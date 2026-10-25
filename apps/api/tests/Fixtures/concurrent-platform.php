<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

foreach (['APP_ENV' => 'testing', 'DB_DATABASE' => 'clicktoship_test', 'DB_URL' => '', 'SESSION_DRIVER' => 'redis', 'SESSION_SECURE_COOKIE' => 'false', 'SANCTUM_STATEFUL_DOMAINS' => 'localhost:8080,localhost'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'clicktoship_test') {
    exit(9);
}
$d = json_decode(stream_get_contents(STDIN), true);
$r = Request::create($d['path'], 'GET', [], $d['cookies'], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost:8080', 'REMOTE_ADDR' => '2001:db8:'.bin2hex(random_bytes(2)).'::1']);
$response = $app->make(HttpKernel::class)->handle($r);
echo json_encode(['status' => $response->getStatusCode(), 'has_data' => (json_decode($response->getContent(), true)['data'] ?? null) !== null]);
