<?php

use App\Models\User;
use App\Modules\Identity\Application\Audit;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OTPHP\TOTP;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// No runtime HTTP route exposes this fixture. Refuse every normal development/production DB.
if (! app()->environment('testing') || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'clicktoship_e2e') {
    exit(9);
}
$accounts = [];
DB::transaction(function () use (&$accounts) {
    foreach (['adminA', 'adminB', 'owner', 'member'] as $key) {
        $email = strtolower($key).'@browser-fixture.example.test';
        $password = 'Ab1!'.bin2hex(random_bytes(20));
        $u = User::where('email', $email)->first() ?? new User;
        $u->forceFill(['name' => 'Browser fixture '.$key, 'email' => $email, 'password' => $password, 'email_verified_at' => now(), 'suspended_at' => null, 'locale' => 'en', 'auth_version' => ($u->auth_version ?? 0) + 1])->save();
        DB::table('auth_sessions')->where('user_id', $u->id)->update(['revoked_at' => now()]);
        $u->tokens()->delete();
        $accounts[$key] = ['id' => $u->id, 'email' => $email, 'password' => $password];
        if (! str_starts_with($key, 'admin')) {
            DB::table('mfa_recovery_codes')->where('user_id', $u->id)->delete();
            DB::table('user_mfa_credentials')->where('user_id', $u->id)->delete();
        }
        if (str_starts_with($key, 'admin')) {
            $secret = TOTP::generate()->getSecret();
            DB::table('user_mfa_credentials')->updateOrInsert(['user_id' => $u->id], ['secret' => Crypt::encryptString($secret), 'confirmed_at' => now(), 'last_step' => -1, 'version' => 1]);
            DB::table('mfa_recovery_codes')->where('user_id', $u->id)->delete();
            $codes = [];
            for ($i = 0; $i < 8; $i++) {
                $c = bin2hex(random_bytes(16));
                $codes[] = $c;
                DB::table('mfa_recovery_codes')->insert(['id' => (string) Str::uuid(), 'user_id' => $u->id, 'code_hash' => hash('sha256', $c)]);
            }
            $accounts[$key]['recovery_codes'] = $codes;
        }
    }
    if (! DB::table('platform_role_assignments')->exists()) {
        Artisan::call('platform:bootstrap', ['first' => $accounts['adminA']['id'], 'second' => $accounts['adminB']['id']]);
    }
    foreach (['adminA', 'adminB'] as $key) {
        if (! DB::table('platform_role_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')->where('a.user_id', $accounts[$key]['id'])->where('r.code', 'platform.super_admin')->exists()) {
            throw new RuntimeException('Fixture administrator role missing.');
        }
    }
    Audit::record('testing.browser_fixtures_prepared', null);
});
echo json_encode($accounts, JSON_THROW_ON_ERROR);
