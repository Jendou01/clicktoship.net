<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Fixtures\TenantQueueProbe;
use Tests\Support\IdentityTestCase;

class TenantQueueIntegrationTest extends IdentityTestCase
{
    use DatabaseMigrations;

    public function test_one_real_redis_worker_keeps_two_merchant_jobs_isolated(): void
    {
        $a = $this->user();
        $b = $this->user();
        $ma = $this->organization($a);
        $mb = $this->organization($b);
        $queue = 'tenant-test-'.Str::uuid();
        $keys = [$queue.':a', $queue.':b'];
        try {
            Queue::connection('redis')->push(new TenantQueueProbe($ma, $a->id, $keys[0]), '', $queue);
            Queue::connection('redis')->push(new TenantQueueProbe($mb, $b->id, $keys[1]), '', $queue);
            $process = new Process([PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue='.$queue, '--stop-when-empty', '--tries=1'], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'clicktoship_test']);
            $process->setTimeout(20)->mustRun();
            $this->assertSame(['merchant' => $ma, 'memberships' => 1], json_decode(Redis::get($keys[0]), true));
            $this->assertSame(['merchant' => $mb, 'memberships' => 1], json_decode(Redis::get($keys[1]), true));
        } finally {
            foreach ($keys as $key) {
                Redis::del($key);
            }
            Queue::connection('redis')->clear($queue);
        }
    }
}
