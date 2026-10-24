<?php

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Modules\Carriers\Domain\ShipmentGateway;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Pricing\Application\Catalog;
use App\Modules\Shipping\Application\Submission;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\SyntheticShipmentGateway;

foreach (['APP_ENV' => 'testing', 'DB_DATABASE' => 'clicktoship_test', 'DB_URL' => ''] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.pgsql.database') !== 'clicktoship_test') {
    exit(9);
} $d = json_decode(stream_get_contents(STDIN), true);
try {
    $gateway = new SyntheticShipmentGateway($d['account']);
    $gateway->outcome = $d['outcome'] ?? 'accepted';
    app()->instance(ShipmentGateway::class, $gateway);
    // The barrier controls commit order, not an arbitrary timing delay.
    if (isset($d['ready'])) {
        DB::beginTransaction();
        Catalog::lock(true);
        DB::table('merchants')->where('id', $d['merchant'])->lockForUpdate()->first();
        file_put_contents($d['ready'], 'locked');
        $deadline = microtime(true) + 5;
        while (! is_file($d['go']) && microtime(true) < $deadline) {
            usleep(1000);
        } if (! is_file($d['go'])) {
            throw new RuntimeException('Barrier timeout');
        }
    }
    if ($d['mode'] === 'revoke') {
        DB::table('personal_access_tokens')->where('id', $d['token'])->delete();
        $result = [];
    } elseif ($d['mode'] === 'process') {
        // Release the fixture barrier before HTTP: application processing must commit its own dispatch marker.
        if (DB::transactionLevel()) {
            DB::commit();
        }
        app(Submission::class)->process($d['merchant'], $d['submission']);
        $result = [];
    } else {
        $token = PersonalAccessToken::find($d['token']);
        abort_unless($token, 403);
        $u = User::findOrFail($d['actor']);
        $u->withAccessToken($token);
        $c = app(MerchantContext::class);
        $c->establish($u, $d['merchant'], $token);
        $r = Request::create('/api/v1/shipments', 'POST', ['version' => 2, 'idempotency_key' => 'concurrent-shipment']);
        $r->setUserResolver(fn () => $u);
        $result = app(Submission::class)->create($r, $c, $d['shipment']);
    }
    if (DB::transactionLevel()) {
        DB::commit();
    } echo json_encode(['status' => 'ok', 'result' => $result]);
} catch (HttpExceptionInterface $e) {
    if (DB::transactionLevel()) {
        DB::rollBack();
    } echo json_encode(['status' => $e->getStatusCode()]);
} catch (QueryException $e) {
    if (DB::transactionLevel()) {
        DB::rollBack();
    } echo json_encode(['status' => $e->getCode()]);
}
