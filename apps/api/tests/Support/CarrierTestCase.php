<?php

namespace Tests\Support;

use App\Modules\Merchants\Domain\MerchantContext;
use Illuminate\Support\Str;

abstract class CarrierTestCase extends ManagementTestCase
{
    protected function accountPayload(): array
    {
        return ['code' => 'synthetic-'.Str::random(8), 'environment' => 'sandbox', 'version' => 0, 'name_en' => 'Synthetic Aramex test account', 'name_ar' => 'حساب اختبار فقط', 'evidence' => 'Synthetic configuration fixture; no carrier permission', 'services' => [], 'credentials' => ['UserName' => 'synthetic@example.test', 'Password' => 'Synthetic-not-a-carrier-password', 'Version' => '1.0', 'AccountNumber' => 'SYNTHETIC', 'AccountPin' => 'SYNTHETIC', 'AccountEntity' => 'SYN', 'AccountCountryCode' => 'SA', 'Source' => 'TEST']];
    }

    protected function account(): string
    {
        $a = $this->platformUser();
        $b = $this->platformUser();
        $this->adminLogin($a);
        $row = $this->browser('POST', '/api/v1/admin/carriers/accounts', $this->accountPayload())->assertCreated()->json('data');
        $this->adminLogin($b);
        $this->browser('POST', "/api/v1/admin/carriers/accounts/{$row['id']}/approve", ['version' => 1, 'state_version' => 1])->assertOk();

        return $row['id'];
    }

    protected function merchantContext(): MerchantContext
    {
        $u = $this->user();
        $m = $this->organization($u);
        $c = app(MerchantContext::class);
        $c->establish($u, $m);

        return $c;
    }

    protected function service(): array
    {
        return ['code' => 'synthetic-dom', 'name_en' => 'Test service', 'name_ar' => 'خدمة اختبار', 'origin_country' => 'SA', 'destination_country' => 'SA', 'product_group' => 'DOM', 'product_type' => 'CDS', 'postal_required' => true, 'state_required' => false, 'evidence' => 'ProductGroup guidance only, not verified entitlement'];
    }

    protected function shipmentInput(): array
    {
        return ['origin' => ['country' => 'SA', 'city' => 'Synthetic Origin', 'line1' => 'Test line', 'postal' => '12345', 'state' => null], 'destination' => ['country' => 'SA', 'city' => 'Synthetic Destination', 'line1' => 'Test line', 'postal' => '12345', 'state' => null], 'packages' => [['weight_grams' => 1250, 'length_mm' => 100, 'width_mm' => 200, 'height_mm' => 300]], 'currency' => 'SAR', 'description' => 'Synthetic goods', 'goods_origin_country' => 'AE', 'declared_minor' => '12345', 'payment_type' => 'P'];
    }
}
