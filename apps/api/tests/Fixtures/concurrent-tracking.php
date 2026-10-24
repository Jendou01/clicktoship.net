<?php

use App\Modules\Tracking\Application\TrackingPollScheduler;
use App\Modules\Tracking\Domain\TrackingProviderAdapter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticTrackingAdapter;

foreach (['APP_ENV' => 'testing', 'DB_DATABASE' => 'clicktoship_test', 'DB_URL' => ''] as $k => $v) {
    putenv($k.'='.$v);
    $_ENV[$k] = $_SERVER[$k] = $v;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'clicktoship_test') {
    exit(9);
}
app()->instance(TrackingProviderAdapter::class, new SyntheticTrackingAdapter);
$d = json_decode(stream_get_contents(STDIN), true);
try {
    if (isset($d['ready'])) {
        DB::beginTransaction();
        DB::table('merchants')->where('id', $d['merchant'])->lockForUpdate()->first();
        file_put_contents($d['ready'], 'locked');
        $until = microtime(true) + 10;
        while (! is_file($d['go']) && microtime(true) < $until) {
            usleep(1000);
        }if (! is_file($d['go'])) {
            throw new RuntimeException('Barrier timeout');
        }DB::commit();
    }app(TrackingPollScheduler::class)->tick($d['merchant'], $d['work']);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage());
    exit(1);
}
