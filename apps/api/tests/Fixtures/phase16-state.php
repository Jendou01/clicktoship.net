<?php

use App\Models\User;
use App\Modules\Identity\Application\Audit;
use App\Modules\Merchants\Application\MerchantService;
use App\Modules\WalletBilling\Application\Ledger;
use App\Support\SchemaIntegrity;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection()->getDatabaseName();
if (! app()->environment('testing') || ! in_array($db, ['clicktoship_e2e', 'clicktoship_restore_test'], true)) {
    exit(9);
}
$mode = $argv[1] ?? 'manifest';
if ($mode !== 'health' && DB::selectOne('SELECT current_database() AS name')->name !== $db) {
    exit(9);
}
if ($mode === 'guard') {
    echo json_encode(['environment' => 'testing', 'database' => $db]);
    exit;
}
if ($mode === 'seed') {
    if ($db !== 'clicktoship_e2e') {
        exit(9);
    }
    $u = new User;
    $u->forceFill(['name' => 'QA restore synthetic', 'email' => 'restore-'.Str::uuid().'@example.test', 'password' => bin2hex(random_bytes(32)), 'email_verified_at' => now()])->save();
    $m = app(MerchantService::class)->create($u, 'QA RESTORE SYNTHETIC ONLY');
    $wallet = app(Ledger::class)->provision($m->id);
    app(Ledger::class)->command($m->id, $wallet, 'synthetic', '123456', 'phase16-restore-synthetic', 'phase16-restore-request', 'TEST DATABASE ONLY - NOT A PAYMENT');
    Audit::record('qa.restore.synthetic', $u->id, $m->id);
    echo '{"synthetic_fixture":true}';
    exit;
}
if ($mode === 'health') {
    $request = Request::create('/api/v1/health/ready', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    echo json_encode(['status' => $response->getStatusCode()]);
    exit;
}
$tables = [];
foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $t) {
    $count = DB::table($t->tablename)->count();
    if ($count > 20000) {
        throw new RuntimeException('QA_SOURCE_SIZE_LIMIT');
    }
    $hash = hash_init('sha256');
    foreach (DB::select('SELECT to_jsonb(t)::text AS value FROM "'.str_replace('"', '""', $t->tablename).'" AS t ORDER BY to_jsonb(t)::text') as $row) {
        hash_update($hash, $row->value."\n");
    }
    $tables[$t->tablename] = ['rows' => $count, 'sha256' => hash_final($hash)];
}
$decrypt = 0;
$wrongKeyRejected = false;
foreach (DB::table('shipment_revisions')->limit(10)->pluck('payload') as $cipher) {
    Crypt::decryptString($cipher);
    $decrypt++;
    try {
        (new Encrypter(random_bytes(32), 'AES-256-CBC'))->decryptString($cipher);
    } catch (DecryptException) {
        $wrongKeyRejected = true;
    }
}
$imbalanced = DB::select("SELECT transaction_id,currency FROM ledger_entries GROUP BY transaction_id,currency HAVING sum(CASE WHEN side='debit' THEN amount::numeric ELSE -amount::numeric END)<>0");
if ($imbalanced || app(SchemaIntegrity::class)->failures() || ! $decrypt || ! $wrongKeyRejected || ! DB::table('ledger_transactions')->exists()) {
    throw new RuntimeException('QA_RESTORE_INVARIANT_FAILED');
}
echo json_encode(['tables' => $tables, 'decrypted_samples' => $decrypt, 'wrong_key_rejected' => $wrongKeyRejected, 'balanced_ledger' => true, 'schema_valid' => true], JSON_THROW_ON_ERROR);
