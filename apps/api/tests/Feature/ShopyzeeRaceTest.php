<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ShopyzeeTestCase;

final class ShopyzeeRaceTest extends ShopyzeeTestCase
{
    use DatabaseMigrations;

    private function process(array $data): Process
    {
        return (new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent-commerce.php')], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'clicktoship_test', 'SESSION_DRIVER' => 'redis']))->setInput(json_encode($data))->setTimeout(20);
    }

    public function test_independent_refresh_competitors_use_one_rotation_and_a_bounded_busy_result(): void
    {
        $f = $this->setupShopyzee();
        $run = $this->browser('POST', "/api/v1/commerce/connections/{$f['connection']}/sync", ['idempotency_key' => 'refresh-race-run'])->assertStatus(202)->json('data.run_id');
        DB::table('commerce_credentials')->update(['expires_at' => now()->subSecond()]);
        $data = ['action' => 'refresh', 'merchant' => $f['m'], 'run' => $run];
        $a = $this->process($data);
        $b = $this->process($data);
        DB::select('SELECT pg_advisory_lock(13132026)');
        try {
            $a->start();
            $deadline = microtime(true) + 8;
            $waiting = false;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = (bool) DB::selectOne("SELECT 1 FROM pg_stat_activity WHERE datname=current_database() AND application_name='commerce-race-refresh' AND wait_event_type='Lock'");
                if ($waiting) {
                    break;
                }usleep(1000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($waiting, 'Independent provider refresh must be held outside merchant transaction '.$a->getOutput().' '.$a->getErrorOutput().$a->getOutput());
            $b->run();
            $this->assertTrue($b->isSuccessful(), $b->getErrorOutput().$b->getOutput());
            $this->assertSame('REFRESH_BUSY', json_decode($b->getOutput(), true)['code']);
        } finally {
            DB::select('SELECT pg_advisory_unlock(13132026)');
        } $a->wait();
        $this->assertTrue($a->isSuccessful(), $a->getErrorOutput().$a->getOutput());
        $this->assertTrue(json_decode($a->getOutput(), true)['ok']);
        $this->assertSame(1, DB::table('commerce_events')->where('kind', 'credentials_rotated')->count());
    }

    public function test_two_independent_draft_commands_commit_one_draft_and_permanent_source_link(): void
    {
        $f = $this->setupShopyzee();
        $o = $this->import($f, [$this->order()])[0];
        $review = $this->review($f, $o);
        $run = $this->browser('POST', "/api/v1/commerce/connections/{$f['connection']}/prepare", ['selections' => [['order_id' => $o->id] + $review], 'idempotency_key' => 'draft-race-run'])->assertStatus(202)->json('data.run_id');
        $data = ['action' => 'draft', 'merchant' => $f['m'], 'run' => $run, 'order' => $o->id, 'data' => $review];
        $a = $this->process($data);
        $b = $this->process($data);
        $a->start();
        $b->start();
        $a->wait();
        $b->wait();
        $this->assertTrue($a->isSuccessful(), $a->getErrorOutput().$a->getOutput());
        $this->assertTrue($b->isSuccessful(), $b->getErrorOutput().$b->getOutput());
        $x = json_decode($a->getOutput(), true);
        $y = json_decode($b->getOutput(), true);
        $this->assertSame($x['shipment_id'], $y['shipment_id']);
        $states = [$x['state'], $y['state']];
        sort($states);
        $this->assertSame(['created', 'existing'], $states);
        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseCount('commerce_draft_links', 1);
        $this->assertDatabaseCount('ledger_transactions', 0);
        $this->assertDatabaseCount('carrier_operations', 0);
    }
}
