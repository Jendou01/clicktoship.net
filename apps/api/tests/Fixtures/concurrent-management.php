<?php

use App\Models\User;
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

use App\Modules\Merchants\Application\Management;
use App\Modules\Merchants\Application\TeamService;
use App\Modules\Operations\Application\Administration;
use App\Modules\Operations\Application\PlatformSecurity;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Validation\ValidationException;

try {
    DB::transaction(function () use ($input): void {
        if (in_array($input['mode'], ['role', 'userstatus'], true)) {
            PlatformSecurity::lock();
            if ($input['mode'] === 'userstatus') {
                User::whereKey($input['target'])->lockForUpdate()->firstOrFail();
            }
        } else {
            DB::table('merchants')->where('id', $input['merchant'])->lockForUpdate()->first();
        }
        if (isset($input['ready'])) {
            file_put_contents($input['ready'], 'locked');
            usleep(500000);
        }
        $r = Request::create('/api/v1/test', 'PATCH', $input['change']);
        $r->setUserResolver(fn () => User::findOrFail($input['actor']));
        if (in_array($input['mode'], ['role', 'userstatus'], true)) {
            $session = new Store('test', new ArraySessionHandler(120));
            $session->put('auth_session_id', $input['session']);
            $r->setLaravelSession($session);
            if ($input['mode'] === 'role') {
                app(Administration::class)->role($r, $input['target']);
            } else {
                app(Administration::class)->userStatus($r, $input['target']);
            }
        } else {
            app(MerchantContext::class)->establish(User::findOrFail($input['actor']), $input['merchant']);
            if ($input['mode'] === 'add') {
                app(TeamService::class)->add($input['change']);
            } else {
                app(Management::class)->settings($r, true);
            }
        }
    });
    echo json_encode(['status' => 200]);
} catch (ValidationException $e) {
    echo json_encode(['status' => 422]);
} catch (HttpExceptionInterface $e) {
    echo json_encode(['status' => $e->getStatusCode()]);
} finally {
    app(MerchantContext::class)->clear();
}
