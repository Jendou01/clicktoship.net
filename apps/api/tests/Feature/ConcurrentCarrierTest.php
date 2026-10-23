<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\CarrierTestCase;

final class ConcurrentCarrierTest extends CarrierTestCase
{
    use DatabaseMigrations;

    private function race(array $a, array $b): array
    {
        $path = sys_get_temp_dir().'/carrier-'.Str::uuid();
        $worker = fn ($d) => (new Process([PHP_BINARY, 'tests/Fixtures/concurrent-carrier.php'], base_path()))->setInput(json_encode($d))->setTimeout(15);
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

    public function test_concurrent_requests_deduplicate_and_conflicting_replay_cannot_reuse_key(): void
    {
        $account = $this->account();
        $c = $this->merchantContext();
        $base = ['account' => $account, 'actor' => $c->actorId(), 'merchant' => $c->id(), 'key' => 'synthetic-concurrency', 'payload' => ['country' => 'SA', 'prefix' => 'Test']];
        $result = $this->race($base, $base);
        $this->assertSame(['ok', 'ok'], array_column($result, 'status'));
        $this->assertSame($result[0]['result'], $result[1]['result']);
        $this->assertDatabaseCount('carrier_operations', 1);
        $base['key'] = 'synthetic-changed';
        $changed = $base;
        $changed['payload']['prefix'] = 'Other';
        $result = $this->race($base, $changed);
        $this->assertSame(['ok', 409], array_column($result, 'status'));
        $this->assertDatabaseCount('carrier_operations', 2);
    }
}
