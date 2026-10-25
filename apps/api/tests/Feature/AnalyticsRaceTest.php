<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\AnalyticsTestCase;

final class AnalyticsRaceTest extends AnalyticsTestCase
{
    use DatabaseMigrations;

    private function process(array $data): Process
    {
        return (new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent-report.php')], base_path()))->setInput(json_encode($data))->setTimeout(20);
    }

    public function test_independent_workers_generate_one_artifact_without_holding_sql_during_scan(): void
    {
        $this->analytics(true);
        $e = $this->export();
        $a = $this->process(['id' => $e['id'], 'block' => true]);
        $b = $this->process(['id' => $e['id']]);
        DB::select('SELECT pg_advisory_lock(1500016)');
        try {
            $a->start();
            $deadline = microtime(true) + 8;
            $waiting = false;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = (bool) DB::selectOne("SELECT 1 FROM pg_stat_activity WHERE datname=current_database() AND application_name='report-race-scan' AND wait_event_type='Lock'");
                if ($waiting) {
                    break;
                }usleep(1000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($waiting, $a->getErrorOutput().$a->getOutput());
            $b->run();
            $this->assertTrue($b->isSuccessful(), $b->getErrorOutput().$b->getOutput());
            $this->assertSame('processing', json_decode($b->getOutput(), true)['state']);
        } finally {
            DB::select('SELECT pg_advisory_unlock(1500016)');
        }$a->wait();
        $this->assertTrue($a->isSuccessful(), $a->getErrorOutput().$a->getOutput());
        $this->assertSame('ready', json_decode($a->getOutput(), true)['state']);
        $this->assertSame(1, DB::table('report_export_events')->where('kind', 'ready')->count());
        $this->assertDatabaseHas('report_exports', ['id' => $e['id'], 'attempt' => 1]);
    }

    public function test_independent_requests_commit_one_idempotent_export(): void
    {
        $this->analytics(true);
        $e = $this->export();
        $a = $this->process(['id' => $e['id'], 'action' => 'request']);
        $b = $this->process(['id' => $e['id'], 'action' => 'request']);
        $a->start();
        $b->start();
        $a->wait();
        $b->wait();
        $this->assertTrue($a->isSuccessful(), $a->getErrorOutput().$a->getOutput());
        $this->assertTrue($b->isSuccessful(), $b->getErrorOutput().$b->getOutput());
        $this->assertSame(json_decode($a->getOutput(), true)['id'], json_decode($b->getOutput(), true)['id']);
        $this->assertDatabaseCount('report_exports', 2);
    }
}
