<?php

namespace App\Modules\WalletBilling\Application;

use App\Models\User;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Application\Sessions;
use App\Modules\Operations\Application\Administration;
use App\Modules\Operations\Application\PlatformSecurity;
use App\Modules\WalletBilling\Domain\Money;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FinancialAdministration
{
    public function authorize(Request $r, string $merchant, string $capability, bool $validateSession = true): void
    {
        if ($validateSession) {
            app(Sessions::class)->validate($r, User::findOrFail($r->user()->id));
        }
        app(Administration::class)->authorizeTarget($r, $merchant, $capability);
        abort_if(DB::table('merchant_users')->where('merchant_id', $merchant)->where('user_id', $r->user()->id)->exists(), 403);
    }

    public function lockAuthorities(Request $r, string $merchant): void
    {
        // Match Identity's user -> MFA/session mutation order while serializing with admin role/grant changes.
        $grants = DB::table('platform_access_grants')->where('merchant_id', $merchant)->get();
        $users = collect([$r->user()->id]);
        $sessions = collect([$r->session()->get('auth_session_id')]);
        foreach ($grants as $grant) {
            $users->push($grant->actor_id, $grant->approved_by);
            $sessions->push($grant->session_id, $grant->approval_session_id);
        }
        User::whereIn('id', $users->filter()->unique()->sort()->values())->orderBy('id')->lockForUpdate()->get();
        DB::table('auth_sessions')->whereIn('id', $sessions->filter()->unique()->sort()->values())->orderBy('id')->lockForUpdate()->get();
    }

    public function request(Request $r, string $merchant): array
    {
        $d = $r->validate(['wallet_id' => 'required|uuid', 'kind' => 'required|in:adjust_credit,adjust_debit,refund,reverse', 'amount' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,12})$/'], 'reference' => 'nullable|uuid', 'business_key' => 'required|string|max:150', 'request_key' => 'required|string|min:16|max:150', 'reason' => 'required|string|min:10|max:500']);
        try {
            new Money($d['amount']);
        } catch (\InvalidArgumentException) {
            abort(422);
        }
        if (in_array($d['kind'], ['refund', 'reverse'], true)) {
            abort_unless($d['reference'] ?? null, 422);
        }

        return DB::transaction(function () use ($r, $merchant, $d) {
            PlatformSecurity::lock();
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
            $this->lockAuthorities($r, $merchant);
            $this->authorize($r, $merchant, 'platform.finance.correct');
            app(WalletRead::class)->wallet($merchant, $d['wallet_id']);
            $payload = array_intersect_key($d, array_flip(['wallet_id', 'kind', 'amount', 'reference', 'business_key']));
            $payload['reason_digest'] = hash('sha256', $d['reason']);
            $payload['access_grant'] = $r->input('access_grant');
            $id = (string) Str::uuid();
            // Deterministic scoped request replay, preserving immutable original payload.
            $digest = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $requestHash = hash('sha256', $merchant.'|'.$r->user()->id.'|'.$d['request_key']);
            $existing = DB::table('financial_approval_events')->where('kind', 'request:'.$requestHash)->first();
            if ($existing) {
                $old = DB::table('financial_approvals')->where('id', $existing->approval_id)->where('merchant_id', $merchant)->where('maker_id', $r->user()->id)->where('maker_session', $r->session()->get('auth_session_id'))->first();
                abort_unless($old && hash_equals($old->digest, $digest), 409);

                return ['id' => $old->id, 'version' => $old->version];
            }
            DB::table('financial_approvals')->insert(['id' => $id, 'merchant_id' => $merchant, 'maker_id' => $r->user()->id, 'maker_session' => $r->session()->get('auth_session_id'), 'payload' => json_encode($payload), 'digest' => $digest, 'reason' => Crypt::encryptString($d['reason']), 'created_at' => now(), 'expires_at' => now()->addMinutes(15)]);
            $this->event($id, $r->user()->id, 'request:'.$requestHash);
            Audit::record('finance.approval_requested', $r->user()->id, $merchant, $id);

            return ['id' => $id, 'version' => 1];
        });
    }

    private function originalRequest(object $a, string $user, string $session, string $grant): Request
    {
        $r = Request::create('/api/v1/admin/finance', 'POST', ['access_grant' => $grant]);
        $r->setUserResolver(fn () => User::findOrFail($user));
        $store = new Store('finance', new ArraySessionHandler(120));
        $store->put('auth_session_id', $session);
        $r->setLaravelSession($store);
        $u = $r->user();
        $registry = DB::table('auth_sessions')->where('id', $session)->where('user_id', $user)->whereNull('revoked_at')->where('expires_at', '>', now())->where('last_seen_at', '>', now()->subMinutes(config('session.lifetime')))->first();
        abort_unless($registry && $registry->auth_version === $u->auth_version && ! $u->suspended_at && $u->hasVerifiedEmail(), 403);
        $this->authorize($r, $a->merchant_id, 'platform.finance.correct', false);

        return $r;
    }

    public function change(Request $r, string $merchant, string $id, string $action): array
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'request_key' => 'required|string|min:16|max:150']);

        return DB::transaction(function () use ($r, $merchant, $id, $action, $d) {
            PlatformSecurity::lock();
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
            $this->lockAuthorities($r, $merchant);
            $this->authorize($r, $merchant, 'platform.finance.correct');
            $a = DB::table('financial_approvals')->where('merchant_id', $merchant)->where('id', $id)->lockForUpdate()->first();
            abort_unless($a, 404);
            $payload = json_decode($a->payload, true, flags: JSON_THROW_ON_ERROR);
            if ($action === 'execute' && $a->executed_operation) {
                abort_unless($a->maker_id === $r->user()->id && $a->maker_session === $r->session()->get('auth_session_id'), 403);

                return ['operation' => json_decode(DB::table('financial_operations')->where('id', $a->executed_operation)->value('result'), true)];
            }
            abort_unless($a->version === $d['version'] && ! $a->revoked_at && now()->lt($a->expires_at), 409);
            if ($action === 'revoke') {
                abort_unless(in_array($r->user()->id, [$a->maker_id, $a->checker_id], true), 403);
                DB::table('financial_approvals')->where('id', $id)->update(['revoked_at' => now(), 'version' => $a->version + 1]);
            } elseif ($action === 'approve') {
                abort_if($a->maker_id === $r->user()->id, 403);
                abort_if($a->approved_at, 409);
                $this->originalRequest($a, $a->maker_id, $a->maker_session, $payload['access_grant']);

                DB::table('financial_approvals')->where('id', $id)->update(['checker_id' => $r->user()->id, 'checker_session' => $r->session()->get('auth_session_id'), 'approved_at' => now(), 'checker_grant' => $r->input('access_grant'), 'version' => $a->version + 1]);
            } else {
                abort_unless(config('finance.corrections_enabled'), 403);
                abort_unless($a->approved_at && $a->maker_id === $r->user()->id && $a->maker_session === $r->session()->get('auth_session_id'), 403);
                $this->originalRequest($a, $a->maker_id, $a->maker_session, $payload['access_grant']);
                $this->originalRequest($a, $a->checker_id, $a->checker_session, $a->checker_grant);
                $result = app(Ledger::class)->command($merchant, $payload['wallet_id'], $payload['kind'], $payload['amount'], $payload['business_key'], $d['request_key'], $a->maker_id, $payload['reference'] ?? null);
                DB::table('financial_approvals')->where('id', $id)->update(['executed_operation' => $result['operation_id'], 'version' => $a->version + 1]);
            }
            $this->event($id, $r->user()->id, $action);
            Audit::record('finance.approval_'.$action, $r->user()->id, $merchant, $id);

            return ['id' => $id, 'version' => $a->version + 1, 'operation' => $result ?? null];
        });
    }

    private function event(string $id, string $actor, string $kind): void
    {
        DB::table('financial_approval_events')->insert(['id' => (string) Str::uuid(), 'approval_id' => $id, 'actor_id' => $actor, 'kind' => $kind]);
    }
}
