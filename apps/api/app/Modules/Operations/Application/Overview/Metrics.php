<?php

namespace App\Modules\Operations\Application\Overview;

use App\Modules\Carriers\Domain\CarrierCapabilityRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Cross-merchant COUNTs only. No private IDs/payloads/money are projected globally. */
final class Metrics
{
    public function window($q, array $period, string $column = 'created_at')
    {
        return $q->where($column, '>=', $period['start'])->where($column, '<', $period['end_exclusive']);
    }

    public function groups($q, string $column): array
    {
        return $q->selectRaw("$column AS status, count(*)::int AS count")->groupBy($column)->orderBy($column)->get()->all();
    }

    public function trend($q, array $p, string $column = 'created_at'): array
    {
        $values = $this->window($q, $p, $column)->selectRaw("($column AT TIME ZONE 'UTC')::date AS day,count(*)::int AS count")->groupByRaw("($column AT TIME ZONE 'UTC')::date")->pluck('count', 'day');
        $out = [];
        for ($d = CarbonImmutable::parse($p['from'], 'UTC'); $d->toDateString() <= $p['to']; $d = $d->addDay()) {
            $out[] = ['day' => $d->toDateString(), 'count' => (int) ($values[$d->toDateString()] ?? 0)];
        }

        return $out;
    }

    public function overview(array $permissions, array $p, ?string $section = null): array
    {
        $widgets = [];
        foreach (['merchants', 'users', 'shipping', 'bulk', 'tracking', 'carriers', 'pricing', 'finance', 'customs'] as $kind) {
            if ((! $section || $kind === $section) && in_array('platform.metrics.'.$kind, $permissions, true)) {
                $widgets[$kind] = $this->$kind($p);
            }
        }

        return ['widgets' => (object) $widgets, 'period' => $p, 'generated_at' => now()->toIso8601String(), 'permissions' => $permissions, 'live_shipping_enabled' => false, 'payments_enabled' => false, 'notifications_enabled' => false];
    }

    public function merchants(array $p): array
    {
        return ['total' => DB::table('merchants')->count(), 'accounts' => $this->groups(DB::table('merchants'), 'status'), 'verification' => $this->groups(DB::table('merchants'), 'verification_status'), 'registrations' => $this->trend(DB::table('merchants'), $p), 'verification_decisions' => $this->trend(DB::table('merchant_verification_decisions'), $p), 'pending_accounts_supported' => false];
    }

    public function users(array $p): array
    {
        return ['total' => DB::table('users')->count(), 'suspended' => DB::table('users')->whereNotNull('suspended_at')->count(), 'platform_assignments' => DB::table('platform_role_assignments')->count()];
    }

    public function shipping(array $p, ?string $merchant = null): array
    {
        $q = DB::table('shipments')->when($merchant, fn ($q) => $q->where('merchant_id', $merchant));
        $cohort = $this->window(clone $q, $p);
        $sub = DB::table('shipment_submissions')->when($merchant, fn ($q) => $q->where('merchant_id', $merchant));
        $exceptions = (clone $q)->where(fn ($x) => $x->whereNotNull('exception_code')->orWhereIn('state', ['unknown', 'blocked'])->orWhereIn('financial_state', ['capture_pending', 'release_pending', 'review']));
        $accepted = DB::table('shipments as s')->join('shipping_observations as o', fn ($j) => $j->on('o.id', '=', 's.carrier_observation_id')->on('o.merchant_id', '=', 's.merchant_id'))->when($merchant, fn ($q) => $q->where('s.merchant_id', $merchant))->where('s.state', 'accepted')->where('o.outcome', 'accepted')->where('o.provenance', 'verified');

        return ['created_in_period' => (clone $cohort)->count(), 'states' => $this->groups($cohort, 'state'), 'submitted_in_period' => $this->window(clone $sub, $p)->count(), 'verified_accepted_in_period' => $this->window($accepted, $p, 'o.created_at')->count(), 'open_exceptions' => $exceptions->count(), 'unknown_outcomes' => (clone $q)->where('state', 'unknown')->count(), 'pending_settlement' => (clone $q)->whereIn('financial_state', ['capture_pending', 'release_pending', 'review'])->count(), 'quarantined_documents' => (clone $q)->where('document_state', 'quarantined')->count(), 'missing_documents' => (clone $q)->whereIn('state', ['accepted'])->whereIn('document_state', ['not_requested', 'failed'])->count(), 'pickup_exceptions' => DB::table('pickups')->when($merchant, fn ($q) => $q->where('merchant_id', $merchant))->whereIn('state', ['unknown', 'rejected', 'cancel_pending'])->count(), 'creation_trend' => $this->trend(clone $q, $p), 'submission_trend' => $this->trend($sub, $p), 'external_enabled' => false];
    }

    public function bulk(array $p): array
    {
        $work = DB::table('bulk_work')->whereNull('completed_at');

        return ['batches' => $this->groups($this->window(DB::table('bulk_batches'), $p), 'state'), 'trend' => $this->trend(DB::table('bulk_batches'), $p), 'failed' => DB::table('bulk_batches')->where('state', 'failed')->count(), 'active_work' => (clone $work)->count(), 'recovery_candidates' => (clone $work)->where(fn ($q) => $q->where('lease_until', '<', now())->orWhere(fn ($q) => $q->whereNull('lease_until')->where('created_at', '<', now()->subMinutes(5))))->count(), 'external_enabled' => false];
    }

    public function tracking(array $p): array
    {
        $verified = DB::table('tracking_bindings as b')->join('carrier_tracking_projections as p', fn ($j) => $j->on('p.merchant_id', '=', 'b.merchant_id')->on('p.account_id', '=', 'b.account_id')->on('p.operation_id', '=', 'b.operation_id'))->join('tracking_event_details as d', fn ($j) => $j->on('d.event_id', '=', 'p.event_id')->on('d.binding_id', '=', 'b.id'))->where('b.provenance', 'verified')->where('d.provenance', 'verified')->where('d.validation', 'verified')->where('p.status', '<>', 'unknown');

        return ['work' => $this->groups(DB::table('tracking_work'), 'state'), 'webhook_work' => $this->groups(DB::table('tracking_work')->where('kind', 'webhook'), 'state'), 'verified_statuses' => $this->groups($this->window(clone $verified, $p, 'p.occurred_at'), 'p.status'), 'stale_verified' => (clone $verified)->where('p.occurred_at', '<', now()->subHours(24))->count(), 'unknown_codes' => DB::table('tracking_event_details')->where('validation', 'unknown_code')->count(), 'cases' => DB::table('tracking_cases')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('tracking_reviews')->whereColumn('tracking_reviews.case_id', 'tracking_cases.id'))->count(), 'failed_work' => DB::table('tracking_work')->whereIn('state', ['dead_letter', 'quarantined'])->count(), 'exception_trend' => $this->trend(DB::table('tracking_cases'), $p), 'external_enabled' => false];
    }

    public function carriers(array $p): array
    {
        return ['accounts' => DB::table('carrier_accounts')->count(), 'environments' => $this->groups(DB::table('carrier_accounts'), 'environment'), 'disabled_accounts' => DB::table('carrier_accounts')->whereNull('active_version')->count(), 'configured_accounts' => DB::table('carrier_accounts')->whereNotNull('active_version')->count(), 'operations' => $this->groups($this->window(DB::table('carrier_operations'), $p), 'state'), 'capabilities' => app(CarrierCapabilityRegistry::class)->all(), 'sandbox_verified' => false, 'external_enabled' => false];
    }

    public function pricing(array $p): array
    {
        $global = DB::table('pricing_definitions')->whereNull('merchant_id');

        return ['global_definitions' => $this->groups(clone $global, 'kind'), 'enabled_global' => (clone $global)->where('enabled', true)->count(), 'pending_global' => (clone $global)->where(fn ($q) => $q->whereNull('active_version')->orWhereColumn('version', '<>', 'active_version'))->count(), 'external_rates_enabled' => false, 'cost_payloads_included' => false];
    }

    public function finance(array $p): array
    {
        $wallets = DB::table('wallets as w')->join('financial_currencies as c', 'c.code', '=', 'w.currency')->join('wallet_balances as b', 'b.wallet_id', '=', 'w.id')->selectRaw('w.currency,c.exponent,count(*)::int AS wallets,count(*) FILTER(WHERE b.reserved>0)::int AS wallets_with_reservations,count(*) FILTER(WHERE b.frozen)::int AS frozen_wallets')->groupBy('w.currency', 'c.exponent')->orderBy('w.currency')->limit(200)->get();
        $latest = DB::table('financial_reconciliation_runs')->selectRaw('DISTINCT ON(wallet_id) wallet_id,matches')->orderBy('wallet_id')->orderByDesc('created_at')->orderByDesc('id');

        return ['currencies' => $wallets, 'reconciliation_cases' => DB::query()->fromSub($latest, 'latest')->where('matches', false)->count(), 'pending_approvals' => DB::table('financial_approvals')->whereNull('revoked_at')->whereNull('executed_operation')->where('expires_at', '>', now())->count(), 'pending_settlement' => DB::table('shipments')->whereIn('financial_state', ['capture_pending', 'release_pending', 'review'])->count(), 'amounts_visibility' => 'exact_target_grant_required', 'funding_enabled' => false, 'execution_enabled' => false];
    }

    public function customs(array $p): array
    {
        return ['rules' => DB::table('customs_country_rules')->count(), 'pending_reviews' => app(Approvals::class)->countryPending()->count(), 'document_work' => $this->groups(DB::table('customs_document_work'), 'state'), 'quarantined_documents' => app(Facts::class)->currentCustomsQuarantine()->count(), 'local_incomplete' => DB::query()->fromSub(DB::table('customs_readiness')->selectRaw('DISTINCT ON(merchant_id,shipment_id,revision) merchant_id,shipment_id,revision,report')->orderBy('merchant_id')->orderBy('shipment_id')->orderBy('revision')->orderByDesc('created_at')->orderByDesc('id'), 'r')->join('shipments as s', fn ($j) => $j->on('s.merchant_id', '=', 'r.merchant_id')->on('s.id', '=', 'r.shipment_id')->on('s.revision', '=', 'r.revision'))->whereRaw("(r.report->>'local_valid')::boolean=false")->count(), 'clearance_enabled' => false];
    }
}
