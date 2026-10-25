<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE commerce_authorizations ADD COLUMN cancelled_at timestamptz');
        DB::statement('CREATE INDEX commerce_authorization_pending ON commerce_authorizations(merchant_id,expires_at) WHERE cancelled_at IS NULL');
    }

    public function down(): void
    {
        if (! (app()->environment('testing') && DB::connection()->getDatabaseName() === 'clicktoship_test') && DB::table('commerce_authorizations')->exists()) {
            throw new RuntimeException('Retained authorization history prevents cancellation-fence downgrade.');
        }
        DB::statement('DROP INDEX commerce_authorization_pending');
        DB::statement('ALTER TABLE commerce_authorizations DROP COLUMN cancelled_at');
    }
};
