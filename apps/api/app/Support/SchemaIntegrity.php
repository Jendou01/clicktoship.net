<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only checks for security-critical Phase 7 objects, independent of migration history. */
final class SchemaIntegrity
{
    public function failures(): array
    {
        $missing = [];
        foreach (['shipments' => ['merchant_id', 'carrier_observation_id', 'revision'], 'shipment_submissions' => ['merchant_id', 'shipment_id', 'revision'], 'shipping_observations' => ['merchant_id', 'submission_id']] as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missing[] = "$table.$column";
                }
            }
        }
        // Include every declared Phase 7 trigger, including immutable history and tenant binding.
        preg_match_all('/CREATE (?:CONSTRAINT )?TRIGGER (\w+).*? ON (\w+) /i', file_get_contents(database_path('sql/phase7.sql')), $triggers, PREG_SET_ORDER);
        preg_match("/FOREACH t IN ARRAY ARRAY\[(.*?)\] LOOP/s", file_get_contents(database_path('sql/phase7.sql')), $history);
        foreach (explode(',', $history[1]) as $table) {
            $triggers[] = ['', 'shipping_history_immutable', trim($table, " '\n")];
        }
        foreach ($triggers as $trigger) {
            $found = DB::selectOne("SELECT 1 FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname=? AND t.tgname=? AND t.tgenabled IN ('O','A') AND NOT t.tgisinternal", [$trigger[2], $trigger[1]]);
            if (! $found) {
                $missing[] = "$trigger[2].$trigger[1]";
            }
        }
        $fk = DB::selectOne("SELECT 1 FROM pg_constraint WHERE conrelid='public.shipments'::regclass AND conname='shipments_merchant_id_carrier_observation_id_fkey' AND contype='f' AND convalidated");
        if (! $fk) {
            $missing[] = 'shipments decisive-observation foreign key';
        }

        if (Schema::hasTable('bulk_batches')) {
            foreach (['bulk_batches' => ['merchant_id', 'mapping_version', 'origin_token_id'], 'bulk_work' => ['cursor', 'lease_until'], 'bulk_rows' => ['raw_hash'], 'bulk_drafts' => ['shipment_id']] as $table => $columns) {
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        $missing[] = "$table.$column";
                    }
                }
            }
            foreach (['bulk_files', 'bulk_rows', 'bulk_mappings', 'bulk_validations', 'bulk_decisions', 'bulk_prices', 'bulk_approvals', 'bulk_drafts', 'bulk_events', 'bulk_commands'] as $table) {
                $guard = DB::selectOne("SELECT 1 FROM pg_trigger WHERE tgrelid=?::regclass AND tgname='bulk_history_immutable' AND tgenabled IN ('O','A')", [$table]);
                if (! $guard) {
                    $missing[] = "$table.bulk_history_immutable";
                }
            }
        } elseif (DB::table('migrations')->where('migration', '2026_10_24_130000_create_bulk_imports')->exists()) {
            $missing[] = 'bulk_batches';
        }

        return $missing;
    }
}
