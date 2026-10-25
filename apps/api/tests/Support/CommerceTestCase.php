<?php

namespace Tests\Support;

use App\Modules\Commerce\Application\Orders;
use App\Modules\Commerce\Domain\StoreProvider;
use App\Modules\Commerce\Jobs\ProcessStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

abstract class CommerceTestCase extends ShippingTestCase
{
    protected SyntheticStoreProvider $provider;

    protected function setupCommerce(): array
    {
        Http::preventStrayRequests();
        Queue::fake([ProcessStore::class]);
        $u = $this->user();
        $m = $this->organization($u);
        $f = $this->fixture();
        $this->login($u);
        $this->select($m);
        $this->provider = new SyntheticStoreProvider;
        app()->instance(StoreProvider::class, $this->provider);
        config(['commerce.redirect_uri' => 'https://preview.example.test/api/v1/commerce/salla/callback', 'commerce.approved_redirect_uris' => ['https://preview.example.test/api/v1/commerce/salla/callback'], 'commerce.verified_authorization_hosts' => ['synthetic.example.test']]);
        $connection = $this->install('store-A');

        return compact('u', 'm', 'f', 'connection');
    }

    protected function install(string $store): string
    {
        $r = $this->browser('POST', '/api/v1/commerce/salla/authorize')->assertOk()->json('data');
        parse_str(parse_url($r['authorization_url'], PHP_URL_QUERY), $q);

        return $this->browser('GET', '/api/v1/commerce/salla/callback?'.http_build_query(['state' => $q['state'], 'code' => $store]))->assertOk()->json('data.connection_id');
    }

    protected function order(string $id = 'order-1', array $overrides = []): array
    {
        return array_replace(['id' => $id, 'reference' => 'SYN-'.$id, 'created_at' => now()->subDay()->toIso8601String(), 'updated_at' => now()->toIso8601String(), 'status' => 'ready', 'payment' => 'paid', 'fulfillment' => 'unfulfilled', 'currency' => 'SAR', 'amount_minor' => '2500', 'cod_minor' => '0', 'recipient' => ['name' => 'Synthetic Recipient', 'phone' => '+966501234567', 'country' => 'SA', 'city' => 'riyadh', 'line1' => 'Synthetic street', 'postal' => '12345'], 'items' => []], $overrides);
    }

    protected function import(array $f, array $orders): array
    {
        $conn = DB::table('commerce_connections')->where('id', $f['connection'])->first();
        DB::transaction(function () use ($conn, $orders) {
            foreach ($orders as $o) {
                app(Orders::class)->ingest($conn, $o);
            }
        });

        return DB::table('commerce_orders')->where('merchant_id', $f['m'])->get()->all();
    }

    protected function review(array $f, object $o): array
    {
        $draft = $this->draft($f['f']['service']);
        $review = array_intersect_key($draft, array_flip(['origin', 'packages', 'service_id', 'description', 'goods_origin_country', 'items']));
        $d = ['snapshot_id' => $o->current_snapshot, 'version' => $o->version, 'review' => $review];
        $p = $this->browser('POST', "/api/v1/commerce/orders/$o->id/preview", $d)->assertOk()->json('data');
        $this->assertTrue($p['valid'], json_encode($p['fields']));

        return $d + ['preview_hash' => $p['preview_hash']];
    }
}
