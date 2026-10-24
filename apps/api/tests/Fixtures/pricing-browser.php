<?php

use App\Models\User;
use App\Modules\Pricing\Domain\Configuration;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OTPHP\TOTP;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'clicktoship_e2e') {
    exit(9);
}
$accounts = [];
DB::transaction(function () use (&$accounts) {
    foreach (['pricingOwner', 'qaOwner', 'previewOwner', 'adminE', 'adminF'] as $key) {
        $email = strtolower($key).'@browser-fixture.example.test';
        $password = 'Ab1!'.bin2hex(random_bytes(20));
        $u = User::where('email', $email)->first() ?? new User;
        $u->forceFill(['name' => 'Synthetic pricing browser '.$key, 'email' => $email, 'password' => $password, 'email_verified_at' => now(), 'suspended_at' => null, 'locale' => 'en', 'auth_version' => ($u->auth_version ?? 0) + 1])->save();
        DB::table('auth_sessions')->where('user_id', $u->id)->update(['revoked_at' => now()]);
        $u->tokens()->delete();
        $accounts[$key] = ['id' => $u->id, 'email' => $email, 'password' => $password];
        if (str_starts_with($key, 'admin')) {
            DB::table('user_mfa_credentials')->updateOrInsert(['user_id' => $u->id], ['secret' => Crypt::encryptString(TOTP::generate()->getSecret()), 'confirmed_at' => now(), 'last_step' => -1, 'version' => 1]);
            DB::table('mfa_recovery_codes')->where('user_id', $u->id)->delete();
            $codes = [];
            for ($i = 0; $i < 8; $i++) {
                $code = bin2hex(random_bytes(16));
                $codes[] = $code;
                DB::table('mfa_recovery_codes')->insert(['id' => (string) Str::uuid(), 'user_id' => $u->id, 'code_hash' => hash('sha256', $code)]);
            }
            $accounts[$key]['recovery_codes'] = $codes;
            DB::table('platform_role_assignments')->insertOrIgnore(['id' => (string) Str::uuid(), 'user_id' => $u->id, 'role_id' => DB::table('roles')->where('code', 'platform.super_admin')->value('id'), 'role_scope' => 'platform', 'created_at' => now()]);
        }
    }
    $row = function (string $kind, string $code, array $c, ?string $currency = null) use (&$accounts) {
        $code = 'browser-synthetic-'.$code;
        $existing = DB::table('pricing_definitions')->where('kind', $kind)->where('code', $code)->first();
        $id = $existing->id ?? (string) Str::uuid();
        $version = ($existing->version ?? 0) + 1;
        if (! $existing) {
            DB::table('pricing_definitions')->insert(['id' => $id, 'kind' => $kind, 'code' => $code]);
        }
        $c = ['name_en' => 'Synthetic pricing test only', 'name_ar' => 'تسعير اختبار فقط', 'evidence' => 'Synthetic browser fixture; NOT a carrier contract or real tax rate.'] + $c;
        Configuration::validate($kind, $c, null);
        DB::table('pricing_versions')->insert(['definition_id' => $id, 'version' => $version, 'configuration' => json_encode($c), 'currency' => $currency, 'effective_from' => now()->subHour(), 'effective_to' => now()->addDays(2), 'created_by' => $accounts['adminE']['id']]);
        DB::table('pricing_definitions')->where('id', $id)->update(['version' => $version, 'active_version' => $version, 'enabled' => true]);

        return $id;
    };
    $service = $row('service', 'service', ['carrier' => 'synthetic_browser_only', 'volumetric_divisor' => 5000, 'weight_step_grams' => 500, 'weight_mode' => 'aggregate', 'ttl_seconds' => 300, 'rounding' => 'half_up', 'capabilities' => ['cod' => false, 'remote' => false, 'declared_value' => false]]);
    $row('zone', 'zone', ['country' => 'SA', 'cities' => ['synthetic origin', 'synthetic destination'], 'remote' => false]);
    $tax = $row('tax', 'tax', ['country' => 'SA', 'jurisdiction' => 'origin', 'mode' => 'exempt', 'percent_bps' => 0]);
    $selectors = ['service_id' => $service, 'origin_country' => 'SA', 'destination_country' => 'SA', 'lane' => 'domestic', 'weight_from' => 0, 'weight_to' => 1000000001, 'packages_from' => 1, 'packages_to' => 100];
    $amounts = ['base_minor' => '1000', 'fixed_minor' => '0', 'percent_bps' => 0, 'included_grams' => 1000, 'step_grams' => 1000, 'step_minor' => '100'];
    $row('tariff', 'tariff', $selectors + $amounts, 'SAR');
    $row('rule', 'rule', $selectors + array_replace($amounts, ['base_minor' => '2000']) + ['scope' => 'global', 'mode' => 'fixed', 'tier_id' => null, 'discount_minor' => '0', 'discount_bps' => 0, 'min_margin_minor' => '100', 'min_margin_bps' => 100, 'tax_id' => $tax], 'SAR');
    $accounts['service'] = $service;
});
echo json_encode($accounts, JSON_THROW_ON_ERROR);
