<?php

namespace App\Modules\Analytics\Application;

final class Definitions
{
    public static function all(): array
    {
        return [
            'shipment_summary' => ['shipments.read', 'shipments', 'state', ['id', 'state', 'financial_state', 'document_state', 'created_at'], ['status']],
            'shipment_history' => ['shipments.read', 'shipment_events', 'kind', ['id', 'shipment_id', 'kind', 'created_at'], ['status']],
            'submission_outcomes' => ['shipments.read', 'shipment_submissions', null, ['id', 'shipment_id', 'created_at'], []],
            'submission_attempts' => ['shipments.read', 'carrier_attempts', 'outcome', ['id', 'outcome', 'created_at'], ['status']],
            'tracking_exceptions' => ['tracking.read', 'tracking_cases', 'reason', ['id', 'reason', 'created_at'], ['status']],
            'bulk_summary' => ['bulk.read', 'bulk_batches', 'state', ['id', 'state', 'row_count', 'created_at'], ['status']],
            'wallet_transactions' => ['wallet.transactions.read', 'wallet_transactions', 'kind', ['id', 'sequence', 'kind', 'delta', 'posted_after', 'reserved_after', 'currency', 'exponent', 'created_at'], ['status', 'currency']],
            'wallet_reservations' => ['wallet.holds.read', 'balance_hold_events', 'kind', ['id', 'kind', 'amount', 'currency', 'exponent', 'created_at'], ['status', 'currency']],
            'financial_reconciliation' => ['wallet.read', 'financial_reconciliation_runs', 'matches', ['id', 'matches', 'currency', 'exponent', 'cutoff', 'created_at'], ['currency']],
            'quote_summary' => ['rates.read', 'shipping_quotes', null, ['id', 'currency', 'exponent', 'total_minor', 'selling_minor', 'discount_minor', 'expires_at', 'service_id', 'created_at'], ['currency', 'service']],
            'customs_readiness' => ['customs.read', 'customs_declarations', 'local_valid', ['id', 'local_valid', 'created_at'], []],
            'connected_orders' => ['orders.read', 'commerce_orders', 'state', ['id', 'provider', 'provenance', 'state', 'review_required', 'shipment_id', 'created_at'], ['provider', 'status']],
            'draft_conversion' => ['orders.read', 'commerce_orders', 'state', ['id', 'provider', 'provenance', 'state', 'review_required', 'shipment_id', 'created_at'], ['provider', 'status']],
            'connection_states' => ['integrations.read', 'commerce_connections', 'state', ['id', 'provider', 'provenance', 'state', 'created_at'], ['provider', 'status']],
            'commerce_activity' => ['integrations.read', 'commerce_events', 'kind', ['id', 'provider', 'kind', 'created_at'], ['provider', 'status']],
            'merchant_activity' => ['merchant.view', 'audit_logs', 'action', ['id', 'action', 'created_at'], ['status']],
        ];
    }

    public static function get(string $report): array
    {
        abort_unless(isset(self::all()[$report]), 404);

        return self::all()[$report];
    }

    public static function financial(string $report): bool
    {
        return in_array($report, ['wallet_transactions', 'wallet_reservations', 'financial_reconciliation'], true);
    }

    public static function catalog(array $permissions): array
    {
        $out = [];
        foreach (self::all() as $id => [$permission,$table,$state,$columns,$filters]) {
            if (in_array($permission, $permissions, true)) {
                $out[] = ['id' => $id, 'permission' => $permission, 'source' => $table, 'date' => 'created_at', 'columns' => $columns, 'filters' => $filters, 'financial' => self::financial($id), 'export_formats' => ['csv', 'xlsx'], 'page_size' => 25, 'max_export_rows' => 1000, 'max_export_bytes' => 2097152];
            }
        }

        return $out;
    }
}
