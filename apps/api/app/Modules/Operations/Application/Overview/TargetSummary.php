<?php

namespace App\Modules\Operations\Application\Overview;

use App\Models\User;
use App\Modules\Operations\Application\Administration;
use App\Modules\Pricing\Application\Catalog;
use App\Modules\WalletBilling\Application\FinancialAdministration;
use App\Modules\WalletBilling\Application\WalletRead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TargetSummary
{
    public function read(Request $r, string $merchant, array $period): array
    {
        $d = $r->validate(['section' => 'required|in:merchant,shipping,finance,pricing', 'access_grant' => 'required|uuid']);
        // Caller holds the exclusive platform security key. Lock the independent
        // approver's current authority before reusing the existing grant service.
        $g = DB::table('platform_access_grants')->where('id', $d['access_grant'])->where('merchant_id', $merchant)->where('actor_id', $r->user()->id)->where('session_id', $r->session()->get('auth_session_id'))->whereNotNull('approved_by')->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        abort_unless($g, 403);
        User::whereKey($g->approved_by)->lockForUpdate()->firstOrFail();
        DB::table('auth_sessions')->where('id', $g->approval_session_id)->lockForUpdate()->firstOrFail();
        $cap = ['merchant' => 'platform.merchant.view', 'shipping' => 'platform.shipments.view', 'finance' => 'platform.wallet.view', 'pricing' => 'platform.pricing.view'][$d['section']];
        if ($d['section'] === 'finance') {
            app(FinancialAdministration::class)->authorize($r, $merchant, $cap);

            return ['section' => 'finance'] + app(WalletRead::class)->wallets($merchant);
        }
        if ($d['section'] === 'pricing') {
            app(Catalog::class)->authorize($r, $cap, $merchant);

            return ['section' => 'pricing', 'kinds' => app(Metrics::class)->groups(DB::table('pricing_definitions')->where('merchant_id', $merchant), 'kind'), 'assignments' => DB::table('pricing_definitions as d')->join('pricing_versions as v', fn ($j) => $j->on('v.definition_id', '=', 'd.id')->on('v.version', '=', 'd.active_version'))->where('d.merchant_id', $merchant)->where('d.kind', 'assignment')->where('d.enabled', true)->limit(20)->get(['d.id', 'd.active_version', DB::raw("v.configuration->>'tier_id' AS tier_id")])];
        }
        if ($d['section'] === 'shipping') {
            app(Administration::class)->authorizeTarget($r, $merchant, $cap);

            return ['section' => 'shipping', 'period' => $period, 'shipping' => app(Metrics::class)->shipping($period, $merchant)];
        }
        $base = app(Administration::class)->merchant($r, $merchant);
        $roles = DB::table('merchant_users as m')->join('merchant_role_assignments as a', fn ($j) => $j->on('a.membership_id', '=', 'm.id')->on('a.merchant_id', '=', 'm.merchant_id'))->join('roles as r', 'r.id', '=', 'a.role_id')->where('m.merchant_id', $merchant)->selectRaw('r.code AS role,m.status,count(*)::int AS count')->groupBy('r.code', 'm.status')->orderBy('r.code')->orderBy('m.status')->limit(24)->get();

        return ['section' => 'merchant', 'memberships' => $roles, 'settings' => DB::table('merchant_settings')->where('merchant_id', $merchant)->first(['locale', 'timezone', 'version'])] + $base;
    }
}
