<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ManagementTestCase;

final class PlatformOverviewRaceTest extends ManagementTestCase
{
    use DatabaseMigrations;

    public function test_current_session_harness_and_winning_security_changes_fence_aggregates(): void
    {
        foreach (['control', 'roles', 'session', 'mfa'] as $kind) {
            $a = $this->platformUser();
            $this->adminLogin($a);
            $p = (new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent-platform.php')], base_path()))->setInput(json_encode(['path' => '/api/v1/admin/dashboard', 'cookies' => $this->jar]))->setTimeout(20);
            if ($kind === 'control') {
                $p->run();
                $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
                $this->assertSame(200, json_decode($p->getOutput(), true)['status']);

                continue;
            }
            DB::beginTransaction();
            DB::select('SELECT pg_advisory_xact_lock(3042026)');
            try {
                $p->start();
                $blocked = false;
                $deadline = microtime(true) + 8;
                do {
                    DB::select('SELECT pg_stat_clear_snapshot()');
                    $blocked = (bool) DB::selectOne("SELECT 1 FROM pg_stat_activity WHERE datname=current_database() AND wait_event_type='Lock' AND pid<>pg_backend_pid()");
                    if ($blocked) {
                        break;
                    }usleep(1000);
                } while (microtime(true) < $deadline);
                $this->assertTrue($blocked, 'Actual independent request must wait on the platform security key');
                if ($kind === 'roles') {
                    DB::table('platform_role_assignments')->where('user_id', $a->id)->delete();
                }
                if ($kind === 'session') {
                    DB::table('auth_sessions')->where('user_id', $a->id)->update(['revoked_at' => now()]);
                }
                if ($kind === 'mfa') {
                    DB::table('auth_sessions')->where('user_id', $a->id)->update(['mfa_verified_at' => now()->subMinutes(16)]);
                }
                DB::commit();
                $p->wait();
                $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
                $result = json_decode($p->getOutput(), true);
                $this->assertSame($kind === 'session' ? 401 : 403, $result['status']);
                $this->assertFalse($result['has_data']);
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
