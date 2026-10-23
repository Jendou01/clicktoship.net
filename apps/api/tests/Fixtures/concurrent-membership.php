<?php

use App\Models\User;
use App\Modules\Merchants\Application\TeamService;
use App\Modules\Merchants\Domain\MerchantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// Isolated integration worker; never accepts credentials or a database target from input.
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
$input = json_decode(stream_get_contents(STDIN), true);
$context = app(MerchantContext::class);
try {
    $context->establish(User::findOrFail($input['actor']), $input['merchant']);
    DB::transaction(function () use ($input): void {
        if (isset($input['ready'])) {
            DB::table('merchants')->where('id', $input['merchant'])->lockForUpdate()->first();
            file_put_contents($input['ready'], 'locked');
            usleep(700000);
        }
        app(TeamService::class)->update($input['target'], $input['change']);
    });
    echo json_encode(['status' => 200]);
} catch (HttpExceptionInterface $error) {
    echo json_encode(['status' => $error->getStatusCode()]);
} finally {
    $context->clear();
}
