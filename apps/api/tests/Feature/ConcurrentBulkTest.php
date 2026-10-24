<?php

namespace Tests\Feature;

use App\Modules\Bulk\Application\Processor;
use App\Modules\Bulk\Jobs\ProcessImport;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Support\BulkTestCase;

final class ConcurrentBulkTest extends BulkTestCase
{
    use DatabaseMigrations;

    private function worker(array $f, object $w): Process
    {
        return (new Process([PHP_BINARY, 'tests/Fixtures/concurrent-bulk.php'], base_path()))->setInput(json_encode(['merchant' => $f['m'], 'work' => $w->id]))->setTimeout(20);
    }

    private function readyBatch(): array
    {
        $f = $this->bulkFixture();
        $b = $this->uploadTo($f, $this->csv([$this->csvRow($f['f']['service'])]));
        $this->drain($f['m'], $b);
        $this->mapRows($f, $b);
        $this->act($f, $b, 'prepare', ['include_unpriced' => true])->assertOk();
        $w = DB::table('bulk_work')->where('batch_id', $b)->whereNull('completed_at')->first();

        return [$f, $b, $w];
    }

    private function waitBlocked(int $n = 1): void
    {
        $until = microtime(true) + 8;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = (int) DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE datname=current_database() AND wait_event_type='Lock'")->n;
            if ($waiting >= $n) {
                return;
            }usleep(1000);
        } while (microtime(true) < $until);
        $this->fail('Expected actual PostgreSQL lock waiters.');
    }

    public function test_duplicate_workers_commit_one_draft_and_one_reference(): void
    {
        [$f,$b,$w] = $this->readyBatch();
        DB::beginTransaction();
        DB::table('merchants')->where('id', $f['m'])->lockForUpdate()->first();
        $a = $this->worker($f, $w);
        $c = $this->worker($f, $w);
        try {
            $a->start();
            $c->start();
            $this->waitBlocked(2);
            DB::commit();
            $a->wait();
            $c->wait();
            $this->assertTrue($a->isSuccessful(), $a->getErrorOutput());
            $this->assertTrue($c->isSuccessful(), $c->getErrorOutput());
            $this->assertDatabaseCount('shipments', 1);
            $this->assertDatabaseCount('bulk_drafts', 1);
            $this->assertDatabaseCount('ledger_transactions', 0);
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }if ($a->isRunning()) {
                $a->stop();
            }if ($c->isRunning()) {
                $c->stop();
            }
        }
    }

    public function test_cancellation_winning_the_commit_fence_prevents_worker_effects(): void
    {
        [$f,$b,$w] = $this->readyBatch();
        DB::beginTransaction();
        DB::table('merchants')->where('id', $f['m'])->lockForUpdate()->first();
        $worker = $this->worker($f, $w);
        try {
            $worker->start();
            $this->waitBlocked();
            $this->act($f, $b, 'cancel')->assertOk();
            DB::commit();
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            $this->assertDatabaseCount('shipments', 0);
            $this->assertDatabaseHas('bulk_batches', ['id' => $b, 'state' => 'cancelled']);
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }

    public function test_credential_revocation_winning_the_fence_blocks_prepare(): void
    {
        [$f,$b,$w] = $this->readyBatch();
        DB::beginTransaction();
        DB::table('merchants')->where('id', $f['m'])->lockForUpdate()->first();
        $worker = $this->worker($f, $w);
        try {
            $worker->start();
            $this->waitBlocked();
            $f['u']->tokens()->delete();
            DB::commit();
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            $this->assertDatabaseCount('shipments', 0);
            $this->assertDatabaseHas('bulk_batches', ['id' => $b, 'state' => 'blocked']);
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }

    public function test_redis_loss_republishes_durable_work_and_partial_commit_cannot_cancel(): void
    {
        [$f,$b,$w] = $this->readyBatch();
        $this->assertGreaterThan(0, app(Processor::class)->recover());
        Queue::assertPushed(ProcessImport::class);
        $this->drain($f['m'], $b);
        $this->act($f, $b, 'cancel')->assertConflict();
        $this->assertDatabaseCount('shipments', 1);
    }
}
