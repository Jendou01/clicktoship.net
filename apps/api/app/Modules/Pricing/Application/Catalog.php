<?php

namespace App\Modules\Pricing\Application;

use App\Models\User;
use App\Modules\Identity\Application\Sessions;
use App\Modules\Operations\Application\Administration;
use App\Modules\Operations\Application\PlatformSecurity;
use App\Modules\Pricing\Domain\Configuration;
use Illuminate\Http\Request;
use Illuminate\Session\NullSessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Catalog
{
    private function transaction(\Closure $work): mixed
    {
        try {
            return DB::transaction($work);
        } catch (\PDOException $e) {
            // Catch inside the application boundary, before Laravel's routing pipeline renders/logs SQL bindings.
            abort(in_array((string) $e->getCode(), ['23505', '23514', '23503', '23P01', '40001', '40P01'], true) ? 409 : 503);
        }
    }

    public static function lock(bool $shared = false): void
    {
        DB::select($shared ? 'SELECT pg_advisory_xact_lock_shared(5052026)' : 'SELECT pg_advisory_xact_lock(5052026)');
    }

    public function authorize(Request $r, string $permission, ?string $merchant): void
    {
        $user = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
        abort_if($user->suspended_at || ! $user->email_verified_at, 403);
        app(Sessions::class)->validate($r, $user);
        PlatformSecurity::require($r, $permission);
        if ($merchant) {
            // Same grant/MFA boundary as all other target-merchant operations.
            app(Administration::class)->authorizeTarget($r, $merchant, $permission);
            abort_if(DB::table('merchant_users')->where('user_id', $user->id)->where('merchant_id', $merchant)->exists(), 403);
        }
    }

    public function rows(?string $merchant = null): array
    {
        return DB::table('pricing_definitions as d')->join('pricing_versions as v', function ($j) {
            $j->on('v.definition_id', '=', 'd.id')->on('v.version', '=', 'd.version');
        })
            ->where('d.merchant_id', $merchant)->orderBy('d.kind')->orderBy('d.code')->limit(500)->get(['d.*', 'v.configuration', 'v.effective_from', 'v.effective_to', 'v.currency', 'v.created_by'])->map(function ($r) {
                $r->configuration = json_decode($r->configuration, true);

                return $r;
            })->all();
    }

    public function write(Request $r, ?string $merchant = null, ?string $id = null): array
    {
        $d = $r->validate(['kind' => 'required|in:'.implode(',', Configuration::KINDS), 'code' => 'required|regex:/^[a-zA-Z0-9_-]{1,80}$/', 'version' => 'required|integer|min:0', 'currency' => 'nullable|regex:/^[A-Z]{3}$/', 'effective_from' => 'required|date', 'effective_to' => 'required|date|after:effective_from', 'configuration' => 'required|array', 'merchant_id' => 'prohibited']);
        $c = Configuration::validate($d['kind'], $d['configuration'], $merchant);
        abort_if(in_array($d['kind'], ['tariff', 'rule', 'fee'], true) && empty($d['currency']), 422);
        if (! empty($d['currency'])) {
            abort_unless(DB::table('financial_currencies')->where('code', $d['currency'])->exists(), 422);
        }

        return $this->transaction(function () use ($r, $merchant, $id, $d, $c) {
            PlatformSecurity::lock();
            self::lock();
            $this->authorize($r, 'platform.pricing.manage', $merchant);
            $row = $id ? DB::table('pricing_definitions')->where('id', $id)->where('merchant_id', $merchant)->lockForUpdate()->first() : null;
            abort_if($id && ! $row, 404);
            abort_unless($d['version'] === ($row->version ?? 0), 409);
            abort_if($row && ($row->kind !== $d['kind'] || $row->code !== $d['code']), 422);
            $id = $row->id ?? (string) Str::uuid();
            $version = $d['version'] + 1;
            if (! $row) {
                DB::table('pricing_definitions')->insert(['id' => $id, 'kind' => $d['kind'], 'merchant_id' => $merchant, 'code' => $d['code'], 'version' => $version]);
            } else {
                DB::table('pricing_definitions')->where('id', $id)->update(['version' => $version, 'state_version' => $row->state_version + 1]);
            }
            DB::table('pricing_versions')->insert(['definition_id' => $id, 'version' => $version, 'configuration' => json_encode($c), 'effective_from' => $d['effective_from'], 'effective_to' => $d['effective_to'], 'currency' => $d['currency'] ?? null, 'created_by' => $r->user()->id]);
            $this->event($r, $id, $version, 'draft');

            return ['id' => $id, 'version' => $version, 'state_version' => ($row->state_version ?? 0) + 1];
        });
    }

    public function change(Request $r, string $id, string $action, ?string $merchant = null): array
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'state_version' => 'required|integer|min:1']);

        return $this->transaction(function () use ($r, $id, $action, $merchant, $d) {
            PlatformSecurity::lock();
            self::lock();
            $this->authorize($r, 'platform.pricing.approve', $merchant);
            $row = DB::table('pricing_definitions')->where('id', $id)->where('merchant_id', $merchant)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->version === $d['version'] && $row->state_version === $d['state_version'], 409);
            $v = DB::table('pricing_versions')->where('definition_id', $id)->where('version', $row->version)->first();
            abort_if($v->created_by === $r->user()->id, 403);
            $this->makerStillAuthorized($v->created_by, $merchant, $id, $row->version);
            if ($action === 'publish') {
                abort_if(now()->gte($v->effective_to), 422);
                $this->conflicts($row, $v);
            }
            DB::table('pricing_definitions')->where('id', $id)->update(['state_version' => $row->state_version + 1, 'enabled' => $action === 'publish', 'active_version' => $action === 'publish' ? $row->version : $row->active_version]);
            $this->event($r, $id, $row->version, $action);

            return ['id' => $id, 'version' => $row->version, 'enabled' => $action === 'publish', 'state_version' => $row->state_version + 1];
        });
    }

    private function makerStillAuthorized(string $maker, ?string $merchant, string $id, int $version): void
    {
        $e = DB::table('pricing_events')->where('definition_id', $id)->where('version', $version)->where('action', 'draft')->first();
        $u = User::whereKey($maker)->lockForUpdate()->first();
        $s = DB::table('auth_sessions')->where('id', $e->session_id)->whereNull('revoked_at')->first();
        $m = DB::table('user_mfa_credentials')->where('user_id', $maker)->whereNotNull('confirmed_at')->first();
        abort_unless($u && ! $u->suspended_at && $u->email_verified_at && $s && $m && $s->auth_version === $u->auth_version && $s->mfa_version === $m->version && $s->mfa_verified_at && now()->subMinutes(15)->lt($s->mfa_verified_at) && now()->lt($s->expires_at) && now()->subMinutes(config('session.lifetime'))->lt($s->last_seen_at) && in_array('platform.pricing.manage', PlatformSecurity::permissions($maker), true), 403);
        if ($merchant) {
            abort_if(DB::table('merchant_users')->where('user_id', $maker)->where('merchant_id', $merchant)->exists(), 403);
            $request = Request::create('/', 'GET', ['access_grant' => $e->access_grant]);
            $request->setUserResolver(fn () => $u);
            $session = new Store('pricing-approval', new NullSessionHandler);
            $session->put('auth_session_id', $s->id);
            $request->setLaravelSession($session);
            app(Administration::class)->authorizeTarget($request, $merchant, 'platform.pricing.manage');
        }
    }

    public function active(): array
    {
        return DB::table('pricing_definitions as d')->join('pricing_versions as v', function ($j) {
            $j->on('v.definition_id', '=', 'd.id')->on('v.version', '=', 'd.active_version');
        })
            ->where('d.enabled', true)->where('v.effective_from', '<=', now())->where('v.effective_to', '>', now())->orderBy('d.id')->get(['d.id', 'd.kind', 'd.merchant_id', 'd.code', 'v.version', 'v.configuration', 'v.currency', 'v.effective_from', 'v.effective_to'])->map(fn ($r) => ['id' => $r->id, 'kind' => $r->kind, 'merchant_id' => $r->merchant_id, 'code' => $r->code, 'version' => $r->version, 'currency' => $r->currency, 'effective_from' => $r->effective_from, 'effective_to' => $r->effective_to, 'config' => json_decode($r->configuration, true)])->all();
    }

    private function conflicts(object $row, object $version): void
    {
        $c = json_decode($version->configuration, true);
        $others = DB::table('pricing_definitions as d')->join('pricing_versions as v', function ($j) {
            $j->on('v.definition_id', '=', 'd.id')->on('v.version', '=', 'd.active_version');
        })->where('d.enabled', true)->where('d.kind', $row->kind)->where('d.id', '!=', $row->id)->where('d.merchant_id', $row->merchant_id)->where('v.effective_from', '<', $version->effective_to)->where('v.effective_to', '>', $version->effective_from)->get(['v.configuration']);
        foreach ($others as $o) {
            $b = json_decode($o->configuration, true);
            $conflict = false;
            if ($row->kind === 'assignment') {
                $conflict = true;
            }
            if ($row->kind === 'zone') {
                $conflict = $c['country'] === $b['country'] && (bool) array_intersect($c['cities'], $b['cities']);
            }
            if (in_array($row->kind, ['rule', 'tariff', 'fee'], true)) {
                $same = $row->kind !== 'rule' || ($c['scope'] === $b['scope'] && ($c['tier_id'] ?? null) === ($b['tier_id'] ?? null) && Configuration::rank($c) === Configuration::rank($b));
                if ($row->kind === 'fee') {
                    $same = $c['code'] === $b['code'] && $c['side'] === $b['side'];
                }
                $conflict = $same && Configuration::overlaps($c, $b);
            }
            abort_if($conflict, 409);
        }
    }

    private function event(Request $r, string $id, int $version, string $action): void
    {
        DB::table('pricing_events')->insert(['id' => (string) Str::uuid(), 'definition_id' => $id, 'version' => $version, 'actor_id' => $r->user()->id, 'session_id' => $r->session()->get('auth_session_id'), 'action' => $action, 'access_grant' => $r->input('access_grant')]);
    }
}
