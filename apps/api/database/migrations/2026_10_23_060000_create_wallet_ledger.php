<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/phase4.sql')));
    }

    public function down(): void
    {
        if (DB::table('ledger_transactions')->exists() && ! (app()->environment('testing') && DB::connection()->getDatabaseName() === 'clicktoship_test')) {
            throw new RuntimeException('Posted financial history prevents downgrade; use a forward migration.');
        }
        DB::unprepared('DROP FUNCTION IF EXISTS finance_command(uuid,uuid,text,bigint,uuid,text,text,text,text,timestamptz);');
        foreach (['payment_events', 'payment_intents', 'financial_case_events', 'financial_reconciliation_runs', 'financial_outbox_deliveries', 'financial_outbox', 'financial_approval_events', 'financial_approvals', 'financial_refunds', 'wallet_transactions', 'balance_hold_events', 'balance_holds', 'ledger_entries', 'ledger_transactions', 'financial_templates', 'financial_request_keys', 'financial_operations', 'wallet_balances', 'wallets', 'ledger_accounts', 'financial_currencies'] as $table) {
            DB::statement("DROP TABLE $table");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS finance_template_guard(); DROP FUNCTION IF EXISTS finance_wallet_identity_guard(); DROP FUNCTION IF EXISTS finance_entry_insert_guard(); DROP FUNCTION IF EXISTS finance_projection_check(); DROP FUNCTION IF EXISTS finance_approval_guard(); DROP FUNCTION IF EXISTS finance_balanced(); DROP FUNCTION IF EXISTS finance_immutable();');
    }
};
