<?php

use App\Models\User;
use App\Modules\Operations\Application\PlatformSecurity;
use App\Modules\WalletBilling\Application\FinancialAdministration;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
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
config(['finance.corrections_enabled' => true]);
try {
    DB::transaction(function () use ($d) {
        if ($d['mode'] === 'execute') {
            $r = Request::create('/api/v1/admin/finance', 'POST', ['access_grant' => $d['grant'], 'version' => 2, 'request_key' => 'race-execution-key-0001']);
            $r->setUserResolver(fn () => User::findOrFail($d['actor']));
            $store = new Store('race', new ArraySessionHandler(120));
            $store->setId($d['raw_session']);
            $store->put('auth_session_id', $d['session']);
            $r->setLaravelSession($store);
            app(FinancialAdministration::class)->change($r, $d['merchant'], $d['approval'], 'execute');

            return;
        }
        if ($d['mode'] === 'approval') {
            PlatformSecurity::lock();
            DB::table('merchants')->where('id', $d['merchant'])->lockForUpdate()->first();
        } else {
            User::whereKey($d['actor'])->lockForUpdate()->first();
        }
        file_put_contents($d['ready'], 'locked');
        $deadline = microtime(true) + 5;
        while (! is_file($d['go']) && microtime(true) < $deadline) {
            usleep(1000);
        }if (! is_file($d['go'])) {
            throw new RuntimeException('Barrier timeout');
        }
        if ($d['mode'] === 'mfa') {
            DB::table('user_mfa_credentials')->where('user_id', $d['actor'])->increment('version');
        } elseif ($d['mode'] === 'credential') {
            DB::table('users')->where('id', $d['actor'])->increment('auth_version');
        } else {
            DB::table('financial_approvals')->where('id', $d['approval'])->update(['revoked_at' => now(), 'version' => 3]);
        }
    });
    echo json_encode(['status' => 'ok']);
} catch (HttpExceptionInterface $e) {
    echo json_encode(['status' => $e->getStatusCode()]);
}
