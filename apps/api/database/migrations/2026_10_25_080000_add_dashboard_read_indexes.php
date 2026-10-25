<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INDEXES = [
        'dashboard_submissions' => 'shipment_submissions(merchant_id,created_at DESC,id DESC)',
        'dashboard_quotes' => 'shipping_quotes(merchant_id,created_at DESC,id DESC)',
        'dashboard_customs_latest' => 'customs_readiness(merchant_id,shipment_id,revision,created_at DESC,id DESC)',
        'dashboard_customs_directory' => 'customs_declarations(merchant_id,created_at DESC,id DESC)',
        'dashboard_customs_activity' => 'customs_events(merchant_id,created_at DESC,id DESC)',
        'dashboard_shipping_activity' => 'shipment_events(merchant_id,created_at DESC,id DESC)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $target) {
            DB::statement("CREATE INDEX $name ON $target");
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $name => $target) {
            DB::statement("DROP INDEX IF EXISTS $name");
        }
    }
};
