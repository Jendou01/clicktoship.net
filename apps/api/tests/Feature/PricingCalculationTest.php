<?php

namespace Tests\Feature;

use App\Modules\Pricing\Application\Catalog;
use App\Modules\Pricing\Domain\Calculator;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\PricingTestCase;

final class PricingCalculationTest extends PricingTestCase
{
    use DatabaseMigrations;

    public function test_weight_rounding_and_adjacent_half_open_bands(): void
    {
        $this->user();
        $f = $this->fixture();
        $rows = app(Catalog::class)->active();
        $next = null;
        foreach ($rows as &$r) {
            if ($r['kind'] === 'tariff') {
                $r['config']['weight_to'] = 2000;
                $next = $r;
                $next['id'] = 'synthetic-next-band';
                $next['config']['weight_from'] = 2000;
                $next['config']['weight_to'] = 1000000001;
                $next['config']['base_minor'] = '1500';
            }if ($r['kind'] === 'rule') {
                $r['config']['mode'] = 'markup';
                $r['config']['base_minor'] = '0';
                $r['config']['fixed_minor'] = '500';
                $r['config']['step_minor'] = '0';
            }
        }unset($r);
        $rows[] = $next;
        $input = $this->quoteInput($f['service']);
        $input['packages'][0]['weight_grams'] = 1500;
        $this->assertSame('1680', app(Calculator::class)->calculate($input, 'unused', $rows)['public']['total_minor']);
        $input['packages'][0]['weight_grams'] = 1501;
        $q = app(Calculator::class)->calculate($input, 'unused', $rows);
        $this->assertSame(2000, $q['public']['billable_weight_grams']);
        $this->assertSame('2205', $q['public']['total_minor']);
    }

    public function test_per_package_and_aggregate_rounding_are_explicit(): void
    {
        $this->user();
        $f = $this->fixture();
        $rows = app(Catalog::class)->active();
        $input = $this->quoteInput($f['service']);
        $input['packages'][0]['weight_grams'] = 501;
        $input['packages'][] = $input['packages'][0];
        $this->assertSame(1500, app(Calculator::class)->calculate($input, 'unused', $rows)['public']['billable_weight_grams']);
        foreach ($rows as &$r) {
            if ($r['kind'] === 'service') {
                $r['config']['weight_mode'] = 'per_package';
            }
        }unset($r);
        $this->assertSame(2000, app(Calculator::class)->calculate($input, 'unused', $rows)['public']['billable_weight_grams']);
        foreach ($rows as &$r) {
            if ($r['kind'] === 'tariff') {
                $r['config']['packages_to'] = 1;
            }
        }unset($r);
        $this->expectException(DomainException::class);
        app(Calculator::class)->calculate($input, 'unused', $rows);
    }

    public function test_cod_remote_and_declared_value_require_both_verified_capability_and_fee_policy(): void
    {
        $this->user();
        $f = $this->fixture();
        $input = $this->quoteInput($f['service']);
        $input['cod_minor'] = '10000';
        $input['declared_value_minor'] = '50000';
        foreach (['cod', 'remote', 'declared_value'] as $code) {
            $this->configRow('fee', $f['selectors'] + ['code' => $code, 'side' => 'merchant', 'basis' => $code === 'remote' ? 'sell' : $code, 'fixed_minor' => '50', 'percent_bps' => 100, 'per_package' => false], null, 'SAR');
        }
        $rows = app(Catalog::class)->active();
        foreach ($rows as &$r) {
            if ($r['kind'] === 'service') {
                $r['config']['capabilities'] = ['cod' => true, 'remote' => true, 'declared_value' => true];
            }if ($r['kind'] === 'zone') {
                $r['config']['remote'] = true;
            }
        }unset($r);
        $q = app(Calculator::class)->calculate($input, 'unused', $rows);
        $this->assertSame('2909', $q['public']['total_minor']);
        $this->assertCount(3, $q['public']['fees']);
        $rows = array_filter($rows, fn ($r) => ! ($r['kind'] === 'fee' && $r['config']['code'] === 'remote'));
        $this->expectException(DomainException::class);
        app(Calculator::class)->calculate($input, 'unused', $rows);
    }

    public function test_quote_ttl_cannot_outlive_any_selected_contract_version(): void
    {
        $this->user();
        $f = $this->fixture();
        $rows = app(Catalog::class)->active();
        $expires = now()->addSeconds(45)->startOfSecond();
        foreach ($rows as &$r) {
            if ($r['kind'] === 'tariff') {
                $r['effective_to'] = $expires->toIso8601String();
            }
        }unset($r);
        $q = app(Calculator::class)->calculate($this->quoteInput($f['service']), 'unused', $rows);
        $this->assertSame($expires->toIso8601String(), $q['public']['expires_at']);
    }

    public function test_inactive_contract_and_merchant_mass_assignment_are_unavailable(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $f = $this->fixture();
        $token = $this->token($u, $m);
        $input = $this->quoteInput($f['service']);
        $this->bearer($token, 'POST', '/api/v1/rates/quote', $input + ['total_minor' => '1'])->assertUnprocessable();
        $this->bearer($token, 'POST', '/api/v1/rates/quote', $input + ['merchant_id' => $m])->assertUnprocessable();
        DB::table('pricing_definitions')->where('id', $f['tariff'])->update(['enabled' => false]);
        $this->bearer($token, 'POST', '/api/v1/rates/quote', $input)->assertUnprocessable();
        $this->assertDatabaseCount('shipping_quotes', 0);
    }
}
