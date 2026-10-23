<?php

use App\Modules\Identity\Domain\RoleCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('suspended_at')->nullable();
            $table->unsignedInteger('auth_version')->default(1);
            $table->string('locale', 2)->default('en');
        });
        DB::statement('CREATE UNIQUE INDEX users_email_case_insensitive_unique ON users (lower(email))');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_locale_check CHECK (locale IN ('en','ar'))");
        Schema::create('merchants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('status', 20)->default('active');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE merchants ADD CONSTRAINT merchant_status_check CHECK (status IN ('active','suspended'))");
        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 80)->unique();
            $table->string('name', 80);
            $table->string('scope', 20);
            $table->unique(['id', 'scope']);
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 80)->unique();
            $table->string('scope', 20);
            $table->unique(['id', 'scope']);
        });
        foreach (['roles', 'permissions'] as $name) {
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_scope_check CHECK (scope IN ('merchant','platform'))");
        }
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->uuid('role_id');
            $table->uuid('permission_id');
            $table->string('scope', 20);
            $table->primary(['role_id', 'permission_id']);
            $table->foreign(['role_id', 'scope'])->references(['id', 'scope'])->on('roles')->restrictOnDelete();
            $table->foreign(['permission_id', 'scope'])->references(['id', 'scope'])->on('permissions')->restrictOnDelete();
        });
        Schema::create('merchant_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->unique(['merchant_id', 'user_id']);
            $table->unique(['merchant_id', 'id']);
            $table->index(['user_id', 'status']);
        });
        DB::statement("ALTER TABLE merchant_users ADD CONSTRAINT membership_status_check CHECK (status IN ('active','suspended','removed'))");
        DB::statement('ALTER TABLE merchant_users ADD CONSTRAINT membership_version_check CHECK (version > 0)');
        Schema::create('merchant_role_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id');
            $table->uuid('membership_id')->unique();
            $table->uuid('role_id');
            $table->string('role_scope', 20)->default('merchant');
            $table->foreign(['merchant_id', 'membership_id'])->references(['merchant_id', 'id'])->on('merchant_users')->restrictOnDelete();
            $table->foreign(['role_id', 'role_scope'])->references(['id', 'scope'])->on('roles')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE merchant_role_assignments ADD CONSTRAINT merchant_role_scope_check CHECK (role_scope = 'merchant')");
        Schema::create('platform_role_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->uuid('role_id');
            $table->string('role_scope', 20)->default('platform');
            $table->foreignUuid('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['user_id', 'role_id']);
            $table->foreign(['role_id', 'role_scope'])->references(['id', 'scope'])->on('roles')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE platform_role_assignments ADD CONSTRAINT platform_role_scope_check CHECK (role_scope = 'platform')");
        Schema::create('auth_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->string('session_hash', 64)->unique();
            $table->unsignedInteger('auth_version');
            $table->timestampTz('created_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->index(['user_id', 'revoked_at']);
        });
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignUuid('merchant_id')->nullable()->constrained()->restrictOnDelete();
            // Existing foundation tokens cannot silently acquire tenant privileges.
            $table->unsignedInteger('auth_version')->default(0);
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('request_id')->nullable();
            $table->string('action', 80);
            $table->uuid('subject_id')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at');
            $table->index(['merchant_id', 'created_at']);
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION forbid_audit_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Audit history is append-only'; END;
$$;
CREATE TRIGGER immutable_audit_logs BEFORE UPDATE OR DELETE ON audit_logs
FOR EACH ROW EXECUTE FUNCTION forbid_audit_mutation();
SQL);
        $permissions = [];
        foreach (RoleCatalogue::definitions() as $code => [$name, $grants]) {
            $scope = explode('.', $code)[0];
            $roleId = (string) Str::uuid();
            DB::table('roles')->insert(['id' => $roleId, 'code' => $code, 'name' => $name, 'scope' => $scope]);
            foreach ($grants as $permission) {
                if (! isset($permissions[$permission])) {
                    $permissions[$permission] = (string) Str::uuid();
                    DB::table('permissions')->insert(['id' => $permissions[$permission], 'code' => $permission, 'scope' => $scope]);
                }
                DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissions[$permission], 'scope' => $scope]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        DB::statement('DROP FUNCTION IF EXISTS forbid_audit_mutation()');
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('merchant_id');
            $table->dropColumn('auth_version');
        });
        foreach (['auth_sessions', 'platform_role_assignments', 'merchant_role_assignments', 'merchant_users', 'role_permissions', 'permissions', 'roles', 'merchants'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP INDEX users_email_case_insensitive_unique');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_locale_check');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['suspended_at', 'auth_version', 'locale']));
    }
};
