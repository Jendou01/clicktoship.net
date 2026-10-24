<?php

namespace Tests\Support;

use App\Modules\Carriers\Domain\ShipmentGateway;
use App\Modules\WalletBilling\Application\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class ShippingTestCase extends PricingTestCase
{
    protected function draft(string $service): array
    {
        $q = $this->quoteInput($service);
        unset($q['idempotency_key']);
        foreach (['origin', 'destination'] as $side) {
            $q[$side] += ['name' => 'Synthetic contact', 'phone' => '+966501234567', 'line1' => 'Synthetic test street', 'postal' => '12345'];
        }

        return $q + ['description' => 'Synthetic goods', 'goods_origin_country' => 'AE', 'items' => []];
    }

    protected function setupShipment(bool $synthetic = true): array
    {
        $u = $this->user();
        $m = $this->organization($u);
        $f = $this->fixture();
        $token = $this->token($u, $m);
        $d = $this->draft($f['service']);
        $s = $this->bearer($token, 'POST', '/api/v1/shipments', ['draft' => $d, 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data.shipment');
        $s = $this->bearer($token, 'POST', "/api/v1/shipments/{$s['id']}/validate", ['version' => 1])->assertOk()->json('data.shipment');
        $q = $this->bearer($token, 'POST', '/api/v1/rates/quote', $this->quoteInput($f['service']))->assertCreated()->json('data');
        $this->bearer($token, 'POST', "/api/v1/shipments/{$s['id']}/quote-acceptance", ['version' => $s['version'], 'quote_id' => $q['id'], 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $w = app(Ledger::class)->provision($m);
        app(Ledger::class)->command($m, $w, 'synthetic', '10000', 'fixture', 'fixture', 'test');
        $gateway = null;
        if ($synthetic) {
            $admin = $this->platformUser();
            $this->adminLogin($admin);
            $session = app('session.store')->get('auth_session_id');
            $account = (string) Str::uuid();
            DB::transaction(function () use ($account, $admin, $session) {
                DB::table('carrier_accounts')->insert(['id' => $account, 'carrier' => 'aramex', 'code' => 'synthetic-'.$account, 'environment' => 'sandbox', 'active_version' => 1]);
                DB::table('carrier_account_versions')->insert(['account_id' => $account, 'version' => 1, 'name_en' => 'SYNTHETIC ONLY', 'name_ar' => 'اختبار فقط', 'evidence' => 'Synthetic carrier protocol; not a verified Aramex contract', 'services' => '[]', 'created_by' => $admin->id, 'session_id' => $session]);
            });
            $gateway = new SyntheticShipmentGateway($account);
            app()->instance(ShipmentGateway::class, $gateway);
        }

        return compact('u', 'm', 'token', 'd', 's', 'q', 'w', 'gateway');
    }

    protected function submit(array $f): array
    {
        return $this->bearer($f['token'], 'POST', "/api/v1/shipments/{$f['s']['id']}/submit", ['version' => $f['s']['version'], 'idempotency_key' => 'synthetic-submit-command'])->assertStatus(202)->json('data');
    }
}
