<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ShippingTestCase;

final class ShipmentDirectoryPerformanceTest extends ShippingTestCase
{
    use DatabaseMigrations;

    public function test_directory_queries_are_constant_and_summaries_match_exact_tenant_revisions(): void
    {
        Http::preventStrayRequests();
        $u = $this->user();
        $m = $this->organization($u);
        $token = $this->token($u, $m);
        $pricing = $this->fixture();
        $draft = $this->draft($pricing['service']);
        $create = fn ($t, $d) => $this->bearer($t, 'POST', '/api/v1/shipments', ['draft' => $d, 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data.shipment');
        $first = $create($token, $draft);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->bearer($token, 'GET', '/api/v1/shipments')->assertOk()->assertJsonCount(1, 'data.shipments');
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();
        foreach (range(1, 24) as $n) {
            $create($token, array_replace($draft, ['description' => 'Synthetic page '.$n]));
        }
        $other = $this->user();
        $otherMerchant = $this->organization($other);
        $create($this->token($other, $otherMerchant), array_replace($draft, ['description' => 'PRIVATE_OTHER_MERCHANT']));
        $this->bearer($token, 'PATCH', '/api/v1/shipments/'.$first['id'], ['draft' => array_replace($draft, ['description' => 'Exact revision two']), 'version' => 1, 'idempotency_key' => 'revision-two-key'])->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $r = $this->bearer($token, 'GET', '/api/v1/shipments')->assertOk()->assertJsonCount(25, 'data.shipments')->assertDontSee('PRIVATE_OTHER_MERCHANT')->assertDontSee('payload');
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(10, $large);
        $row = array_values(array_filter($r->json('data.shipments'), fn ($s) => $s['id'] === $first['id']))[0];
        $this->assertSame(2, $row['revision']);
        $this->assertSame('Exact revision two', $row['summary']['description']);
        $this->assertSame(['description', 'recipient'], array_keys($row['summary']));
        $this->assertDatabaseCount('ledger_transactions', 0);
        $this->assertDatabaseCount('carrier_operations', 0);
    }
}
