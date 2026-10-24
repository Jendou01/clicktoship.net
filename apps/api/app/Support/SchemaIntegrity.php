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

        return $missing;
    }
}
