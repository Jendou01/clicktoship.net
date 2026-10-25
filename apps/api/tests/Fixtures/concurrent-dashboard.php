<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

foreach (['APP_ENV' => 'testing', 'DB_DATABASE' => 'clicktoship_test', 'DB_URL' => '', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
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
$request = Request::create($d['path'], $d['method'], [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$d['token'], 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '2001:db8:'.bin2hex(random_bytes(2)).'::1'], json_encode($d['body']));
$response = $app->make(HttpKernel::class)->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'data' => json_decode($response->getContent(), true)['data'] ?? null]);
