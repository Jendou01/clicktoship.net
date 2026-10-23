<?php

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Operations\Application\PlatformSecurity;
use App\Modules\Pricing\Application\Catalog;
use App\Modules\Pricing\Application\Quotes;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\NullSessionHandler;
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
try {
    $result = DB::transaction(function () use ($d) {
        if (in_array($d['mode'], ['edit', 'revoke'], true)) {
            PlatformSecurity::lock();
            Catalog::lock();
        } else {
            Catalog::lock(true);
            DB::table('merchants')->where('id', $d['merchant'])->lockForUpdate()->first();
        }
        if (isset($d['ready'])) {
            file_put_contents($d['ready'], 'locked');
            $deadline = microtime(true) + 5;
            while (! is_file($d['go']) && microtime(true) < $deadline) {
                usleep(1000);
            }if (! is_file($d['go'])) {
                throw new RuntimeException('Barrier timeout');
            }
        }
        if ($d['mode'] === 'suspend') {
            DB::table('merchants')->where('id', $d['merchant'])->update(['status' => 'suspended']);

            return [];
        }
        if ($d['mode'] === 'revoke') {
            DB::table('auth_sessions')->where('id', $d['session'])->update(['revoked_at' => now()]);

            return [];
        }
        $u = User::findOrFail($d['actor']);
        $r = Request::create('/', 'POST', $d['payload']);
        $r->setUserResolver(fn () => $u);
        if ($d['mode'] === 'edit') {
            $s = new Store('pricing-race', new NullSessionHandler);
            $s->setId($d['raw_session']);
            $s->put('auth_session_id', $d['session']);
            $r->setLaravelSession($s);

            return app(Catalog::class)->write($r, null, $d['definition']);
        }
        $token = PersonalAccessToken::findOrFail($d['token']);
        $u->withAccessToken($token);
        $c = app(MerchantContext::class);
        $c->establish($u, $d['merchant'], $token);

        return app(Quotes::class)->create($r, $c);
    });
    echo json_encode(['status' => 'ok', 'result' => $result]);
} catch (HttpExceptionInterface $e) {
    echo json_encode(['status' => $e->getStatusCode()]);
}
