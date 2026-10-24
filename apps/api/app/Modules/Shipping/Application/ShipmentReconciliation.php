<?php

namespace App\Modules\Shipping\Application;

use App\Models\User;
use App\Modules\Identity\Application\Sessions;
use App\Modules\Operations\Application\Administration;
use App\Modules\Operations\Application\PlatformSecurity;
use App\Modules\WalletBilling\Application\FinancialAdministration;
use App\Modules\WalletBilling\Application\ShipmentSettlement;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ShipmentReconciliation
{
    public function authorize(Request $r, string $merchant, string $permission): void
    {
        app(Sessions::class)->validate($r, User::findOrFail($r->user()->id));
        app(Administration::class)->authorizeTarget($r, $merchant, $permission);
        abort_if(DB::table('merchant_users')->where('merchant_id', $merchant)->where('user_id', $r->user()->id)->exists(), 403);
    }

    public function request(Request $r, string $merchant, string $submission): array
    {
        $d = $r->validate(['observation_id' => 'required|uuid', 'reason' => 'required|string|min:10|max:500']);

        return DB::transaction(function () use ($r, $merchant, $submission, $d) {
            PlatformSecurity::lock();
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
            app(FinancialAdministration::class)->lockAuthorities($r, $merchant);
            $this->authorize($r, $merchant, 'platform.shipments.reconcile');
            $s = app(Submission::class)->row($merchant, $submission);
            $shipment = app(Shipments::class)->row($merchant, $s->shipment_id);
            abort_unless($shipment->state === 'unknown', 409);
            $o = DB::table('shipping_observations')->where('merchant_id', $merchant)->where('submission_id', $submission)->where('id', $d['observation_id'])->whereIn('outcome', ['accepted', 'rejected'])->first();
            abort_unless($o, 404);
            abort_unless(DB::table('shipping_observations')->where('submission_id', $submission)->whereIn('outcome', ['accepted', 'rejected'])->distinct()->count('outcome') === 1, 409);
            $id = (string) Str::uuid();
            DB::table('shipping_reconciliation_requests')->insert(['id' => $id, 'merchant_id' => $merchant, 'submission_id' => $submission, 'observation_id' => $o->id, 'maker_id' => $r->user()->id, 'maker_session' => $r->session()->get('auth_session_id'), 'maker_grant' => $r->input('access_grant'), 'reason' => Crypt::encryptString($d['reason']), 'expires_at' => now()->addMinutes(15)]);
            app(Shipments::class)->event($merchant, $s->shipment_id, 'reconciliation_requested', $r->user()->id, ['request_id' => $id]);

            return ['id' => $id];
        });
    }

    public function approve(Request $r, string $merchant, string $id): array
    {
        return DB::transaction(function () use ($r, $merchant, $id) {
            PlatformSecurity::lock();
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
            app(FinancialAdministration::class)->lockAuthorities($r, $merchant);
            $this->authorize($r, $merchant, 'platform.shipments.reconcile');
            app(Administration::class)->authorizeTarget($r, $merchant, 'platform.finance.reconcile');
            $a = DB::table('shipping_reconciliation_requests')->where('merchant_id', $merchant)->where('id', $id)->first();
            abort_unless($a, 404);
            abort_unless($a->maker_id !== $r->user()->id, 403);
            abort_unless(now()->lt($a->expires_at), 409);
            // The maker's exact session, MFA and grant must remain current at execution.
            $maker = Request::create('/api/v1/admin/shipments', 'POST', ['access_grant' => $a->maker_grant]);
            $maker->setUserResolver(fn () => User::findOrFail($a->maker_id));
            $store = new Store('reconciliation', new ArraySessionHandler(120));
            $store->put('auth_session_id', $a->maker_session);
            $maker->setLaravelSession($store);
            $u = $maker->user();
            abort_unless(! $u->suspended_at && $u->hasVerifiedEmail() && DB::table('auth_sessions')->where('id', $a->maker_session)->where('user_id', $u->id)->where('auth_version', $u->auth_version)->whereNull('revoked_at')->where('expires_at', '>', now())->where('last_seen_at', '>', now()->subMinutes(config('session.lifetime')))->exists(), 403);
            app(Administration::class)->authorizeTarget($maker, $merchant, 'platform.shipments.reconcile');
            abort_unless(app()->environment('testing') && in_array(DB::connection()->getDatabaseName(), ['clicktoship_test', 'clicktoship_e2e']), 503);
            DB::table('shipping_reconciliation_approvals')->insertOrIgnore(['request_id' => $id, 'checker_id' => $r->user()->id, 'checker_session' => $r->session()->get('auth_session_id'), 'checker_grant' => $r->input('access_grant')]);
            $approved = DB::table('shipping_reconciliation_approvals')->where('request_id', $id)->first();
            abort_unless($approved->checker_id === $r->user()->id && $approved->checker_session === $r->session()->get('auth_session_id'), 403);
            $s = app(Submission::class)->row($merchant, $a->submission_id);
            $o = DB::table('shipping_observations')->where('merchant_id', $merchant)->where('id', $a->observation_id)->first();
            abort_unless(DB::table('shipping_observations')->where('submission_id', $s->id)->whereIn('outcome', ['accepted', 'rejected'])->distinct()->count('outcome') === 1, 409);
            app(ShipmentSettlement::class)->finalize($s, $o, $id);
            $state = $o->outcome === 'accepted' ? 'captured' : 'released';
            DB::table('shipments')->where('id', $s->shipment_id)->update(['financial_state' => $state, 'updated_at' => now()]);
            app(Shipments::class)->event($merchant, $s->shipment_id, 'reconciliation_approved', $r->user()->id, ['request_id' => $id, 'effective_outcome' => $o->outcome]);

            return ['effective_outcome' => $o->outcome, 'financial_state' => $state];
        });
    }
}
