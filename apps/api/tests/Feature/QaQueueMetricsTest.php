<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Fixtures\QaQueueProbe;
use Tests\TestCase;

final class QaQueueMetricsTest extends TestCase
{
    use DatabaseMigrations;

    public function test_actual_encrypted_redis_job_reports_bounded_processing_and_queue_wait(): void
    {
        $queue = 'qa-metrics-'.Str::uuid();
        $key = $queue.':result';
        try {
            Queue::connection('redis')->push(new QaQueueProbe($key, microtime(true)), '', $queue);
            $worker = new Process([PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue='.$queue, '--once', '--tries=1', '--timeout=10'], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'clicktoship_test']);
            $worker->setTimeout(20)->mustRun();
            $metrics = json_decode(Redis::get($key), true);
            $this->assertGreaterThanOrEqual(0, $metrics['queue_wait_ms']);
            $this->assertGreaterThanOrEqual(0, $metrics['processing_ms']);
            $this->assertLessThan(134217728, $metrics['php_peak_bytes']);
            $this->assertSame(0, Queue::connection('redis')->size($queue));
            fwrite(STDERR, 'QA_QUEUE_METRICS '.json_encode($metrics).PHP_EOL);
        } finally {
            Redis::del($key);
            Queue::connection('redis')->clear($queue);
        }
    }
}
