<?php

namespace Tests\Support;

use App\Modules\Bulk\Application\Processor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

abstract class BulkTestCase extends PricingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['bulk.structural_only' => true]);
    }

    protected function bulkFixture(): array
    {
        $u = $this->user();
        $m = $this->organization($u);
        $f = $this->fixture();
        $token = $this->token($u, $m);

        return compact('u', 'm', 'f', 'token');
    }

    protected function csvRow(string $service): array
    {
        return ['service_id' => $service, 'currency' => 'SAR', 'description' => 'Synthetic bulk goods', 'origin_name' => 'Synthetic sender', 'origin_phone' => '+966501234567', 'origin_country' => 'SA', 'origin_city' => 'riyadh', 'origin_line1' => 'Synthetic street', 'origin_postal' => '12345', 'destination_name' => 'Synthetic recipient', 'destination_phone' => '+966509876543', 'destination_country' => 'SA', 'destination_city' => 'jeddah', 'destination_line1' => 'Synthetic destination', 'destination_postal' => '23456', 'weight_grams' => '1000', 'length_mm' => '100', 'width_mm' => '100', 'height_mm' => '100', 'declared_value_minor' => '0'];
    }

    protected function csv(array $rows, ?array $headers = null): string
    {
        $f = fopen('php://temp', 'w+');
        fputcsv($f, $headers ?? array_keys($rows[0]), ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($f, array_values($row), ',', '"', '');
        }rewind($f);
        $s = stream_get_contents($f);
        fclose($f);

        return $s;
    }

    protected function uploadTo(array $f, string $csv, string $format = 'csv', ?string $batch = null): string
    {
        if (! $batch) {
            $batch = $this->bearer($f['token'], 'POST', '/api/v1/bulk/imports', ['format' => $format, 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data.batch.id');
        }
        $version = DB::table('bulk_batches')->where('id', $batch)->value('version');
        $this->resetHttpState();
        $this->call('POST', "/api/v1/bulk/imports/$batch/file", ['version' => $version, 'idempotency_key' => (string) Str::uuid()], [], ['file' => UploadedFile::fake()->createWithContent('synthetic.'.$format, $csv)], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$f['token'], 'REMOTE_ADDR' => $this->ip])->assertOk();

        return $batch;
    }

    protected function drain(string $m, string $b): void
    {
        for ($i = 0; $i < 300; $i++) {
            $w = DB::table('bulk_work')->where('merchant_id', $m)->where('batch_id', $b)->whereNull('completed_at')->first();
            if (! $w) {
                return;
            }app(Processor::class)->tick($m, $w->id);
        }throw new \RuntimeException('Import did not converge.');
    }

    protected function bulkView(array $f, string $b): array
    {
        return $this->bearer($f['token'], 'GET', "/api/v1/bulk/imports/$b")->assertOk()->json('data');
    }

    protected function mapRows(array $f, string $b, ?array $mapping = null): void
    {
        $v = $this->bulkView($f, $b);
        $this->bearer($f['token'], 'POST', "/api/v1/bulk/imports/$b/mapping", ['mapping' => $mapping ?? $v['mapping'], 'version' => $v['batch']['version'], 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $this->drain($f['m'], $b);
    }

    protected function act(array $f, string $b, string $action, array $extra = []): TestResponse
    {
        $v = $this->bulkView($f, $b);

        return $this->bearer($f['token'], 'POST', "/api/v1/bulk/imports/$b/$action", ['version' => $v['batch']['version'], 'idempotency_key' => (string) Str::uuid()] + $extra);
    }
}
