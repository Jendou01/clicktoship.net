<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE merchants DROP CONSTRAINT merchant_status_check');
        DB::statement("ALTER TABLE merchants ADD CONSTRAINT merchant_status_check CHECK (status IN ('active','suspended','closed'))");
        Schema::table('merchants', function (Blueprint $t): void {
            $t->integer('version')->default(1);
            $t->string('verification_status', 20)->default('unverified');
        });
        DB::statement("ALTER TABLE merchants ADD CONSTRAINT merchant_verification_check CHECK (verification_status IN ('unverified','pending','verified','rejected'))");
        DB::statement('ALTER TABLE merchants ADD CONSTRAINT merchant_version_check CHECK (version > 0)');
        foreach (['merchant_profiles', 'merchant_addresses'] as $name) {
            Schema::create($name, function (Blueprint $t) use ($name): void {
                $t->uuid('id')->primary();
                $t->foreignUuid('merchant_id')->constrained()->restrictOnDelete();
                $t->text('payload'); // Authenticated encryption of contact/business/physical-address data.
                $t->integer('version')->default(1);
                $t->timestampsTz();
                $t->unique(['merchant_id', 'id']);
                if ($name === 'merchant_profiles') {
                    $t->unique('merchant_id');
                } else {
                    $t->timestampTz('deleted_at')->nullable();
                    $t->index(['merchant_id', 'deleted_at', 'created_at', 'id']);
                }
            });
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_version_check CHECK (version > 0)");
        }
        Schema::create('merchant_settings', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('merchant_id')->unique()->constrained()->restrictOnDelete();
            $t->string('locale', 2)->default('en');
            $t->string('timezone', 80)->default('UTC');
            $t->uuid('origin_address_id')->nullable();
            $t->uuid('return_address_id')->nullable();
            $t->integer('version')->default(1);
            $t->timestampsTz();
            foreach (['origin_address_id', 'return_address_id'] as $column) {
                $t->foreign(['merchant_id', $column])->references(['merchant_id', 'id'])->on('merchant_addresses')->restrictOnDelete();
            }
        });
        DB::statement("ALTER TABLE merchant_settings ADD CONSTRAINT settings_locale_check CHECK (locale IN ('en','ar') AND version > 0)");
        Schema::create('merchant_verification_submissions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('merchant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('submitted_by')->constrained('users')->restrictOnDelete();
            $t->integer('profile_version');
            $t->text('snapshot');
            $t->timestampTz('created_at');
            $t->unique(['merchant_id', 'id']);
            $t->index(['merchant_id', 'created_at', 'id']);
        });
        Schema::create('merchant_verification_decisions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('merchant_id');
            $t->uuid('submission_id')->unique();
            $t->foreignUuid('reviewed_by')->constrained('users')->restrictOnDelete();
            $t->string('outcome', 20);
            $t->text('reason');
            $t->timestampTz('created_at');
            $t->foreign(['merchant_id', 'submission_id'])->references(['merchant_id', 'id'])->on('merchant_verification_submissions')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE merchant_verification_decisions ADD CONSTRAINT verification_outcome_check CHECK (outcome IN ('verified','rejected'))");
        Schema::create('merchant_invitations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('merchant_id')->constrained()->restrictOnDelete();
            $t->text('email');
            $t->string('email_hash', 64);
            $t->uuid('role_id');
            $t->string('role_scope', 20)->default('merchant');
            $t->foreign(['role_id', 'role_scope'])->references(['id', 'scope'])->on('roles')->restrictOnDelete();
            $t->foreignUuid('invited_by')->constrained('users')->restrictOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->timestampTz('expires_at');
            $t->timestampTz('accepted_at')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->integer('version')->default(1);
            $t->timestampsTz();
            $t->index(['merchant_id', 'created_at', 'id']);
        });
        DB::statement("ALTER TABLE merchant_invitations ADD CONSTRAINT invitation_scope_check CHECK (role_scope = 'merchant' AND version > 0)");
        DB::statement('CREATE UNIQUE INDEX invitation_active_email ON merchant_invitations (merchant_id,email_hash) WHERE accepted_at IS NULL AND revoked_at IS NULL');
        foreach (['merchant_verification_submissions', 'merchant_verification_decisions'] as $name) {
            DB::unprepared("CREATE TRIGGER immutable_{$name} BEFORE UPDATE OR DELETE ON {$name} FOR EACH ROW EXECUTE FUNCTION forbid_audit_mutation(); CREATE TRIGGER immutable_{$name}_truncate BEFORE TRUNCATE ON {$name} FOR EACH STATEMENT EXECUTE FUNCTION forbid_audit_mutation();");
        }
        // Only add grants; preserve deployed role IDs and existing assignments.
        $map = [
            'merchant.owner' => ['profile.view', 'profile.manage', 'settings.manage', 'addresses.view', 'addresses.manage', 'verification.submit', 'audit.view'],
            'merchant.admin' => ['profile.view', 'profile.manage', 'settings.manage', 'addresses.view', 'addresses.manage', 'audit.view'],
            'merchant.shipping_manager' => ['addresses.view', 'addresses.manage'],
            'merchant.shipping_operator' => ['addresses.view'],
            'merchant.finance_manager' => ['profile.view', 'addresses.view'],
            'merchant.viewer' => ['addresses.view'],
            'platform.super_admin' => ['platform.directory', 'platform.access.approve', 'platform.access.request', 'platform.merchant.view', 'platform.merchant.status', 'platform.verification.review', 'platform.users.manage', 'platform.roles.assign', 'platform.audit.view'],
            'platform.operations' => ['platform.directory', 'platform.access.request', 'platform.merchant.view', 'platform.merchant.status', 'platform.verification.review'],
            'platform.finance' => ['platform.directory', 'platform.access.request', 'platform.merchant.view'],
            'platform.support' => ['platform.directory', 'platform.access.request', 'platform.merchant.view'],
        ];
        foreach ($map as $code => $grants) {
            $role = DB::table('roles')->where('code', $code)->value('id');
            $scope = str_starts_with($code, 'platform.') ? 'platform' : 'merchant';
            foreach ($grants as $grant) {
                DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'code' => $grant, 'scope' => $scope]);
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', $grant)->value('id'), 'scope' => $scope]);
            }
        }
    }

    public function down(): void
    {
        foreach (['merchant_verification_decisions', 'merchant_verification_submissions', 'merchant_invitations', 'merchant_settings', 'merchant_addresses', 'merchant_profiles'] as $name) {
            Schema::dropIfExists($name);
        }
        $codes = ['profile.view', 'profile.manage', 'settings.manage', 'addresses.view', 'addresses.manage', 'verification.submit', 'audit.view', 'platform.directory', 'platform.access.approve', 'platform.access.request', 'platform.merchant.view', 'platform.merchant.status', 'platform.verification.review', 'platform.users.manage', 'platform.roles.assign', 'platform.audit.view'];
        $ids = DB::table('permissions')->whereIn('code', $codes)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        DB::statement('ALTER TABLE merchants DROP CONSTRAINT merchant_verification_check, DROP CONSTRAINT merchant_version_check, DROP CONSTRAINT merchant_status_check');
        // Refuse destructive rollback if closed accounts would violate the old schema.
        DB::statement("ALTER TABLE merchants ADD CONSTRAINT merchant_status_check CHECK (status IN ('active','suspended'))");
        Schema::table('merchants', fn (Blueprint $t) => $t->dropColumn(['version', 'verification_status']));
    }
};
