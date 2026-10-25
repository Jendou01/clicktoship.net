<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Operations\Application\Overview\Approvals;
use App\Modules\Operations\Application\Overview\Metrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class Overview
{
    public const SECTIONS = ['merchants', 'users', 'shipping', 'bulk', 'tracking', 'carriers', 'pricing', 'finance', 'customs', 'commerce'];

    public function platform(array $permissions, array $filters, ?string $section = null, ?string $actor = null, ?string $session = null): array
    {
        if ($section !== null) {
            abort_unless(in_array($section, self::SECTIONS, true), 422);
        }
        $out = app(Metrics::class)->overview($permissions, $filters['period'], $section);
        $widgets = (array) $out['widgets'];
        if (isset($widgets['merchants'])) {
            $metrics = app(Metrics::class);
            $actions = DB::table('audit_logs')->where('action', 'merchant.status_changed')->whereNotNull('merchant_id');
            $widgets['merchants']['activation_trend'] = $metrics->trend((clone $actions)->whereRaw("metadata->>'to'='active'"), $filters['period']);
            $widgets['merchants']['status_changes'] = $metrics->window(clone $actions, $filters['period'])->selectRaw("metadata->>'to' AS status,count(*)::int AS count")->groupByRaw("metadata->>'to'")->orderBy('status')->get()->all();
            $widgets['merchants']['verification_outcomes'] = $metrics->groups($metrics->window(DB::table('merchant_verification_decisions'), $filters['period']), 'outcome');
        }
        $out['widgets'] = $this->buckets((object) $widgets, $filters['grain']);
        // All grouped trends preserve their source counts and UTC event windows.
        $out['definitions'] = 'docs/analytics/METRIC_DICTIONARY.md';
        $out['financial_amounts'] = 'unavailable_global_disclosure_policy';
        $out['cost_margin_revenue'] = 'unavailable_verified_accounting_and_cost_evidence';
        $out['insights'] = $this->insights((array) $out['widgets']);
        if (! $section && $actor && $session) {
            $out['approval_workload'] = app(Approvals::class)->read($permissions, $actor, $session);
        }

        return $out;
    }

    public function merchant(string $m, array $permissions, array $f): array
    {
        $widgets = [];
        foreach (['shipment_summary', 'tracking_exceptions', 'bulk_summary', 'quote_summary', 'customs_readiness', 'draft_conversion', 'wallet_transactions'] as $report) {
            if (in_array(Definitions::get($report)[0], $permissions, true)) {
                $value = app(Reports::class)->read($report, $m, $f);
                unset($value['rows'],$value['next_after']);
                $widgets[$report] = $value;
            }
        }
        if (in_array('shipments.read', $permissions, true)) {
            $widgets['shipping_evidence'] = app(Metrics::class)->shipping($f['period'], $m);
        }
        if (in_array('tracking.read', $permissions, true)) {
            $widgets['verified_tracking'] = app(Metrics::class)->tracking($f['period'], $m);
        }
        if (in_array('wallet.read', $permissions, true)) {
            $rows = DB::table('wallets as w')->join('wallet_balances as b', 'b.wallet_id', '=', 'w.id')->join('financial_currencies as c', 'c.code', '=', 'w.currency')->where('w.merchant_id', $m)->orderBy('w.currency')->limit(100)->get(['w.currency', 'c.exponent', 'b.posted', 'b.reserved']);
            foreach ($rows as $w) {
                $w->posted = (string) $w->posted;
                $w->reserved = (string) $w->reserved;
                $w->available = bcsub($w->posted, $w->reserved, 0);
            }
            $widgets['current_balances'] = ['rows' => $rows, 'as_of' => now()->toIso8601String(), 'historical_balance' => 'unavailable'];
        }

        return ['widgets' => $this->buckets((object) $widgets, $f['grain']), 'filters' => $f, 'permissions' => $permissions, 'generated_at' => now()->toIso8601String(), 'insights' => $this->insights($widgets), 'live_execution_enabled' => false, 'unavailable' => ['realized_revenue', 'profit_loss', 'verified_carrier_cost', 'historical_balances', 'implicit_fx', 'duplicate_suppression_total', 'shipment_country_service_projection']];
    }

    private function buckets(mixed $value, string $grain): mixed
    {
        if ($grain === 'day' || (! is_array($value) && ! is_object($value))) {
            return $value;
        }
        if ($value instanceof Collection) {
            $value = $value->all();
        }
        $rows = (array) $value;
        if ($rows && array_is_list($rows) && count(array_filter($rows, fn ($r) => (is_array($r) || is_object($r)) && isset(((array) $r)['day'], ((array) $r)['count']))) === count($rows)) {
            $sums = [];
            foreach ($rows as $row) {
                $row = (array) $row;
                $day = CarbonImmutable::parse($row['day'], 'UTC');
                $bucket = ($grain === 'week' ? $day->startOfWeek() : $day->startOfMonth())->toDateString();
                $sums[$bucket] = ($sums[$bucket] ?? 0) + $row['count'];
            }
            ksort($sums);

            return array_map(fn ($day, $count) => ['day' => $day, 'count' => $count], array_keys($sums), array_values($sums));
        }
        $out = [];
        foreach ($rows as $key => $row) {
            $out[$key] = $this->buckets($row, $grain);
        }

        return is_object($value) ? (object) $out : $out;
    }

    private function insights(array $widgets): array
    {
        $out = [];
        $candidates = [
            ['tracking_exceptions', 'total', 'recorded_tracking_exceptions', 'event_period'],
            ['shipping_evidence', 'unknown_outcomes', 'unknown_outcomes', 'current_stock'],
            ['shipping', 'open_exceptions', 'open_exceptions', 'current_stock'],
            ['tracking', 'cases', 'tracking_reconciliation', 'current_stock'],
            ['bulk', 'failed', 'failed_bulk_batches', 'current_stock'],
            ['customs', 'local_incomplete', 'customs_incomplete', 'current_stock'],
            ['finance', 'reconciliation_cases', 'financial_reconciliation', 'current_stock'],
            ['commerce', 'reauthorization_required', 'integration_reauthorization', 'current_stock'],
        ];
        foreach ($candidates as [$source,$field,$metric,$period]) {
            if (isset($widgets[$source][$field]) && $widgets[$source][$field] >= 1) {
                $out[] = ['source' => $source, 'metric' => $metric, 'value' => $widgets[$source][$field], 'threshold' => 1, 'period_kind' => $period, 'comparison' => 'not_a_growth_claim', 'action_enabled' => false];
            }
        }
        foreach (['bulk_summary' => ['failed'], 'customs_readiness' => ['false', 'not_assessed']] as $source => $states) {
            if (isset($widgets[$source]['distribution'])) {
                $n = 0;
                foreach ($widgets[$source]['distribution'] as $row) {
                    if (in_array((string) $row->status, $states, true)) {
                        $n += $row->count;
                    }
                }
                if ($n) {
                    $out[] = ['source' => $source, 'metric' => $source === 'bulk_summary' ? 'failed_bulk_batches' : 'customs_incomplete', 'value' => $n, 'threshold' => 1, 'period_kind' => 'creation_cohort_current_state', 'comparison' => 'not_a_growth_claim', 'action_enabled' => false];
                }
            }
        }

        return $out;
    }
}
