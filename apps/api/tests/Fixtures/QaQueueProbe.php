<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

final class QaQueueProbe implements ShouldBeEncrypted, ShouldQueue
{
    public function __construct(public string $key, public float $enqueued) {}

    public function handle(): void
    {
        abort_unless(app()->environment('testing') && DB::selectOne('SELECT current_database() AS name')->name === 'clicktoship_test', 403);
        $start = microtime(true);
        DB::table('merchants')->count();
        Redis::setex($this->key, 60, json_encode(['queue_wait_ms' => round(($start - $this->enqueued) * 1000, 3), 'processing_ms' => round((microtime(true) - $start) * 1000, 3), 'php_peak_bytes' => memory_get_peak_usage(true)]));
    }
}
