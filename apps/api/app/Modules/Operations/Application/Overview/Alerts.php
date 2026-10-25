<?php

namespace App\Modules\Operations\Application\Overview;

use App\Modules\Identity\Application\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Derived fact references, not another notification/event store. No tenant IDs escape. */
final class Alerts
{
    public function read(array $permissions, string $actor): array
    {
        $specs = [
            'verification' => ['platform.metrics.merchants', DB::table('merchants')->where('verification_status', 'pending'), 'id', 'version', 'created_at', 'admin-merchants', 'platform.directory'],
            'pricing' => ['platform.metrics.pricing', DB::table('pricing_definitions')->whereNull('merchant_id')->where(fn ($q) => $q->whereNull('active_version')->orWhereColumn('version', '<>', 'active_version')), 'id', 'state_version', 'created_at', 'admin-pricing', 'platform.pricing.view'],
            'carrier_disabled' => ['platform.metrics.carriers', DB::table('carrier_accounts')->whereNull('active_version'), 'id', 'state_version', 'created_at', 'carriers', 'platform.carriers.view'],
            'unknown_shipment' => ['platform.metrics.shipping', DB::table('shipments')->where('state', 'unknown'), 'id', 'version', 'updated_at', 'admin-shipping', 'platform.shipments.view'],
            'tracking_failure' => ['platform.metrics.tracking', DB::table('tracking_work')->whereIn('state', ['dead_letter', 'quarantined']), 'id', 'attempts', 'updated_at', 'admin-tracking', 'platform.tracking.health'],
            'bulk_recovery' => ['platform.metrics.bulk', DB::table('bulk_work')->whereNull('completed_at')->where(fn ($q) => $q->where('lease_until', '<', now())->orWhere(fn ($q) => $q->whereNull('lease_until')->where('created_at', '<', now()->subMinutes(5)))), 'id', 'cursor', 'created_at', 'platform-bulk', 'platform.metrics.bulk'],
            'customs_quarantine' => ['platform.metrics.customs', app(Facts::class)->currentCustomsQuarantine(), 'r.document_id', null, 'r.created_at', 'admin-customs', 'platform.customs.view'],
        ];
        $rows = [];
        foreach ($specs as $kind => [$permission,$query,$id,$version,$at,$route,$routePermission]) {
            if (! in_array($permission, $permissions, true)) {
                continue;
            }
            $values = $query->orderByDesc($at)->orderByDesc($id)->limit(26)->get([$id.' AS fact_id', $at.' AS at', ...($version ? [$version.' AS fact_version'] : [])]);
            foreach ($values as $v) {
                $rows[] = ['id' => hash_hmac('sha256', $kind.'|'.$v->fact_id.'|'.($v->fact_version ?? '').'|'.$v->at, config('app.key')), 'kind' => $kind, 'occurred_at' => $v->at, 'route' => in_array($routePermission, $permissions, true) ? $route : null, 'private_details_require_grant' => in_array($kind, ['unknown_shipment', 'tracking_failure', 'customs_quarantine'], true)];
            }
        }
        if (in_array('platform.metrics.finance', $permissions, true)) {
            $latest = DB::table('financial_reconciliation_runs')->selectRaw('DISTINCT ON(wallet_id) id,wallet_id,matches,created_at')->orderBy('wallet_id')->orderByDesc('created_at')->orderByDesc('id');
            foreach (DB::query()->fromSub($latest, 'r')->where('matches', false)->orderByDesc('created_at')->limit(26)->get(['id', 'created_at']) as $row) {
                $rows[] = ['id' => hash_hmac('sha256', 'finance|'.$row->id, config('app.key')), 'kind' => 'finance_reconciliation', 'occurred_at' => $row->created_at, 'route' => in_array('platform.wallet.view', $permissions, true) ? 'admin-finance' : null, 'private_details_require_grant' => true];
            }
        }
        if (in_array('platform.metrics.customs', $permissions, true)) {
            foreach (app(Approvals::class)->countryPending()->orderByDesc('v.created_at')->orderBy('v.country')->limit(26)->get(['v.country', 'v.version', 'v.created_at']) as $row) {
                $rows[] = ['id' => hash_hmac('sha256', 'customs_rule|'.$row->country.'|'.$row->version, config('app.key')), 'kind' => 'customs_rule', 'occurred_at' => $row->created_at, 'route' => in_array('platform.customs.view', $permissions, true) ? 'admin-customs' : null, 'private_details_require_grant' => false];
            }
        }
        usort($rows, fn ($a, $b) => [$b['occurred_at'], $b['id']] <=> [$a['occurred_at'], $a['id']]);
        $limited = count($rows) > 25;
        $rows = array_slice($rows, 0, 25);
        $read = DB::table('platform_alert_receipts')->where('actor_id', $actor)->whereIn('alert_key', array_column($rows, 'id'))->pluck('alert_key')->all();
        foreach ($rows as &$row) {
            $row['read'] = in_array($row['id'], $read, true);
        }

        return ['alerts' => $rows, 'sample_limited' => $limited, 'sample_limit' => 25, 'generated_at' => now()->toIso8601String(), 'notifications_enabled' => false];
    }

    public function acknowledge(Request $r, array $permissions, string $key): array
    {
        $current = $this->read($permissions, $r->user()->id);
        abort_unless(collect($current['alerts'])->contains('id', $key), 404);
        $inserted = DB::table('platform_alert_receipts')->insertOrIgnore(['actor_id' => $r->user()->id, 'alert_key' => $key, 'session_id' => $r->session()->get('auth_session_id')]);
        if ($inserted) {
            Audit::record('platform.alert_read', $r->user()->id, metadata: ['alert_key' => $key]);
        }

        return ['read' => true, 'resolved' => false];
    }
}
