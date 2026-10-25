<?php

namespace App\Modules\Analytics\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class Reports
{
    public function query(string $report, string $merchant, array $f)
    {
        [$permission,$table,$state,$columns] = Definitions::get($report);
        $q = DB::table($table.' as t');
        $fields = array_map(fn ($k) => 't.'.$k, $columns);
        if ($report === 'submission_attempts') {
            $q->join('carrier_operations as op', 'op.id', '=', 't.operation_id')->join('shipment_submissions as sub', fn ($j) => $j->on('sub.carrier_operation_id', '=', 'op.id')->on('sub.merchant_id', '=', 'op.merchant_id'))->where('op.merchant_id', $merchant);
        } else {
            $q->where('t.merchant_id', $merchant);
        }
        if (in_array($report, ['wallet_transactions', 'wallet_reservations', 'financial_reconciliation', 'quote_summary'], true)) {
            if ($report === 'wallet_transactions') {
                $q->join('ledger_transactions as l', fn ($j) => $j->on('l.id', '=', 't.id')->on('l.merchant_id', '=', 't.merchant_id'))->join('financial_operations as op', fn ($j) => $j->on('op.id', '=', 'l.operation_id')->on('op.merchant_id', '=', 'l.merchant_id'))->join('wallets as w', fn ($j) => $j->on('w.id', '=', 't.wallet_id')->on('w.merchant_id', '=', 't.merchant_id'));
                $currency = 'w.currency';
                $fields = array_map(fn ($k) => match ($k) {
                    'kind' => 'op.kind','currency' => 'w.currency','exponent' => 'cc.exponent',default => 't.'.$k
                }, $columns);
                $state = 'op.kind';
            } else {
                $currency = 't.currency';
                $fields = array_map(fn ($k) => $k === 'exponent' ? 'cc.exponent' : 't.'.$k, $columns);
            }
            $q->join('financial_currencies as cc', 'cc.code', '=', $currency);
            if (isset($f['currency'])) {
                $q->where($currency, $f['currency']);
            }
        }
        if ($report === 'quote_summary') {
            $fields = ['t.id', 't.currency', 'cc.exponent', DB::raw("t.public_snapshot->>'total_minor' AS total_minor"), DB::raw("t.public_snapshot->>'selling_minor' AS selling_minor"), DB::raw("t.public_snapshot->>'discount_minor' AS discount_minor"), 't.expires_at', DB::raw("t.public_snapshot->>'service_id' AS service_id"), 't.created_at'];
            if (isset($f['service'])) {
                $q->whereRaw("t.public_snapshot->>'service_id' = ?", [$f['service']]);
            }
        }
        if (in_array($report, ['connected_orders', 'draft_conversion'], true)) {
            $q->join('commerce_connections as c', fn ($j) => $j->on('c.id', '=', 't.connection_id')->on('c.merchant_id', '=', 't.merchant_id'))->leftJoin('commerce_draft_links as l', fn ($j) => $j->on('l.order_id', '=', 't.id')->on('l.merchant_id', '=', 't.merchant_id'));
            $fields = ['t.id', 'c.provider', 'c.provenance', 't.state', 't.review_required', 'l.shipment_id', 't.created_at'];
        }
        if ($report === 'commerce_activity') {
            $q->join('commerce_connections as c', fn ($j) => $j->on('c.id', '=', 't.connection_id')->on('c.merchant_id', '=', 't.merchant_id'));
            $fields = ['t.id', 'c.provider', 't.kind', 't.created_at'];
        }
        if (isset($f['provider'])) {
            $q->where($report === 'connection_states' ? 't.provider' : 'c.provider', $f['provider']);
        }
        if ($report === 'customs_readiness') {
            $q->join('shipments as s', fn ($j) => $j->on('s.id', '=', 't.shipment_id')->on('s.merchant_id', '=', 't.merchant_id'))->leftJoin(DB::raw('LATERAL (SELECT report FROM customs_readiness r WHERE r.merchant_id=t.merchant_id AND r.shipment_id=t.shipment_id AND r.revision=s.revision ORDER BY created_at DESC,id DESC LIMIT 1) AS readiness'), DB::raw('true'), '=', DB::raw('true'));
            $fields = ['t.id', DB::raw("(readiness.report->>'local_valid')::boolean AS local_valid"), 't.created_at'];
        }
        if (isset($f['status'])) {
            $q->where(str_contains((string) $state, '.') ? $state : 't.'.$state, $f['status']);
        }

        return $q->where('t.created_at', '>=', $f['period']['start'])->where('t.created_at', '<', $f['period']['end_exclusive'])->select($fields);
    }

    public function read(string $report, string $merchant, array $f, int $limit = 25, bool $summary = true): array
    {
        $q = $this->query($report, $merchant, $f);
        $page = clone $q;
        if (isset($f['after'])) {
            $page->where('t.id', '<', $f['after']);
        }
        $rows = $page->orderByDesc('t.id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();
        foreach ($rows as $row) {
            foreach (['delta', 'posted_after', 'reserved_after', 'sequence', 'amount', 'cutoff', 'total_minor', 'selling_minor', 'discount_minor'] as $key) {
                if (isset($row->$key)) {
                    $row->$key = (string) $row->$key;
                }
            }
        }
        $out = ['report' => $report, 'source' => Definitions::get($report)[1], 'columns' => Definitions::get($report)[3], 'filters' => $f, 'rows' => $rows, 'next_after' => $more ? $rows->last()->id : null, 'generated_at' => now()->toIso8601String(), 'evidence' => 'recorded_local_facts', 'live_execution_enabled' => false];
        if (! $summary) {
            return $out;
        }
        $base = DB::query()->fromSub($q, 'r');
        $out['total'] = (clone $base)->count();
        $state = Definitions::get($report)[2];
        $out['distribution'] = $state ? (clone $base)->selectRaw("coalesce($state::text,'not_assessed') AS status,count(*)::int AS count")->groupBy($state)->orderBy($state)->get() : [];
        if ($report === 'quote_summary') {
            $out['expiry'] = (array) (clone $base)->selectRaw('count(*) FILTER(WHERE expires_at <= ?)::int AS expired,count(*) FILTER(WHERE expires_at > ?)::int AS unexpired', [now(), now()])->first();
        }
        $grain = $f['grain'];
        $out['trend'] = (clone $base)->selectRaw("date_trunc('$grain',created_at AT TIME ZONE 'UTC')::date AS day,count(*)::int AS count")->groupByRaw("date_trunc('$grain',created_at AT TIME ZONE 'UTC')::date")->orderBy('day')->get();
        if ($grain === 'day') {
            $points = $out['trend']->pluck('count', 'day');
            $series = [];
            for ($day = CarbonImmutable::parse($f['period']['from'], 'UTC'); $day->toDateString() <= $f['period']['to']; $day = $day->addDay()) {
                $series[] = ['day' => $day->toDateString(), 'count' => (int) ($points[$day->toDateString()] ?? 0)];
            }
            $out['trend'] = $series;
        }
        if (in_array($report, ['connected_orders', 'draft_conversion'], true)) {
            $linked = (clone $base)->whereNotNull('shipment_id')->count();
            $out['conversion'] = ['linked' => $linked, 'total' => $out['total'], 'ratio' => $out['total'] ? bcdiv((string) $linked, (string) $out['total'], 6) : null, 'meaning' => 'local_draft_link_not_paid_shipping'];
            $out['provenance'] = (clone $base)->selectRaw('provenance,count(*)::int AS count')->groupBy('provenance')->get();
        }
        if (in_array($report, ['wallet_transactions', 'wallet_reservations'], true)) {
            $amount = $report === 'wallet_transactions' ? 'delta' : 'amount';
            $out['movements'] = (clone $base)->selectRaw("currency,exponent,kind,count(*)::int AS count,sum($amount::numeric)::text AS amount_minor")->groupBy('currency', 'exponent', 'kind')->orderBy('currency')->get();
        }

        return $out;
    }
}
