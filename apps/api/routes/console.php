<?php

use App\Models\User;
use App\Modules\Identity\Application\Audit;
use App\Modules\Operations\Application\PlatformSecurity;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('platform:bootstrap {first : Existing verified MFA-enrolled user UUID} {second : Independent second verified MFA-enrolled user UUID}', function (): void {
    DB::transaction(function (): void {
        PlatformSecurity::lock();
        $ids = [$this->argument('first'), $this->argument('second')];
        if ($ids[0] === $ids[1] || DB::table('platform_role_assignments')->exists()) {
            throw new RuntimeException('Bootstrap requires two independent administrators and an empty platform assignment table.');
        }
        $users = User::whereIn('id', $ids)->whereNull('suspended_at')->whereNotNull('email_verified_at')->orderBy('id')->lockForUpdate()->get();
        if ($users->count() !== 2 || DB::table('user_mfa_credentials')->whereIn('user_id', $ids)->whereNotNull('confirmed_at')->count() !== 2) {
            throw new RuntimeException('Both existing accounts must be verified, active and MFA-enrolled.');
        }
        $role = DB::table('roles')->where('code', 'platform.super_admin')->value('id');
        foreach ($users as $user) {
            DB::table('platform_role_assignments')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'role_id' => $role, 'role_scope' => 'platform', 'created_at' => now()]);
            Audit::record('platform.bootstrap', null, subject: $user->id);
        }
        $this->info('Two existing MFA-enrolled users assigned; no credentials created.');
    });
})->purpose('One-time audited bootstrap of two independent platform administrators');
