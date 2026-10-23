<?php

namespace Tests\Support;

use App\Modules\Pricing\Domain\Configuration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class PricingTestCase extends ManagementTestCase
{
    protected function configRow(string $kind, array $config, ?string $merchant = null, ?string $currency = null): string
    {
        $config = ['name_en' => 'Synthetic fixture — NOT a carrier offer', 'name_ar' => 'بيانات اختبار وليست عرض ناقل', 'evidence' => 'Synthetic automated test fixture, no real tariff.'] + $config;
        Configuration::validate($kind, $config, $merchant);
        $id = (string) Str::uuid();
        $actor = DB::table('users')->value('id') ?? $this->user()->id;
        DB::transaction(function () use ($id, $kind, $config, $merchant, $currency, $actor) {
            DB::table('pricing_definitions')->insert(['id' => $id, 'kind' => $kind, 'code' => 'test-'.Str::random(8), 'merchant_id' => $merchant, 'version' => 1, 'active_version' => 1, 'enabled' => true]);
            DB::table('pricing_versions')->insert(['definition_id' => $id, 'version' => 1, 'configuration' => json_encode($config), 'currency' => $currency, 'effective_from' => now()->subDay(), 'effective_to' => now()->addDays(5), 'created_by' => $actor]);
        });

        return $id;
    }

    protected function fixture(): array
    {
        $service = $this->configRow('service', ['carrier' => 'synthetic_test_only', 'volumetric_divisor' => 5000, 'weight_step_grams' => 500, 'weight_mode' => 'aggregate', 'ttl_seconds' => 300, 'rounding' => 'half_up', 'capabilities' => ['cod' => false, 'remote' => false, 'declared_value' => false]]);
        $this->configRow('zone', ['country' => 'SA', 'cities' => ['riyadh', 'jeddah'], 'remote' => false]);
        $tax = $this->configRow('tax', ['country' => 'SA', 'jurisdiction' => 'origin', 'mode' => 'exclusive', 'percent_bps' => 500]); // 5% is synthetic, not a tax determination.
        $selectors = ['service_id' => $service, 'origin_country' => 'SA', 'destination_country' => 'SA', 'origin_zone' => null, 'destination_zone' => null, 'lane' => 'domestic', 'weight_from' => 0, 'weight_to' => 1000000001, 'packages_from' => 1, 'packages_to' => 100];
        $amounts = ['base_minor' => '1000', 'fixed_minor' => '0', 'percent_bps' => 0, 'included_grams' => 1000, 'step_grams' => 1000, 'step_minor' => '100'];
        $tariff = $this->configRow('tariff', $selectors + $amounts, null, 'SAR');
        $ruleConfig = $selectors + array_replace($amounts, ['base_minor' => '2000']) + ['scope' => 'global', 'tier_id' => null, 'mode' => 'fixed', 'discount_minor' => '0', 'discount_bps' => 0, 'min_margin_minor' => '100', 'min_margin_bps' => 100, 'tax_id' => $tax];
        $rule = $this->configRow('rule', $ruleConfig, null, 'SAR');

        return compact('service', 'tax', 'selectors', 'amounts', 'tariff', 'rule', 'ruleConfig');
    }

    protected function quoteInput(string $service): array
    {
        return ['service_id' => $service, 'currency' => 'SAR', 'origin' => ['country' => 'SA', 'city' => 'riyadh'], 'destination' => ['country' => 'SA', 'city' => 'jeddah'], 'packages' => [['weight_grams' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100]], 'cod_minor' => '0', 'declared_value_minor' => '0', 'idempotency_key' => (string) Str::uuid()];
    }
}
