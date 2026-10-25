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

        if (Schema::hasTable('tracking_bindings')) {
            foreach (['tracking_bindings' => ['merchant_id', 'observation_id', 'operation_id', 'reference_hash'], 'tracking_work' => ['merchant_id', 'lease_token', 'replay_hash'], 'tracking_event_details' => ['fact_hash', 'outcome', 'validation']] as $table => $columns) {
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        $missing[] = "$table.$column";
                    }
                }
            }
            foreach (['tracking_bindings', 'tracking_commands', 'tracking_event_details', 'tracking_attempts', 'tracking_cases', 'tracking_reviews', 'tracking_notifications', 'tracking_receipts'] as $table) {
                if (! DB::selectOne("SELECT 1 FROM pg_trigger WHERE tgrelid=?::regclass AND tgname='tracking_history_immutable' AND tgenabled IN ('O','A')", [$table])) {
                    $missing[] = "$table.tracking_history_immutable";
                }
            }
            foreach (['tracking_bindings' => 'tracking_binding_guard', 'tracking_work' => 'tracking_work_guard', 'carrier_tracking_projections' => 'tracking_projection_guard'] as $table => $name) {
                if (! DB::selectOne("SELECT 1 FROM pg_trigger WHERE tgrelid=?::regclass AND tgname=? AND tgenabled IN ('O','A')", [$table, $name])) {
                    $missing[] = "$table.$name";
                }
            }
            if (! DB::selectOne("SELECT 1 FROM pg_constraint WHERE conrelid='tracking_work'::regclass AND conname='tracking_work_binding_account_scope' AND contype='f' AND convalidated")) {
                $missing[] = 'tracking_work binding/account foreign key';
            }
        } elseif (DB::table('migrations')->where('migration', '2026_10_24_150000_create_tracking')->exists()) {
            $missing[] = 'tracking_bindings';
        }

        if (Schema::hasTable('customs_declarations')) {
            foreach (['customs_versions' => ['merchant_id', 'revision', 'payload_ciphertext', 'input_hash'], 'customs_items' => ['unit_minor', 'quantity', 'total_minor'], 'customs_document_work' => ['origin_token_id', 'lease_token'], 'customs_documents' => ['revision', 'number']] as $table => $columns) {
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        $missing[] = "$table.$column";
                    }
                }
            }
            foreach (['customs_versions', 'customs_items', 'customs_profiles', 'customs_rule_versions', 'customs_rule_reviews', 'customs_readiness', 'customs_reviews', 'customs_commands', 'customs_events', 'customs_documents', 'customs_document_results', 'customs_document_revocations'] as $table) {
                if (! DB::selectOne("SELECT 1 FROM pg_trigger WHERE tgrelid=?::regclass AND tgname='customs_history_immutable' AND tgenabled IN ('O','A')", [$table])) {
                    $missing[] = "$table.customs_history_immutable";
                }
            }
            foreach (['customs_items' => 'customs_item_totals', 'customs_versions' => 'customs_item_totals', 'customs_declarations' => 'customs_version_guard', 'customs_document_work' => 'customs_work_guard', 'customs_rule_reviews' => 'customs_rule_review_guard'] as $table => $trigger) {
                if (! DB::selectOne("SELECT 1 FROM pg_trigger WHERE tgrelid=?::regclass AND tgname=? AND tgenabled IN ('O','A')", [$table, $trigger])) {
                    $missing[] = "$table.$trigger";
                }
            }
        } elseif (DB::table('migrations')->where('migration', '2026_10_24_170000_create_international_customs')->exists()) {
            $missing[] = 'customs_declarations';
        }

        if (DB::table('migrations')->where('migration', '2026_10_25_080000_add_dashboard_read_indexes')->exists()) {
            foreach (['dashboard_submissions', 'dashboard_quotes', 'dashboard_customs_latest', 'dashboard_customs_directory', 'dashboard_customs_activity', 'dashboard_shipping_activity'] as $index) {
                if (! DB::selectOne("SELECT 1 FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname=? AND i.indisvalid AND i.indisready", [$index])) {
                    $missing[] = 'index.'.$index;
                }
            }
        }

        if (DB::table('migrations')->where('migration', '2026_10_25_100000_create_platform_overview')->exists()) {
            if (! Schema::hasTable('platform_alert_receipts')) {
                $missing[] = 'platform_alert_receipts';
            }
            if (! DB::selectOne("SELECT 1 FROM pg_trigger WHERE tgrelid=to_regclass('public.platform_alert_receipts') AND tgname='platform_receipt_history' AND tgenabled IN ('O','A')")) {
                $missing[] = 'platform_alert_receipts.platform_receipt_history';
            }
        }

        if (DB::table('migrations')->where('migration', '2026_10_25_120000_create_commerce')->exists()) {
            foreach (['connections', 'credentials', 'authorizations', 'orders', 'snapshots', 'runs', 'pages', 'draft_links', 'results', 'commands', 'events', 'receipts'] as $table) {
                if (! Schema::hasTable('commerce_'.$table)) {
                    $missing[] = 'commerce_'.$table;
                }
            }
            foreach (['commerce_snapshots', 'commerce_pages', 'commerce_draft_links', 'commerce_results', 'commerce_commands', 'commerce_events', 'commerce_receipts'] as $table) {
                if (! DB::selectOne("SELECT 1 FROM pg_trigger WHERE tgrelid=to_regclass(?) AND tgname='commerce_history' AND tgenabled IN ('O','A')", ['public.'.$table])) {
                    $missing[] = $table.'.commerce_history';
                }
            }
            if (DB::table('migrations')->where('migration', '2026_10_25_140000_extend_commerce_provider_identity')->exists()) {
                foreach (['commerce_connections' => ['external_platform_id', 'display_domain'], 'commerce_authorizations' => ['provider']] as $table => $columns) {
                    foreach ($columns as $column) {
                        if (! Schema::hasColumn($table, $column)) {
                            $missing[] = $table.'.'.$column.' missing';
                        }
                    }
                }
                $identity = DB::selectOne("SELECT pg_get_functiondef('commerce_identity_guard()'::regprocedure) AS definition");
                if (! str_contains($identity->definition, 'external_platform_id')) {
                    $missing[] = 'Commerce platform identity guard missing';
                }
            }
            if (DB::table('migrations')->where('migration', '2026_10_25_140000_extend_commerce_provider_identity')->exists()) {
                $constraint = DB::selectOne("SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid='commerce_connections'::regclass AND conname='commerce_source_identity' AND contype='u'");
                if (! $constraint || ! str_contains($constraint->definition, 'provider, external_platform_id, external_store_id')) {
                    $missing[] = 'commerce_source_identity';
                }
            }
            foreach (['commerce_connections' => 'commerce_owner_guard', 'commerce_orders' => 'commerce_order_owner_guard'] as $table => $trigger) {
                if (! DB::selectOne("SELECT 1 FROM pg_trigger WHERE tgrelid=to_regclass(?) AND tgname=? AND tgenabled IN ('O','A')", ['public.'.$table, $trigger])) {
                    $missing[] = $table.'.'.$trigger;
                }
            }
            if (! Schema::hasColumn('commerce_authorizations', 'cancelled_at')) {
                $missing[] = 'commerce_authorizations.cancelled_at';
            }
            foreach (['commerce_authorization_pending', 'commerce_one_active_run', 'commerce_order_directory', 'commerce_order_reference'] as $index) {
                if (! DB::selectOne('SELECT 1 FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid WHERE c.relname=? AND i.indisvalid AND i.indisready', [$index])) {
                    $missing[] = 'index.'.$index;
                }
            }
        }

        if (DB::table('migrations')->where('migration', '2026_10_25_150000_create_analytics_exports')->exists()) {
            foreach (['report_exports' => ['scope', 'merchant_id', 'actor_id', 'filters', 'lease', 'artifact', 'last_cleanup_at'], 'report_export_events' => ['export_id', 'kind', 'artifact']] as $table => $columns) {
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        $missing[] = $table.'.'.$column;
                    }
                }
            }
            foreach (['report_exports' => 'report_export_identity_guard', 'report_export_events' => 'report_export_history'] as $table => $trigger) {
                if (! DB::selectOne("SELECT 1 FROM pg_trigger WHERE tgrelid=to_regclass(?) AND tgname=? AND tgenabled IN ('O','A')", ['public.'.$table, $trigger])) {
                    $missing[] = $table.'.'.$trigger;
                }
            }
            foreach (['report_export_history', 'report_export_active_digest', 'report_export_event_history', 'report_export_recovery', 'report_export_expiry', 'analytics_hold_events', 'analytics_wallet_movements', 'analytics_commerce_orders'] as $index) {
                if (! DB::selectOne('SELECT 1 FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid WHERE c.relname=? AND i.indisvalid AND i.indisready', [$index])) {
                    $missing[] = 'index.'.$index;
                }
            }
        }

        return $missing;
    }
}
