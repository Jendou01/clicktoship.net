<?php

namespace App\Modules\MerchantDashboard\Application;

use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Shipping\Application\ShippingAccess;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Permission-filtered read composition only. No state machine, cache or financial writes. */
final class Dashboard
{
    private function window($query, array $p, string $column = 'created_at')
    {
        return $query->where($column, '>=', $p['start'])->where($column, '<', $p['end_exclusive']);
    }

    public function response(Request $r, MerchantContext $c, bool $activityOnly = false): mixed
    {
        $p = Period::read($r);
        $r->validate(['cursor' => 'sometimes|string|max:512', 'kind' => 'sometimes|in:all,shipping,bulk,pricing,customs', 'search' => 'sometimes|nullable|uuid']);
        $data = DB::transaction(function () use ($r, $c, $p, $activityOnly) {
            // Same merchant -> user lock order as membership/security mutations. Authority
            // stays current throughout these bounded reads; no shared stale cache exists.
            app(ShippingAccess::class)->lock($r, $c, 'merchant.view');
            DB::statement("SET LOCAL statement_timeout = '5000ms'");
            $permissions = array_values(array_unique($c->permissions()));
            $merchant = $c->id();
            $activity = $this->activity($r, $merchant, $permissions, $p);
            if ($activityOnly) {
                return ['period' => $p] + $activity;
            }
            $widgets = [];
            if (in_array('shipments.read', $permissions, true)) {
                $q = $this->window(DB::table('shipments')->where('merchant_id', $merchant), $p);
                $states = (clone $q)->selectRaw('state, count(*)::int AS count')->groupBy('state')->orderBy('state')->get();
                $daily = (clone $q)->selectRaw("(created_at AT TIME ZONE 'UTC')::date AS day, count(*)::int AS count")->groupByRaw("(created_at AT TIME ZONE 'UTC')::date")->pluck('count', 'day');
                $series = [];
                for ($day = CarbonImmutable::parse($p['from'], 'UTC'); $day->toDateString() <= $p['to']; $day = $day->addDay()) {
                    $series[] = ['day' => $day->toDateString(), 'count' => (int) ($daily[$day->toDateString()] ?? 0)];
                }
                $exceptions = (clone $q)->where(fn ($x) => $x->whereNotNull('exception_code')->orWhereIn('state', ['unknown', 'blocked'])->orWhereIn('financial_state', ['capture_pending', 'release_pending', 'review']))->count();
                $submitted = $this->window(DB::table('shipment_submissions')->where('merchant_id', $merchant), $p)->count();
                $accepted = $this->window(DB::table('shipments as s')->join('shipping_observations as o', fn ($j) => $j->on('o.id', '=', 's.carrier_observation_id')->on('o.merchant_id', '=', 's.merchant_id'))->where('s.merchant_id', $merchant)->where('s.state', 'accepted')->where('o.outcome', 'accepted')->where('o.provenance', 'verified'), $p, 'o.created_at')->count();
                $widgets['shipping'] = ['total' => $states->sum('count'), 'drafts' => $states->where('state', 'draft')->sum('count'), 'submitted' => $submitted, 'carrier_accepted' => $accepted, 'exceptions' => $exceptions, 'states' => $states, 'daily' => $series];
            }
            if (in_array('tracking.read', $permissions, true)) {
                $rows = $this->window(DB::table('tracking_bindings as b')->join('carrier_tracking_projections as p', fn ($j) => $j->on('p.merchant_id', '=', 'b.merchant_id')->on('p.account_id', '=', 'b.account_id')->on('p.operation_id', '=', 'b.operation_id'))->join('tracking_event_details as d', fn ($j) => $j->on('d.event_id', '=', 'p.event_id')->on('d.binding_id', '=', 'b.id')->on('d.merchant_id', '=', 'b.merchant_id'))->where('b.merchant_id', $merchant)->where('b.provenance', 'verified')->where('d.provenance', 'verified')->where('d.validation', 'verified')->where('p.status', '<>', 'unknown'), $p, 'p.occurred_at')->selectRaw('p.status, count(*)::int AS count')->groupBy('p.status')->orderBy('p.status')->get();
                $widgets['tracking'] = ['verified_statuses' => $rows, 'external_enabled' => false];
            }
            if (in_array('wallet.read', $permissions, true)) {
                $rows = DB::table('wallets as w')->join('wallet_balances as b', 'b.wallet_id', '=', 'w.id')->join('financial_currencies as c', 'c.code', '=', 'w.currency')->where('w.merchant_id', $merchant)->orderBy('w.currency')->limit(100)->get(['w.currency', 'c.exponent', 'b.posted', 'b.reserved', 'b.frozen']);
                $widgets['wallet'] = ['balances' => $rows->map(fn ($w) => ['currency' => $w->currency, 'exponent' => $w->exponent, 'posted' => (string) $w->posted, 'reserved' => (string) $w->reserved, 'available' => bcsub((string) $w->posted, (string) $w->reserved, 0), 'frozen' => $w->frozen]), 'as_of' => now()->toIso8601String(), 'funding_enabled' => false];
            }
            if (in_array('bulk.read', $permissions, true)) {
                $batches = $this->window(DB::table('bulk_batches as b')->where('b.merchant_id', $merchant), $p, 'b.created_at')->orderByDesc('b.created_at')->orderByDesc('b.id')->limit(6)->select(['b.id', 'b.state', 'b.row_count', 'b.created_at'])->selectSub(DB::table('bulk_drafts as d')->whereColumn('d.batch_id', 'b.id')->whereColumn('d.merchant_id', 'b.merchant_id')->selectRaw('count(*)::int'), 'prepared_rows')->selectSub(DB::table('bulk_work as w')->whereColumn('w.batch_id', 'b.id')->whereColumn('w.merchant_id', 'b.merchant_id')->whereNull('w.completed_at')->select('phase')->limit(1), 'active_phase')->selectSub(DB::table('bulk_rows as r')->whereColumn('r.batch_id', 'b.id')->whereColumn('r.merchant_id', 'b.merchant_id')->where('r.source_row', '<=', function ($q) {
                    $q->select('cursor')->from('bulk_work as w')->whereColumn('w.batch_id', 'b.id')->whereColumn('w.merchant_id', 'b.merchant_id')->whereNull('w.completed_at')->limit(1);
                })->selectRaw('count(*)::int'), 'phase_processed_rows')->get();
                foreach ($batches as $batch) {
                    // Count actual source rows through the active phase cursor; never label a
                    // spreadsheet row position as a processed row count.
                    $batch->total_rows = $batch->state === 'parsing' ? null : $batch->row_count;
                    $batch->phase = $batch->active_phase ?? $batch->state;
                    $batch->processed_rows = $batch->active_phase ? $batch->phase_processed_rows : $batch->total_rows;
                    unset($batch->active_phase, $batch->phase_processed_rows);
                }
                $groups = $this->window(DB::table('bulk_batches')->where('merchant_id', $merchant), $p)->selectRaw('state, count(*)::int AS count')->groupBy('state')->orderBy('state')->get();
                $widgets['bulk'] = ['states' => $groups, 'batches' => $batches];
            }
            if (in_array('rates.read', $permissions, true)) {
                $quotes = $this->window(DB::table('shipping_quotes')->where('merchant_id', $merchant), $p)->selectRaw('count(*)::int AS total, count(*) FILTER (WHERE expires_at > ?)::int AS unexpired', [now()])->first();
                $widgets['pricing'] = ['quotes' => $quotes->total, 'unexpired' => $quotes->unexpired, 'availability' => 'requires_route_inputs', 'live_rates_enabled' => false];
            }
            if (in_array('customs.read', $permissions, true)) {
                $q = $this->window(DB::table('customs_declarations as d')->join('shipments as s', fn ($j) => $j->on('s.id', '=', 'd.shipment_id')->on('s.merchant_id', '=', 'd.merchant_id'))->where('d.merchant_id', $merchant), $p, 'd.created_at');
                $q->leftJoin(DB::raw('LATERAL (SELECT r.report FROM customs_readiness r WHERE r.merchant_id=d.merchant_id AND r.shipment_id=d.shipment_id AND r.revision=s.revision ORDER BY r.created_at DESC,r.id DESC LIMIT 1) AS r'), DB::raw('true'), '=', DB::raw('true'));
                $summary = $q->selectRaw("count(*)::int AS declarations, count(*) FILTER(WHERE (r.report->>'local_valid')::boolean=true)::int AS locally_complete, count(*) FILTER(WHERE r.report IS NULL)::int AS not_assessed")->first();
                $widgets['customs'] = (array) $summary + ['clearance_enabled' => false];
            }

            return ['merchant' => DB::table('merchants')->where('id', $merchant)->first(['id', 'name']), 'permissions' => $permissions, 'period' => $p, 'widgets' => (object) $widgets, 'generated_at' => now()->toIso8601String(), 'live_shipping_enabled' => false, 'payments_enabled' => false] + $activity;
        });

        return ApiResponse::success($data)->header('Cache-Control', 'private, no-store')->header('Vary', 'Cookie, Authorization, X-Merchant-ID');
    }

    private function activity(Request $r, string $merchant, array $permissions, array $p): array
    {
        $sources = ['shipping' => ['shipments.read', 'shipment_events', 'shipment_id', 'kind'], 'bulk' => ['bulk.read', 'bulk_batches', 'id', 'state'], 'pricing' => ['rates.read', 'shipping_quotes', 'id', null], 'customs' => ['customs.read', 'customs_events', 'shipment_id', 'kind']];
        $kind = $r->input('kind', 'all');
        $queries = [];
        foreach ($sources as $source => [$permission,$table,$subject,$event]) {
            if (! in_array($permission, $permissions, true) || ($kind !== 'all' && $kind !== $source)) {
                continue;
            }
            $q = $this->window(DB::table($table)->where('merchant_id', $merchant), $p)->selectRaw("id, $subject AS subject_id, created_at, '$source' AS source, ".($event ? "$event AS event" : "'quote_created'::text AS event"));
            if ($r->filled('search')) {
                $q->where($subject, $r->input('search'));
            }
            $queries[] = $q;
        }
        if (! $queries) {
            return ['activity' => [], 'next_cursor' => null];
        }
        $union = array_shift($queries);
        foreach ($queries as $q) {
            $union->unionAll($q);
        }
        $q = DB::query()->fromSub($union, 'activity');
        if ($r->filled('cursor')) {
            try {
                $cursor = json_decode(base64_decode($r->input('cursor'), true), true, 8, JSON_THROW_ON_ERROR);
                if (! is_array($cursor) || ! array_is_list($cursor) || count($cursor) !== 3 || ! is_string($cursor[0]) || ! is_string($cursor[1]) || ! Str::isUuid($cursor[1]) || ! isset($sources[$cursor[2]])) {
                    throw new \RuntimeException;
                }
                $at = CarbonImmutable::parse($cursor[0]);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['cursor' => 'Invalid activity cursor.']);
            }
            $q->whereRaw('(created_at,id,source) < (?::timestamptz,?::uuid,?::text)', [$at->toIso8601String(), $cursor[1], $cursor[2]]);
        }
        $rows = $q->orderByDesc('created_at')->orderByDesc('id')->orderByDesc('source')->limit(21)->get();
        $more = $rows->count() > 20;
        $rows = $rows->take(20)->values();
        $last = $rows->last();

        return ['activity' => $rows, 'next_cursor' => $more ? base64_encode(json_encode([$last->created_at, $last->id, $last->source])) : null];
    }
}
