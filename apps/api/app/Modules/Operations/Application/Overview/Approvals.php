<?php

namespace App\Modules\Operations\Application\Overview;

use Illuminate\Support\Facades\DB;

final class Approvals
{
    public function carrierPending()
    {
        $last = DB::table('carrier_config_events')->selectRaw('DISTINCT ON(account_id) account_id,version,action')->orderBy('account_id')->orderByDesc('created_at')->orderByDesc('id');

        return DB::table('carrier_accounts as a')->joinSub($last, 'e', fn ($j) => $j->on('e.account_id', '=', 'a.id')->on('e.version', '=', 'a.version'))->where('e.action', 'draft');
    }

    public function countryPending()
    {
        return DB::table('customs_country_rules as c')->join('customs_rule_versions as v', fn ($j) => $j->on('v.country', '=', 'c.country')->on('v.version', '=', 'c.version'))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('customs_rule_reviews as r')->whereColumn('r.country', 'v.country')->whereColumn('r.version', 'v.version'));
    }

    public function read(array $permissions, string $actor, string $session): array
    {
        $out = [];
        if (in_array('platform.access.request', $permissions, true)) {
            $q = DB::table('platform_access_grants')->whereNull('revoked_at')->whereNull('approved_by')->where('expires_at', '>', now());
            if (! in_array('platform.access.approve', $permissions, true)) {
                $q->where('actor_id', $actor)->where('session_id', $session);
            }
            $out[] = ['kind' => 'access', 'count' => $q->count(), 'route' => 'admin-access'];
        }
        if (in_array('platform.metrics.merchants', $permissions, true)) {
            $out[] = ['kind' => 'verification', 'count' => DB::table('merchants')->where('verification_status', 'pending')->count(), 'route' => in_array('platform.directory', $permissions, true) ? 'admin-merchants' : null];
        }
        if (in_array('platform.metrics.pricing', $permissions, true)) {
            $out[] = ['kind' => 'pricing', 'count' => DB::table('pricing_definitions')->whereNull('merchant_id')->where(fn ($q) => $q->whereNull('active_version')->orWhereColumn('version', '<>', 'active_version'))->count(), 'route' => in_array('platform.pricing.view', $permissions, true) ? 'admin-pricing' : null];
        }
        if (in_array('platform.metrics.carriers', $permissions, true)) {
            $out[] = ['kind' => 'carriers', 'count' => $this->carrierPending()->count(), 'route' => in_array('platform.carriers.view', $permissions, true) ? 'carriers' : null];
        }
        if (in_array('platform.metrics.customs', $permissions, true)) {
            $out[] = ['kind' => 'customs', 'count' => $this->countryPending()->count(), 'route' => in_array('platform.customs.view', $permissions, true) ? 'admin-customs' : null];
        }
        if (in_array('platform.metrics.finance', $permissions, true)) {
            $out[] = ['kind' => 'finance', 'count' => DB::table('financial_approvals')->where('maker_id', $actor)->where('maker_session', $session)->whereNull('revoked_at')->whereNull('executed_operation')->where('expires_at', '>', now())->count(), 'route' => in_array('platform.wallet.view', $permissions, true) ? 'admin-finance' : null];
        }

        return ['approvals' => $out, 'scope' => 'aggregate_metadata_or_current_session_own_requests', 'approval_controls' => 'existing_independent_domain_workflows', 'generated_at' => now()->toIso8601String()];
    }
}
