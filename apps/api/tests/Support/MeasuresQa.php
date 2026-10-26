<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait MeasuresQa
{
    private array $measurements = [];

    private function measure(string $name, callable $work, int $samples = 20): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('clicktoship_test', DB::connection()->getDatabaseName());
        $times = $queries = $sizes = $sql = [];
        $cpu = getrusage();
        for ($i = 0; $i < $samples; $i++) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $start = hrtime(true);
            $response = $work($i);
            $times[] = (hrtime(true) - $start) / 1000000;
            $queries[] = count(DB::getQueryLog());
            $sql[] = array_sum(array_column(DB::getQueryLog(), 'time'));
            $sizes[] = is_string($response) ? strlen($response) : strlen($response->getContent());
            DB::disableQueryLog();
        }
        sort($times);
        $r = getrusage();
        $percentile = fn ($p) => round($times[(int) ceil($samples * $p) - 1], 3);
        $this->measurements[$name] = ['samples' => $samples, 'p50_ms' => $percentile(.5), 'p95_ms' => $percentile(.95), 'p99_ms' => $percentile(.99), 'sequential_rps' => round($samples / (array_sum($times) / 1000), 2), 'errors' => 0, 'queries_min' => min($queries), 'queries_max' => max($queries), 'sql_ms_max' => round(max($sql), 3), 'payload_bytes_max' => max($sizes), 'php_peak_bytes' => memory_get_peak_usage(true), 'php_cpu_ms' => round(($r['ru_utime.tv_sec'] - $cpu['ru_utime.tv_sec']) * 1000 + ($r['ru_utime.tv_usec'] - $cpu['ru_utime.tv_usec']) / 1000 + ($r['ru_stime.tv_sec'] - $cpu['ru_stime.tv_sec']) * 1000 + ($r['ru_stime.tv_usec'] - $cpu['ru_stime.tv_usec']) / 1000, 3)];
    }

    private function emitMeasures(): void
    {
        fwrite(STDERR, 'QA_BENCHMARK '.json_encode(['transport' => 'in-process real Laravel HTTP kernel / PostgreSQL / Redis', 'metrics' => $this->measurements]).PHP_EOL);
    }
}
