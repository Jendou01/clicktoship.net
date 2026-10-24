<?php

namespace Tests\Feature;

use App\Modules\Pricing\Application\Catalog;
use App\Modules\Pricing\Domain\Calculator;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PricingTestCase;

final class PricingTest extends PricingTestCase
{
    use DatabaseMigrations;

    public function test_quote_is_tenant_scoped_immutable_idempotent_and_has_no_wallet_effect(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $f = $this->fixture();
        $token = $this->token($u, $m);
        $input = $this->quoteInput($f['service']);
        $q = $this->bearer($token, 'POST', '/api/v1/rates/quote', $input)->assertCreated()->json('data');
        $this->assertSame('2100', $q['total_minor']);
        $this->assertFalse($q['shipping_enabled']);
        $this->assertSame($q, $this->bearer($token, 'POST', '/api/v1/rates/quote', $input)->assertCreated()->json('data'));
        $changed = $input;
        $changed['packages'][0]['weight_grams'] = 1001;
        $this->bearer($token, 'POST', '/api/v1/rates/quote', $changed)->assertConflict();
        $this->assertStringNotContainsString('carrier_cost', json_encode($q));
        $this->assertStringNotContainsString('margin', json_encode($q));
        $this->assertStringNotContainsString('evidence', json_encode($q));
        $private = json_decode(Crypt::decryptString(DB::table('shipping_quotes')->value('private_snapshot')), true);
        $this->assertSame('1000', $private['carrier_cost_minor']);
        $other = $this->user();
        $otherMerchant = $this->organization($other);
        $otherToken = $this->token($other, $otherMerchant);
        $this->bearer($otherToken, 'GET', '/api/v1/rates/quotes/'.$q['id'])->assertNotFound();
        $this->bearer($otherToken, 'POST', '/api/v1/rates/quotes/'.$q['id'].'/recalculate', $input)->assertNotFound();
        $this->assertDatabaseCount('ledger_transactions', 0);
        $this->travel(301)->seconds();
        $this->bearer($token, 'GET', '/api/v1/rates/quotes/'.$q['id'].'/validate')->assertOk()->assertJsonPath('data.valid', false);
        $this->bearer($token, 'GET', '/api/v1/rates/quotes/'.$q['id'])->assertOk()->assertJsonPath('data.total_minor', '2100');
        $input['idempotency_key'] = 'new-calculation-key';
        $next = $this->bearer($token, 'POST', '/api/v1/rates/quotes/'.$q['id'].'/recalculate', $input)->assertCreated()->json('data');
        $this->assertSame(2, $next['revision']);
        try {
            DB::table('shipping_quotes')->where('id', $q['id'])->update(['public_snapshot' => '{}']);
            $this->fail('Mutable quote');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
    }

    public function test_integer_weight_boundaries_volumetric_and_rounding(): void
    {
        $this->user();
        $f = $this->fixture();
        $input = $this->quoteInput($f['service']);
        $calc = app(Calculator::class);
        $all = app(Catalog::class)->active();
        $input['packages'][0]['weight_grams'] = 1001;
        $q = $calc->calculate($input, 'unused', $all);
        $this->assertSame(1500, $q['public']['billable_weight_grams']);
        $this->assertSame('2205', $q['public']['total_minor']);
        $input['packages'][0] = ['weight_grams' => 500, 'length_mm' => 300, 'width_mm' => 300, 'height_mm' => 300];
        $q = $calc->calculate($input, 'unused', $all);
        $this->assertSame(5500, $q['public']['billable_weight_grams']);
        $this->assertSame('2625', $q['public']['total_minor']);
        $this->assertSame(2, Calculator::ceil(1001, 1000));
    }

    public function test_merchant_tier_carrier_global_precedence_and_ambiguity_fail_closed(): void
    {
        $m = $this->organization($this->user());
        $f = $this->fixture();
        $input = $this->quoteInput($f['service']);
        $calc = app(Calculator::class);
        $this->configRow('rule', array_replace($f['ruleConfig'], ['scope' => 'carrier', 'base_minor' => '2200']), null, 'SAR');
        $tier = $this->configRow('tier', []);
        $this->configRow('assignment', ['tier_id' => $tier], $m);
        $this->configRow('rule', array_replace($f['ruleConfig'], ['scope' => 'tier', 'tier_id' => $tier, 'base_minor' => '2400']), null, 'SAR');
        $this->assertSame('2520', $calc->calculate($input, $m, app(Catalog::class)->active())['public']['total_minor']);
        $this->configRow('rule', array_replace($f['ruleConfig'], ['scope' => 'merchant', 'base_minor' => '2600']), $m, 'SAR');
        $this->assertSame('2730', $calc->calculate($input, $m, app(Catalog::class)->active())['public']['total_minor']);
        $rows = app(Catalog::class)->active();
        $duplicate = array_values(array_filter($rows, fn ($r) => $r['kind'] === 'rule' && $r['merchant_id'] === $m))[0];
        $duplicate['id'] = (string) Str::uuid();
        $rows[] = $duplicate;
        $this->expectException(DomainException::class);
        $calc->calculate($input, $m, $rows);
    }

    public function test_fees_discounts_margin_and_currency_are_explicit(): void
    {
        $this->user();
        $f = $this->fixture();
        $calc = app(Calculator::class);
        $input = $this->quoteInput($f['service']);
        $this->configRow('fee', $f['selectors'] + ['code' => 'service', 'side' => 'merchant', 'basis' => 'sell', 'fixed_minor' => '100', 'percent_bps' => 250, 'per_package' => false], null, 'SAR');
        $q = $calc->calculate($input, 'unused', app(Catalog::class)->active());
        $this->assertSame('150', $q['public']['fees'][0]['amount_minor']);
        $this->assertSame('2258', $q['public']['total_minor']);
        $all = app(Catalog::class)->active();
        foreach ($all as &$row) {
            if ($row['kind'] === 'tariff') {
                $row['currency'] = 'USD';
            }
        }unset($row);
        $this->expectException(DomainException::class);
        $calc->calculate($input, 'unused', $all);
    }

    public function test_unknown_route_missing_tariff_unsupported_cod_and_low_margin_rejected(): void
    {
        $this->user();
        $f = $this->fixture();
        $calc = app(Calculator::class);
        $input = $this->quoteInput($f['service']);
        $all = app(Catalog::class)->active();
        foreach (['route', 'tariff', 'cod', 'margin', 'overflow', 'tax'] as $case) {
            $i = $input;
            $rows = $all;
            if ($case === 'route') {
                $i['destination']['country'] = 'AE';
            }
            if ($case === 'tariff') {
                $rows = array_filter($rows, fn ($r) => $r['kind'] !== 'tariff');
            }
            if ($case === 'cod') {
                $i['cod_minor'] = '100';
            }
            if ($case === 'overflow') {
                $i['packages'][0]['weight_grams'] = 1000000000;
            }
            foreach ($rows as &$row) {
                if ($case === 'margin' && $row['kind'] === 'rule') {
                    $row['config']['base_minor'] = '900';
                }if ($case === 'overflow' && $row['kind'] === 'tariff') {
                    $row['config']['step_minor'] = '1000000000000';
                }if ($case === 'tax' && $row['kind'] === 'tax') {
                    $row['config']['country'] = 'US';
                }
            }unset($row);
            try {
                $calc->calculate($i, 'unused', $rows);
                $this->fail($case.' accepted');
            } catch (DomainException $e) {
                $this->assertSame('RATE_UNAVAILABLE', $e->getMessage());
            }
        }
    }

    public function test_admin_requires_mfa_independent_publish_and_optimistic_version(): void
    {
        $a = $this->platformUser();
        $b = $this->platformUser();
        $this->login($a);
        $this->browser('GET', '/api/v1/admin/pricing/definitions')->assertForbidden();
        $this->adminLogin($a);
        $payload = ['kind' => 'tier', 'code' => 'test-tier', 'version' => 0, 'effective_from' => now()->toIso8601String(), 'effective_to' => now()->addDay()->toIso8601String(), 'configuration' => ['name_en' => 'Test tier', 'name_ar' => 'فئة اختبار', 'evidence' => 'Synthetic fixture explicitly verified']];
        $d = $this->browser('POST', '/api/v1/admin/pricing/definitions', $payload)->assertCreated()->json('data');
        $this->browser('POST', '/api/v1/admin/pricing/definitions/'.$d['id'].'/publish', ['version' => 1, 'state_version' => 1])->assertForbidden();
        $this->browser('PATCH', '/api/v1/admin/pricing/definitions/'.$d['id'], $payload)->assertConflict();
        $this->adminLogin($b);
        $this->browser('POST', '/api/v1/admin/pricing/definitions/'.$d['id'].'/publish', ['version' => 1, 'state_version' => 1])->assertOk();
        $this->assertDatabaseCount('pricing_events', 2);
    }

    public function test_merchants_cannot_write_rates_and_scoped_tokens_cannot_quote(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $f = $this->fixture();
        $token = $this->token($u, $m, ['rates.read']);
        $this->bearer($token, 'GET', '/api/v1/rates')->assertOk();
        $this->bearer($token, 'POST', '/api/v1/rates/quote', $this->quoteInput($f['service']))->assertForbidden();
        $this->bearer($token, 'POST', '/api/v1/admin/pricing/definitions', [])->assertStatus(400);
        $this->resetHttpState();
        $this->getJson('/api/v1/rates')->assertUnauthorized();
    }

    public function test_pricing_rollback_reapply_keeps_existing_wallets_and_identities(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Additive Phase 7 first.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Additive Phase 6 first.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]));
        $this->assertDatabaseHas('users', ['id' => $u->id]);
        $this->assertDatabaseHas('merchants', ['id' => $m]);
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertDatabaseCount('pricing_definitions', 0);
    }
}
