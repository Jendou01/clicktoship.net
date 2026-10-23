<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_mfa_credentials', function (Blueprint $t): void {
            $t->foreignUuid('user_id')->primary()->constrained()->restrictOnDelete();
            $t->text('secret')->nullable();
            $t->text('pending_secret')->nullable();
            $t->timestampTz('pending_expires_at')->nullable();
            $t->timestampTz('confirmed_at')->nullable();
            $t->bigInteger('last_step')->default(-1);
            $t->integer('version')->default(1);
        });
        Schema::create('mfa_recovery_codes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $t->string('code_hash', 64)->unique();
            $t->timestampTz('used_at')->nullable();
        });
        Schema::table('auth_sessions', function (Blueprint $t): void {
            $t->timestampTz('mfa_verified_at')->nullable();
            $t->integer('mfa_version')->nullable();
            $t->unique(['user_id', 'id']);
        });
        Schema::table('users', fn (Blueprint $t) => $t->integer('admin_version')->default(1));
        Schema::create('platform_access_grants', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('merchant_id')->constrained()->restrictOnDelete();
            $t->uuid('actor_id');
            $t->uuid('session_id');
            $t->foreign(['actor_id', 'session_id'])->references(['user_id', 'id'])->on('auth_sessions')->restrictOnDelete();
            $t->jsonb('capabilities');
            $t->text('reason');
            $t->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->uuid('approval_session_id')->nullable();
            $t->foreign(['approved_by', 'approval_session_id'])->references(['user_id', 'id'])->on('auth_sessions')->restrictOnDelete();
            $t->timestampTz('approved_at')->nullable();
            $t->timestampTz('expires_at');
            $t->timestampTz('revoked_at')->nullable();
            $t->integer('version')->default(1);
            $t->timestampTz('created_at');
            $t->index(['actor_id', 'session_id', 'expires_at']);
        });
        DB::statement('ALTER TABLE platform_access_grants ADD CONSTRAINT grant_approval_check CHECK ((approved_by IS NULL AND approved_at IS NULL AND approval_session_id IS NULL) OR (approved_by IS NOT NULL AND approved_at IS NOT NULL AND approval_session_id IS NOT NULL AND approved_by <> actor_id))');
        DB::statement("ALTER TABLE platform_access_grants ADD CONSTRAINT grant_capability_check CHECK (jsonb_typeof(capabilities) = 'array' AND jsonb_array_length(capabilities) BETWEEN 1 AND 3 AND capabilities <@ '[\"platform.merchant.view\",\"platform.merchant.status\",\"platform.verification.review\"]'::jsonb AND version > 0 AND expires_at > created_at AND expires_at <= created_at + interval '15 minutes')");
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_access_grants');
        Schema::dropIfExists('mfa_recovery_codes');
        Schema::dropIfExists('user_mfa_credentials');
        Schema::table('auth_sessions', function (Blueprint $t): void {
            $t->dropUnique(['user_id', 'id']);
            $t->dropColumn(['mfa_verified_at', 'mfa_version']);
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('admin_version'));
    }
};
