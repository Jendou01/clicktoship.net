<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\JobPayload;
use Symfony\Component\Process\Process;
use Tests\Fixtures\QaFailureProbe;
use Tests\TestCase;

final class QueueDiagnosticPrivacyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_real_worker_failure_and_horizon_metadata_keep_diagnostics_private_and_payload_retryable(): void
    {
        $queue = 'qa-private-failure-'.Str::uuid();
        $secret = 'synthetic-private-'.Str::uuid();
        $id = null;
        try {
            Queue::connection('redis')->push(new QaFailureProbe($secret), '', $queue);
            $raw = Redis::lindex('queues:'.$queue, 0);
            $payload = new JobPayload($raw);
            $id = $payload->id();
            $worker = new Process([PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue='.$queue, '--once', '--tries=1', '--timeout=10'], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'clicktoship_test']);
            $worker->setTimeout(20)->mustRun();
            $failed = DB::table('failed_jobs')->where('uuid', $id)->first();
            $this->assertNotNull($failed);
            $this->assertStringNotContainsString($secret, $failed->exception);
            $this->assertStringNotContainsString('[stacktrace]', $failed->exception);
            $this->assertSame('APPLICATION_QUEUE_FAILURE', json_decode($failed->exception, true)['code']);
            $this->assertSame($raw, $failed->payload);
            $this->assertSame($payload->decoded['data']['command'], json_decode($failed->payload, true)['data']['command']);
            $this->assertStringNotContainsString($secret, $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringNotContainsString($secret, $failed->payload);
            $this->assertStringContainsString($secret, Crypt::decryptString(json_decode($failed->payload, true)['data']['command']));
            $repo = app(JobRepository::class);
            $repo->failed(new \RuntimeException($secret), 'redis', $queue, $payload);
            $stored = $repo->getJobs([$id])[0];
            $this->assertStringNotContainsString($secret, $stored->exception);
            $this->assertSame('APPLICATION_QUEUE_FAILURE', json_decode($stored->exception, true)['code']);
            $this->assertSame($raw, $stored->payload);
            $this->assertSame(0, Queue::connection('redis')->size($queue));
            $this->assertTrue(app('queue.failer')->forget($id));
        } finally {
            Queue::connection('redis')->clear($queue);
            if ($id) {
                $h = Redis::connection('horizon');
                $h->del($id);
                foreach (['failed_jobs', 'recent_failed_jobs', 'recent_jobs', 'pending_jobs', 'completed_jobs', 'silenced_jobs'] as $set) {
                    $h->zrem($set, $id);
                }
            }
        }
    }
}
