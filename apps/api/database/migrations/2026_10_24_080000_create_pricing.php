<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/phase5.sql')));
    }

    public function down(): void
    {
        if (DB::table('shipping_quotes')->exists() || DB::table('pricing_versions')->exists()) {
            if (! (app()->environment('testing') && DB::connection()->getDatabaseName() === 'clicktoship_test')) {
                throw new RuntimeException('Retained pricing history prevents downgrade.');
            }
        }
        DB::unprepared('DROP TABLE pricing_quote_requests,shipping_quotes,pricing_events; ALTER TABLE pricing_definitions DROP CONSTRAINT pricing_definitions_id_active_version_fkey; DROP TABLE pricing_versions,pricing_definitions;');
    }
};
