<?php

namespace Tests\Feature;

use App\Modules\WalletBilling\Application\Ledger;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ManagementTestCase;

class ConcurrentFinanceTest extends ManagementTestCase
{
    use DatabaseMigrations;

    private function race(array $a, array $b, string $fixture = 'concurrent-finance.php'): array
    {
        $path = sys_get_temp_dir().'/finance-'.Str::uuid();
        $worker = fn ($d) => (new Process([PHP_BINARY, 'tests/Fixtures/'.$fixture], base_path()))->setInput(json_encode($d))->setTimeout(10);
        $first = $worker([...$a, 'ready' => $path, 'go' => $path.'.go']);
        $second = $worker($b);
        try {
            $first->start();
            $deadline = microtime(true) + 5;
            while (! is_file($path) && microtime(true) < $deadline) {
                usleep(1000);
            }
            $this->assertFileExists($path);
            $second->start();
            // Wait for a real PostgreSQL lock waiter, not a timing-based race assumption.
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
            } if ($second->isRunning()) {
                $second->stop();
            }
        }
    }

    private function base(): array
    {
        $m = $this->organization($this->user());
        $l = app(Ledger::class);
        $w = $l->provision($m);
        $l->command($m, $w, 'synthetic', '10000', 'seed', 'seed', 'test');

        return ['merchant' => $m, 'wallet' => $w, 'kind' => 'reserve', 'amount' => '7000', 'business' => 'one', 'request' => 'one', 'expires' => now()->addHour()->toIso8601String()];
    }

    public function test_simultaneous_reservations_cannot_overspend(): void
    {
        $a = $this->base();
        $r = $this->race($a, [...$a, 'business' => 'two', 'request' => 'two']);
        $this->assertSame(['ok', '23514'], array_column($r, 'status'));
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $a['wallet'], 'reserved' => 7000, 'posted' => 10000]);
    }

    public function test_simultaneous_duplicate_commands_have_one_financial_effect(): void
    {
        $a = $this->base();
        $r = $this->race($a, $a);
        $this->assertSame(['ok', 'ok'], array_column($r, 'status'));
        $this->assertSame($r[0]['result'], $r[1]['result']);
        $this->assertSame(1, DB::table('balance_holds')->count());
    }

    public function test_suspension_winning_lock_blocks_new_reservation(): void
    {
        $a = $this->base();
        $r = $this->race([...$a, 'mode' => 'suspend'], $a);
        $this->assertSame(['ok', '23514'], array_column($r, 'status'));
        $this->assertSame(0, DB::table('balance_holds')->count());
    }

    public function test_concurrent_partial_captures_cannot_exceed_hold(): void
    {
        $a = $this->base();
        $h = app(Ledger::class)->command($a['merchant'], $a['wallet'], 'reserve', '7000', 'hold', 'hold', 'test', expires: $a['expires']);
        $c = [...$a, 'kind' => 'capture', 'amount' => '5000', 'reference' => $h['hold_id']];
        unset($c['expires']);
        $r = $this->race($c, [...$c, 'business' => 'two', 'request' => 'two']);
        $this->assertSame(['ok', '23514'], array_column($r, 'status'));
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $a['wallet'], 'posted' => 5000, 'reserved' => 2000]);
    }

    public function test_mfa_credential_and_approval_revocation_races_prevent_stale_financial_execution(): void
    {
        foreach (['mfa', 'credential', 'approval'] as $mode) {
            $m = $this->organization($this->user());
            $w = app(Ledger::class)->provision($m);
            $a = $this->platformUser();
            $b = $this->platformUser();
            $ga = $this->grant($a, $b, $m, ['platform.finance.correct']);
            $id = $this->browser('POST', "/api/v1/admin/merchants/$m/financial-requests", ['access_grant' => $ga, 'wallet_id' => $w, 'kind' => 'adjust_credit', 'amount' => '100', 'business_key' => 'race-'.$mode, 'request_key' => 'request-key-'.$mode.'-0001', 'reason' => 'Synthetic concurrency security case'])->assertCreated()->json('data.id');
            $raw = app('session.store')->getId();
            $session = DB::table('financial_approvals')->where('id', $id)->value('maker_session');
            $this->assertSame(hash('sha256', $raw), DB::table('auth_sessions')->where('id', $session)->value('session_hash'));
            $this->travel(31)->seconds();
            $gb = $this->grant($b, $a, $m, ['platform.finance.correct']);
            $this->browser('POST', "/api/v1/admin/merchants/$m/financial-requests/$id/approve", ['access_grant' => $gb, 'version' => 1, 'request_key' => 'approve-key-'.$mode.'-0001'])->assertOk();
            $payload = ['merchant' => $m, 'actor' => $a->id, 'approval' => $id, 'grant' => $ga, 'session' => $session, 'raw_session' => $raw];
            $r = $this->race([...$payload, 'mode' => $mode], [...$payload, 'mode' => 'execute'], 'concurrent-financial-authority.php');
            $this->assertSame(['ok', match ($mode) {
                'credential' => 401,'approval' => 409,default => 403
            }], array_column($r, 'status'));
            $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $w, 'posted' => 0]);
        }
    }

    public function test_concurrent_refunds_cannot_exceed_original_capture(): void
    {
        $a = $this->base();
        $l = app(Ledger::class);
        $h = $l->command($a['merchant'], $a['wallet'], 'reserve', '10000', 'hold', 'hold', 'test', expires: $a['expires']);
        $c = $l->command($a['merchant'], $a['wallet'], 'capture', '10000', 'capture', 'capture', 'test', $h['hold_id']);
        $refund = [...$a, 'kind' => 'refund', 'amount' => '7000', 'reference' => $c['transaction_id']];
        unset($refund['expires']);
        $r = $this->race($refund, [...$refund, 'business' => 'two', 'request' => 'two']);
        $this->assertSame(['ok', '23514'], array_column($r, 'status'));
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $a['wallet'], 'posted' => 7000, 'reserved' => 0]);
    }

    public function test_capture_and_release_race_cannot_consume_the_same_reservation_twice(): void
    {
        $a = $this->base();
        $l = app(Ledger::class);
        $h = $l->command($a['merchant'], $a['wallet'], 'reserve', '7000', 'hold', 'hold', 'test', expires: $a['expires']);
        $capture = [...$a, 'kind' => 'capture', 'amount' => '5000', 'reference' => $h['hold_id']];
        unset($capture['expires']);
        $r = $this->race($capture, [...$capture, 'kind' => 'release', 'business' => 'release', 'request' => 'release']);
        $this->assertSame(['ok', '23514'], array_column($r, 'status'));
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $a['wallet'], 'posted' => 5000, 'reserved' => 2000]);
    }

    public function test_process_exit_before_commit_rolls_back_and_lost_response_after_commit_replays(): void
    {
        $a = $this->base();
        $l = app(Ledger::class);
        foreach (['before', 'after'] as $when) {
            $p = [...$a, 'amount' => '1000', 'business' => 'crash-'.$when, 'request' => 'crash-'.$when, 'crash' => $when];
            $worker = (new Process([PHP_BINARY, 'tests/Fixtures/concurrent-finance.php'], base_path()))->setInput(json_encode($p))->setTimeout(10);
            $worker->mustRun();
            $this->assertSame('', $worker->getOutput());
            $this->assertSame($when === 'before' ? 0 : 1, DB::table('financial_operations')->where('business_key', 'crash-'.$when)->count());
            $l->command($a['merchant'], $a['wallet'], 'reserve', '1000', 'crash-'.$when, 'crash-'.$when, 'test', expires: $a['expires']);
            $this->assertSame(1, DB::table('financial_operations')->where('business_key', 'crash-'.$when)->count());
        }
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $a['wallet'], 'reserved' => 2000]);
    }
}
