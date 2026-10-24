<?php

namespace Tests\Feature;

use App\Models\PersonalAccessToken;
use App\Modules\WalletBilling\Application\Ledger;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ShippingTestCase;

final class ConcurrentShipmentTest extends ShippingTestCase
{
    use DatabaseMigrations;

    private function race(array $a, array $b): array
    {
        $path = sys_get_temp_dir().'/shipping-'.Str::uuid();
        $worker = fn ($d) => (new Process([PHP_BINARY, 'tests/Fixtures/concurrent-shipping.php'], base_path()))->setInput(json_encode($d))->setTimeout(15);
        $first = $worker([...$a, 'ready' => $path, 'go' => $path.'.go']);
        $second = $worker($b);
        try {
            $first->start();
            $deadline = microtime(true) + 8;
            while (! is_file($path) && microtime(true) < $deadline) {
                usleep(1000);
            }$this->assertFileExists($path);
            $second->start();
            do {
                $waiting = DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE datname=current_database() AND wait_event_type='Lock'")->n;
                if (! $waiting) {
                    usleep(1000);
                }
            } while (! $waiting && microtime(true) < $deadline);
            $this->assertGreaterThan(0, (int) $waiting);
            file_put_contents($path.'.go', 'go');
            $first->wait();
            $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());

            return [json_decode($first->getOutput(), true), json_decode($second->getOutput(), true)];
        } finally {
            @unlink($path);
            @unlink($path.'.go');
            if ($first->isRunning()) {
                $first->stop();
            }if ($second->isRunning()) {
                $second->stop();
            }
        }
    }

    private function payload(array $f): array
    {
        return ['merchant' => $f['m'], 'actor' => $f['u']->id, 'token' => PersonalAccessToken::findToken($f['token'])->id, 'account' => $f['gateway']->account, 'shipment' => $f['s']['id'], 'mode' => 'submit'];
    }

    public function test_simultaneous_submissions_have_one_hold_operation_and_outbox(): void
    {
        $f = $this->setupShipment();
        $a = $this->payload($f);
        $r = $this->race($a, $a);
        $this->assertSame(['ok', 'ok'], array_column($r, 'status'));
        $this->assertEquals($r[0]['result'], $r[1]['result']);
        $this->assertDatabaseCount('shipment_submissions', 1);
        $this->assertDatabaseCount('balance_holds', 1);
        $this->assertDatabaseCount('shipping_outbox', 1);
        $this->assertDatabaseHas('wallet_balances', ['reserved' => 2100]);
    }

    public function test_suspension_or_token_revocation_winning_lock_prevents_submission(): void
    {
        $f = $this->setupShipment();
        $a = $this->payload($f);
        $r = $this->race([...$a, 'mode' => 'revoke'], $a);
        $this->assertSame(['ok', 403], array_column($r, 'status'));
        $this->assertDatabaseCount('balance_holds', 0);
    }

    public function test_competing_shipments_cannot_overspend(): void
    {
        $f = $this->setupShipment();
        $a = $this->payload($f);
        $s = $this->bearer($f['token'], 'POST', '/api/v1/shipments', ['draft' => $f['d'], 'idempotency_key' => 'competing-draft'])->assertCreated()->json('data.shipment');
        $this->bearer($f['token'], 'POST', "/api/v1/shipments/{$s['id']}/validate", ['version' => 1])->assertOk();
        $q = $this->bearer($f['token'], 'POST', '/api/v1/rates/quote', $this->quoteInput($f['q']['service_id']))->assertCreated()->json('data');
        $this->bearer($f['token'], 'POST', "/api/v1/shipments/{$s['id']}/quote-acceptance", ['quote_id' => $q['id'], 'version' => 2, 'idempotency_key' => 'competing-quote'])->assertOk();
        app(Ledger::class)->command($f['m'], $f['w'], 'reserve', '7000', 'other', 'other', 'test', expires: now()->addHour()->toIso8601String());
        $r = $this->race($a, [...$a, 'shipment' => $s['id']]);
        $this->assertSame(['ok', '23514'], array_column($r, 'status'));
        $this->assertDatabaseCount('shipment_submissions', 1);
        $this->assertDatabaseHas('wallet_balances', ['posted' => 10000, 'reserved' => 9100]);
    }

    public function test_concurrent_local_finalization_and_external_workers_capture_once(): void
    {
        $f = $this->setupShipment();
        $a = $this->submit($f);
        $d = [...$this->payload($f), 'mode' => 'process', 'submission' => $a['submission_id']];
        $this->race($d, $d);
        $this->assertDatabaseCount('ledger_transactions', 2);
        $this->assertDatabaseCount('shipping_observations', 1);
        $this->assertDatabaseCount('carrier_attempts', 2);
        $this->assertDatabaseHas('wallet_balances', ['posted' => 7900, 'reserved' => 0]);
    }

    public function test_concurrent_rejection_workers_release_once_without_a_charge(): void
    {
        $f = $this->setupShipment();
        $a = $this->submit($f);
        $d = [...$this->payload($f), 'mode' => 'process', 'submission' => $a['submission_id'], 'outcome' => 'rejected'];
        $this->race($d, $d);
        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertDatabaseHas('balance_holds', ['released' => 2100, 'captured' => 0]);
        $this->assertDatabaseHas('wallet_balances', ['posted' => 10000, 'reserved' => 0]);
    }
}
