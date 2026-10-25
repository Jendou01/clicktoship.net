<?php

use App\Modules\Commerce\Application\Drafts;
use App\Modules\Commerce\Application\Tokens;
use App\Modules\Commerce\Domain\IntegrationFailure;
use App\Modules\Commerce\Domain\StoreProvider;
use App\Modules\Pricing\Application\Catalog;
use App\Modules\Shipping\Application\ShippingAccess;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SyntheticStoreProvider;

foreach (['APP_ENV' => 'testing', 'DB_DATABASE' => 'clicktoship_test', 'DB_URL' => '', 'SESSION_DRIVER' => 'redis', 'SESSION_SECURE_COOKIE' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'clicktoship_test') {
    echo json_encode(['guard' => 'environment_or_database', 'environment' => app()->environment(), 'database' => DB::connection()->getDatabaseName()]);
    exit(9);
}
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$provider = new SyntheticStoreProvider;
app()->instance(StoreProvider::class, $provider);
$origin = DB::table('commerce_runs')->where('id', $input['run'])->where('merchant_id', $input['merchant'])->first();
if (! $origin) {
    echo json_encode(['guard' => 'origin_missing']);
    exit(9);
}
try {
    if ($input['action'] === 'refresh') {
        $provider->onRefresh = function () {
            DB::statement("SET application_name='commerce-race-refresh'");
            DB::select('SELECT pg_advisory_lock(13132026)');
            DB::select('SELECT pg_advisory_unlock(13132026)');
        };
        app(Tokens::class)->credential($origin);
        $result = ['ok' => true];
    } else {
        $result = DB::transaction(function () use ($origin, $input) {
            Catalog::lock(true);
            DB::table('merchants')->where('id', $origin->merchant_id)->lockForUpdate()->firstOrFail();
            app(ShippingAccess::class)->worker($origin, 'orders.prepare');
            app(ShippingAccess::class)->worker($origin, 'orders.read');
            app(ShippingAccess::class)->worker($origin, 'shipments.write');

            return app(Drafts::class)->persist($origin, $input['order'], $input['data']);
        });
    }
    echo json_encode($result);
} catch (IntegrationFailure $e) {
    echo json_encode(['code' => $e->reason]);
} catch (HttpException $e) {
    echo json_encode(['status' => $e->getStatusCode()]);
}
