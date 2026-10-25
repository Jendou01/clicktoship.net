<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ManagementTestCase;

final class DashboardRaceTest extends ManagementTestCase
{
    use DatabaseMigrations;

    public function test_suspension_and_token_revocation_winning_merchant_lock_prevent_dashboard_reads(): void
    {
        foreach (['suspend', 'revoke'] as $kind) {
            $u = $this->user();
            $m = $this->organization($u);
            $t = $this->token($u, $m);
            $p = (new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent-dashboard.php')], base_path()))->setInput(json_encode(['token' => $t, 'method' => 'GET', 'path' => '/api/v1/dashboard', 'body' => []]))->setTimeout(20);
            DB::beginTransaction();
            DB::table('merchants')->where('id', $m)->lockForUpdate()->first();
            try {
                $p->start();
                $deadline = microtime(true) + 8;
                $blocked = false;
                do {
                    DB::select('SELECT pg_stat_clear_snapshot()');
                    $blocked = (bool) DB::selectOne("SELECT 1 FROM pg_stat_activity WHERE datname=current_database() AND wait_event_type='Lock' AND pid<>pg_backend_pid()");
                    if ($blocked) {
                        break;
                    }usleep(1000);
                } while (microtime(true) < $deadline);
                $this->assertTrue($blocked, 'Independent request must wait for the actual merchant row lock');
                if ($kind === 'suspend') {
                    DB::table('users')->where('id', $u->id)->update(['suspended_at' => now()]);
                } else {
                    $u->tokens()->delete();
                }
                DB::commit();
                $p->wait();
                $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
                $result = json_decode($p->getOutput(), true);
                $this->assertSame(403, $result['status']);
                $this->assertNull($result['data']);
            } finally {
                if (DB::transactionLevel()) {
                    DB::rollBack();
                }if ($p->isRunning()) {
                    $p->stop();
                }
            }
        }
    }
}
