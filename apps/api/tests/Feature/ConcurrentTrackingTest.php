<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\TrackingTestCase;

final class ConcurrentTrackingTest extends TrackingTestCase
{
    use DatabaseMigrations;

    private function worker(array $f, string $work): Process
    {
        return (new Process([PHP_BINARY, 'tests/Fixtures/concurrent-tracking.php'], base_path()))->setInput(json_encode(['merchant' => $f['m'], 'work' => $work]))->setTimeout(20);
    }

    private function blocked(int $n): void
    {
        $until = microtime(true) + 8;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            if ((int) DB::selectOne("SELECT count(*) n FROM pg_stat_activity WHERE datname=current_database() AND wait_event_type='Lock'")->n >= $n) {
                return;
            }usleep(1000);
        } while (microtime(true) < $until);
        $this->fail('Missing PostgreSQL lock waiters');
    }

    public function test_duplicate_workers_commit_one_event_and_projection(): void
    {
        $f = $this->trackingFixture();
        $ep = $this->endpoint($f);
        $work = $this->inbound($ep, $this->envelope($f))->assertStatus(202)->json('data.receipt');
        DB::table('tracking_work')->where('id', $work)->update(['available_at' => now()->subSecond()]);
        DB::beginTransaction();
        DB::table('merchants')->where('id', $f['m'])->lockForUpdate()->first();
        $a = $this->worker($f, $work);
        $b = $this->worker($f, $work);
        try {
            $a->start();
            $b->start();
            $this->blocked(2);
            DB::commit();
            $a->wait();
            $b->wait();
            $this->assertTrue($a->isSuccessful(), $a->getErrorOutput());
            $this->assertTrue($b->isSuccessful(), $b->getErrorOutput());
            $this->assertDatabaseCount('carrier_tracking_events', 1);
            $this->assertDatabaseCount('tracking_event_details', 1);
            $this->assertDatabaseHas('tracking_work', ['id' => $work, 'state' => 'completed']);
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }if ($a->isRunning()) {
                $a->stop();
            }if ($b->isRunning()) {
                $b->stop();
            }
        }
    }

    public function test_revocation_committed_before_worker_lock_blocks_poll(): void
    {
        $f = $this->trackingFixture();
        $work = $this->refreshTracking($f)->assertStatus(202)->json('data.refreshes.0.work_id');
        DB::table('tracking_work')->where('id', $work)->update(['available_at' => now()->subSecond()]);
        DB::beginTransaction();
        DB::table('merchants')->where('id', $f['m'])->lockForUpdate()->first();
        $a = $this->worker($f, $work);
        try {
            $a->start();
            $this->blocked(1);
            DB::table('personal_access_tokens')->where('tokenable_id', $f['u']->id)->delete();
            DB::commit();
            $a->wait();
            $this->assertTrue($a->isSuccessful(), $a->getErrorOutput());
            $this->assertDatabaseHas('tracking_work', ['id' => $work, 'state' => 'blocked']);
            $this->assertDatabaseCount('carrier_tracking_events', 0);
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }if ($a->isRunning()) {
                $a->stop();
            }
        }
    }
}
