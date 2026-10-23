<?php

use App\Models\User;
use App\Modules\Carriers\Application\CarrierOperations;
use App\Modules\Merchants\Domain\MerchantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

foreach (['APP_ENV' => 'testing', 'DB_DATABASE' => 'clicktoship_test', 'DB_URL' => ''] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.pgsql.database') !== 'clicktoship_test') {
    exit(9);
}
$d = json_decode(stream_get_contents(STDIN), true);
try {
    $result = DB::transaction(function () use ($d) {
        DB::table('merchants')->where('id', $d['merchant'])->lockForUpdate()->first();
        if (isset($d['ready'])) {
            file_put_contents($d['ready'], 'locked');
            $deadline = microtime(true) + 5;
            while (! is_file($d['go']) && microtime(true) < $deadline) {
                usleep(1000);
            }if (! is_file($d['go'])) {
                throw new RuntimeException('Barrier timeout');
            }
        }
        $c = app(MerchantContext::class);
        $c->establish(User::findOrFail($d['actor']), $d['merchant']);

        return app(CarrierOperations::class)->prepare($c, $d['account'], 'FetchCities', $d['key'], $d['payload']);
    });
    echo json_encode(['status' => 'ok', 'result' => $result]);
} catch (HttpExceptionInterface $e) {
    echo json_encode(['status' => $e->getStatusCode()]);
}
