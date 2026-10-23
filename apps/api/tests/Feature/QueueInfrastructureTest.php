<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Fixtures\QueueProbe;
use Tests\TestCase;

class QueueInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_separate_worker_processes_a_real_redis_job(): void
    {
        $queue = 'foundation-probe-'.Str::uuid();
        $key = $queue.':result';
        try {
            Queue::connection('redis')->push(new QueueProbe($key), '', $queue);
            $worker = new Process([
                PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue='.$queue,
                '--once', '--tries=1', '--timeout=10',
            ], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'clicktoship_test']);
            $worker->setTimeout(20)->mustRun();
            $this->assertSame('processed', Redis::get($key));
            $this->assertSame(0, Queue::connection('redis')->size($queue));
        } finally {
            Redis::del($key);
            Queue::connection('redis')->clear($queue);
        }
    }
}
