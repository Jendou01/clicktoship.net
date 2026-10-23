<?php

use App\Modules\WalletBilling\Application\Ledger;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

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
            }
            if (! is_file($d['go'])) {
                throw new RuntimeException('Barrier timeout');
            }
        }
        if (($d['mode'] ?? 'command') === 'suspend') {
            DB::table('merchants')->where('id', $d['merchant'])->update(['status' => 'suspended']);

            return [];
        }

        $command = app(Ledger::class)->command($d['merchant'], $d['wallet'], $d['kind'], $d['amount'], $d['business'], $d['request'], 'test', $d['reference'] ?? null, $d['expires'] ?? null);
        if (($d['crash'] ?? null) === 'before') {
            exit(0);
        }

        return $command;
    });
    if (($d['crash'] ?? null) === 'after') {
        exit(0);
    }
    echo json_encode(['status' => 'ok', 'result' => $result]);
} catch (PDOException $e) {
    echo json_encode(['status' => $e->getCode()]);
}
