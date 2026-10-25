<?php

namespace Tests\Support;

use App\Modules\Analytics\Jobs\GenerateReport;
use App\Modules\Shipping\Domain\DocumentScanner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

abstract class AnalyticsTestCase extends CommerceTestCase
{
    protected function analytics(bool $artifacts = false): array
    {
        Http::preventStrayRequests();
        Queue::fake([GenerateReport::class]);
        Storage::fake('local');
        $u = $this->user();
        $m = $this->organization($u);
        $this->login($u);
        $this->select($m);
        config(['analytics.test_artifacts_enabled' => $artifacts]);
        if ($artifacts) {
            app()->instance(DocumentScanner::class, new class implements DocumentScanner
            {
                public function clean(string $bytes): bool
                {
                    abort_unless(app()->environment('testing') && DB::connection()->getDatabaseName() === 'clicktoship_test', 403);

                    return true;
                }
            });
        }

        return compact('u', 'm');
    }

    protected function export(array $extra = []): array
    {
        return $this->browser('POST', '/api/v1/analytics/exports', array_replace(['report' => 'merchant_activity', 'format' => 'csv', 'locale' => 'en', 'idempotency_key' => 'request-analytics-1'], $extra))->assertStatus(202)->json('data.export');
    }

    protected function tearDown(): void
    {
        if ($this->app && app()->environment('testing') && DB::connection()->getDatabaseName() === 'clicktoship_test') {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
        parent::tearDown();
    }
}
