<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ManagementTestCase;

final class PlatformOverviewPerformanceTest extends ManagementTestCase
{
    use DatabaseMigrations;

    public function test_two_thousand_merchants_have_bounded_responses_and_no_per_merchant_queries(): void
    {
        $a = $this->platformUser();
        $this->adminLogin($a);
        DB::enableQueryLog();
        $this->browser('GET', '/api/v1/admin/dashboard')->assertOk();
        $baseline = count(DB::getQueryLog());
        DB::disableQueryLog();
        $rows = [];
        $today = now('UTC')->startOfDay();
        for ($i = 0; $i < 2000; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'name' => 'Synthetic scale merchant '.$i, 'status' => 'active', 'verification_status' => 'unverified', 'created_at' => $today, 'updated_at' => $today];
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('merchants')->insert($chunk);
        }
        DB::statement('ANALYZE merchants');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = microtime(true);
        $r = $this->browser('GET', '/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.widgets.merchants.total', 2000);
        $elapsed = (microtime(true) - $start) * 1000;
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($baseline + 1, $queries);
        $this->assertLessThan(85, $queries);
        $this->assertLessThan(262144, strlen($r->getContent()));
        $plan = DB::select('EXPLAIN (ANALYZE,FORMAT JSON) SELECT status,count(*) FROM merchants WHERE created_at >= ? GROUP BY status', [$today]);
        $json = json_decode($plan[0]->{'QUERY PLAN'}, true);
        fwrite(STDERR, PHP_EOL.json_encode(['platform_scale_merchants' => 2000, 'http_queries' => $queries, 'http_ms' => round($elapsed, 2), 'response_bytes' => strlen($r->getContent()), 'plan_execution_ms' => $json[0]['Execution Time']]).PHP_EOL);
        $this->browser('GET', '/api/v1/admin/merchants?search=Synthetic%20scale%20merchant%2019')->assertOk()->assertJsonCount(25, 'data.merchants');
    }
}
