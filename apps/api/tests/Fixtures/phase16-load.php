<?php

use App\Models\User;
use App\Modules\Merchants\Application\MerchantService;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Shipping\Application\Shipments;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'clicktoship_e2e') {
    exit(9);
}
if (DB::selectOne('SELECT current_database() AS name')->name !== 'clicktoship_e2e') {
    exit(9);
}
$result = ['environment' => 'testing', 'database' => 'clicktoship_e2e', 'expires_at' => time() + 900, 'accounts' => []];
DB::transaction(function () use (&$result) {
    for ($i = 0; $i < 4; $i++) {
        $password = 'Ab1!'.bin2hex(random_bytes(20));
        $u = new User;
        $u->forceFill(['name' => 'QA synthetic load', 'email' => 'qa-load-'.Str::uuid().'@example.test', 'password' => $password, 'email_verified_at' => now()])->save();
        $merchant = app(MerchantService::class)->create($u, 'QA synthetic load '.$i);
        $second = app(MerchantService::class)->create($u, 'QA synthetic switch '.$i);
        $token = $u->createToken('QA bounded load only', ['merchant.view', 'shipments.read', 'shipments.write', 'rates.read', 'analytics.read', 'orders.read', 'tracking.read'], now()->addMinutes(15));
        $token->accessToken->forceFill(['merchant_id' => $merchant->id, 'auth_version' => $u->auth_version])->save();
        $u->withAccessToken($token->accessToken);
        $draft = ['currency' => 'SAR', 'description' => 'QA synthetic draft only', 'origin' => ['country' => 'SA', 'city' => 'synthetic origin'], 'destination' => ['country' => 'SA', 'city' => 'synthetic destination'], 'packages' => [['weight_grams' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100]], 'declared_value_minor' => '0', 'cod_minor' => '0', 'items' => []];
        $c = app(MerchantContext::class);
        try {
            for ($n = 0; $n < 50; $n++) {
                $c->establish($u, $merchant->id, $token->accessToken);
                $r = Request::create('/internal/qa-draft', 'POST', ['draft' => $draft, 'idempotency_key' => (string) Str::uuid()]);
                $r->setUserResolver(fn () => $u);
                app(Shipments::class)->write($r, $c);
            }
        } finally {
            $c->clear();
        }
        $result['accounts'][] = ['token' => $token->plainTextToken, 'draft' => $draft, 'email' => $u->email, 'password' => $password, 'merchants' => [$merchant->id, $second->id]];
    }
});
echo json_encode($result, JSON_THROW_ON_ERROR);
