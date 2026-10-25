<?php

use App\Models\User;
use App\Modules\Analytics\Application\Authority;
use App\Modules\Analytics\Application\Exports;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Shipping\Domain\DocumentScanner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

foreach (['APP_ENV' => 'testing', 'DB_DATABASE' => 'clicktoship_test', 'DB_URL' => '', 'SESSION_DRIVER' => 'redis'] as $k => $v) {
    putenv($k.'='.$v);
    $_ENV[$k] = $_SERVER[$k] = $v;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless(app()->environment('testing') && DB::connection()->getDatabaseName() === 'clicktoship_test', 403);
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
config(['analytics.test_artifacts_enabled' => true, 'filesystems.disks.local.root' => storage_path('framework/testing/disks/local')]);
Queue::fake();
$e = DB::table('report_exports')->where('id', $input['id'])->firstOrFail();
if (($input['action'] ?? 'generate') === 'request') {
    $result = app(Authority::class)->worker($e, function () use ($e) {
        $u = User::findOrFail($e->actor_id);
        $c = app(MerchantContext::class);
        $c->establish($u, $e->merchant_id);
        $permissions = $c->permissions();
        $request = Request::create('/', 'POST', ['report' => 'shipment_summary', 'format' => 'csv', 'locale' => 'en', 'idempotency_key' => 'independent-request-same-key']);
        $request->setUserResolver(fn () => $u);
        $store = new Store('fixture', new ArraySessionHandler(120));
        $store->put('auth_session_id', $e->origin_session_id);
        $request->setLaravelSession($store);
        try {
            return app(Exports::class)->request($request, 'merchant', $e->merchant_id, $permissions);
        } finally {
            $c->clear();
        }
    });
    echo json_encode(['id' => $result['export']['id']]);
} else {
    app()->instance(DocumentScanner::class, new class((bool) ($input['block'] ?? false)) implements DocumentScanner
    {
        public function __construct(private bool $block) {}

        public function clean(string $bytes): bool
        {
            if ($this->block) {
                abort_unless(DB::transactionLevel() === 0, 500);
                DB::statement("SET application_name='report-race-scan'");
                DB::select('SELECT pg_advisory_lock(1500016)');
                DB::select('SELECT pg_advisory_unlock(1500016)');
            }

            return true;
        }
    });
    app(Exports::class)->tick($e->id);
    echo json_encode(['state' => DB::table('report_exports')->where('id', $e->id)->value('state')]);
}
