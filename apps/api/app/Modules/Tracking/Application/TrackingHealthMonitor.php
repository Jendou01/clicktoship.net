<?php

namespace App\Modules\Tracking\Application;

use Illuminate\Support\Facades\DB;

final class TrackingHealthMonitor
{
    public function global(): array
    {
        return ['external_enabled' => false, 'notifications_enabled' => false, 'reason' => 'CONTRACT_UNVERIFIED', 'work' => DB::table('tracking_work')->selectRaw('state,count(*) AS count')->groupBy('state')->get(), 'identity_conflicts' => DB::table('audit_logs')->where('action', 'tracking.reference_collision')->count(), 'cases' => DB::table('tracking_cases')->count(), 'unknown_events' => DB::table('tracking_event_details')->where('outcome', 'unknown')->count(), 'stale_projections' => DB::table('carrier_tracking_projections')->where('occurred_at', '<', now()->subHours(24))->count(), 'duplicate_receipts' => DB::table('tracking_receipts')->where('outcome', 'duplicate')->count(), 'replay_conflicts' => DB::table('tracking_receipts')->where('outcome', 'replay_conflict')->count(), 'open_circuits' => DB::table('tracking_account_health')->where('circuit_until', '>', now())->count()];
    }

    public function target(string $merchant): array
    {
        return ['identity_conflicts' => DB::table('audit_logs')->where('merchant_id', $merchant)->where('action', 'tracking.reference_collision')->orderByDesc('created_at')->limit(100)->get(['id', 'subject_id', 'created_at']), 'receipt_counts' => DB::table('tracking_receipts')->where('merchant_id', $merchant)->selectRaw('outcome,count(*) AS count')->groupBy('outcome')->get(), 'deliveries' => DB::table('tracking_work')->where('merchant_id', $merchant)->orderByDesc('created_at')->limit(100)->get(['id', 'binding_id', 'account_id', 'kind', 'state', 'attempts', 'error_code', 'created_at', 'updated_at']),
            'cases' => DB::table('tracking_cases')->where('merchant_id', $merchant)->orderByDesc('created_at')->limit(100)->get(['id', 'binding_id', 'event_id', 'reason', 'created_at']),
            'reviews' => DB::table('tracking_reviews')->where('merchant_id', $merchant)->orderByDesc('created_at')->limit(100)->get(['id', 'case_id', 'created_at']),
            'endpoints' => DB::table('tracking_endpoints')->where('merchant_id', $merchant)->get(['id', 'account_id', 'enabled']),
            'configuration' => ['external_enabled' => false, 'notifications_enabled' => false, 'reason' => 'CONTRACT_UNVERIFIED', 'interval_seconds' => config('tracking.interval_seconds'), 'account_spacing_seconds' => config('tracking.account_spacing_seconds'), 'retention_deletion_enabled' => false]];
    }

    public function retentionCandidates(): int
    {
        return DB::table('tracking_work')->where('kind', 'webhook')->where('created_at', '<', now()->subDays(config('tracking.retention_days')))->count();
    }
}
