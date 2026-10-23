<?php

namespace Tests\Feature;

use App\Modules\Pricing\Application\Catalog;
use App\Modules\Pricing\Domain\Calculator;
use App\Modules\Pricing\Domain\CarrierRates;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use Tests\Support\PricingTestCase;

final class PricingSecurityTest extends PricingTestCase
{
    use DatabaseMigrations;

    private function draftPayload(): array
    {
        return ['kind' => 'tier', 'code' => 'approval-test', 'version' => 0, 'effective_from' => now()->toIso8601String(), 'effective_to' => now()->addDay()->toIso8601String(), 'configuration' => ['name_en' => 'Test tier', 'name_ar' => 'فئة اختبار', 'evidence' => 'Synthetic independent approval fixture']];
    }

    public function test_carrier_source_cannot_inject_selling_rules_or_capabilities(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $f = $this->fixture();
        $rules = array_values(array_filter(app(Catalog::class)->active(), fn ($r) => $r['kind'] === 'rule'));
        app()->instance(CarrierRates::class, new class($rules) implements CarrierRates
        {
            public function __construct(private array $rows) {}

            public function configurations(): array
            {
                return $this->rows;
            }
        });
        $this->bearer($this->token($u, $m), 'POST', '/api/v1/rates/quote', $this->quoteInput($f['service']))->assertStatus(503);
        $this->assertDatabaseCount('shipping_quotes', 0);
    }

    public function test_sql_conflicts_do_not_export_or_log_confidential_bindings(): void
    {
        $a = $this->platformUser();
        $this->adminLogin($a);
        $payload = $this->draftPayload();
        $this->browser('POST', '/api/v1/admin/pricing/definitions', $payload)->assertCreated();
        Log::spy();
        $payload['configuration']['evidence'] = 'Confidential source reference never sent in errors';
        $r = $this->browser('POST', '/api/v1/admin/pricing/definitions', $payload)->assertConflict();
        $this->assertStringNotContainsString('Confidential', $r->getContent());
        Log::shouldNotHaveReceived('error');
    }

    public function test_revoked_maker_or_expired_mfa_prevents_publication(): void
    {
        $a = $this->platformUser();
        $b = $this->platformUser();
        $this->adminLogin($a);
        $draft = $this->browser('POST', '/api/v1/admin/pricing/definitions', $this->draftPayload())->assertCreated()->json('data');
        DB::table('auth_sessions')->where('user_id', $a->id)->update(['revoked_at' => now()]);
        $this->adminLogin($b);
        $this->browser('POST', "/api/v1/admin/pricing/definitions/{$draft['id']}/publish", ['version' => 1, 'state_version' => 1])->assertForbidden();
        $this->travel(901)->seconds();
        $this->browser('GET', '/api/v1/admin/pricing/definitions')->assertForbidden();
        $this->assertDatabaseHas('pricing_definitions', ['id' => $draft['id'], 'enabled' => false]);
    }

    public function test_target_pricing_requires_separate_session_bound_grants_for_both_administrators(): void
    {
        $m = $this->organization($this->user());
        $other = $this->organization($this->user());
        $a = $this->platformUser();
        $b = $this->platformUser();
        $grant = $this->grant($a, $b, $m, ['platform.pricing.manage', 'platform.pricing.view']);
        $payload = $this->draftPayload();
        $payload['kind'] = 'assignment';
        $tier = $this->configRow('tier', []);
        $payload['configuration']['tier_id'] = $tier;
        $payload['access_grant'] = $grant;
        $this->browser('POST', "/api/v1/admin/pricing/merchants/$other/definitions", $payload)->assertForbidden();
        $draft = $this->browser('POST', "/api/v1/admin/pricing/merchants/$m/definitions", $payload)->assertCreated()->json('data');
        $this->browser('GET', '/api/v1/admin/pricing/definitions')->assertOk()->assertJsonCount(1, 'data.definitions'); // Only the global tier.
        $this->browser('GET', "/api/v1/admin/pricing/merchants/$m/definitions?access_grant=$grant")->assertOk()->assertJsonCount(1, 'data.definitions');
        $this->travel(31)->seconds();
        $approveGrant = $this->grant($b, $a, $m, ['platform.pricing.approve']);
        $url = "/api/v1/admin/pricing/merchants/$m/definitions/{$draft['id']}/publish";
        $this->browser('POST', $url, ['version' => 1, 'state_version' => 1, 'access_grant' => $grant])->assertForbidden();
        $this->browser('POST', $url, ['version' => 1, 'state_version' => 1, 'access_grant' => $approveGrant])->assertOk();
        $this->browser('POST', $url, ['version' => 1, 'state_version' => 1, 'access_grant' => $approveGrant])->assertConflict();
    }

    public function test_database_rejects_overlapping_published_bands_and_retains_immutable_versions(): void
    {
        $this->user();
        $f = $this->fixture();
        try {
            $this->configRow('tariff', $f['selectors'] + $f['amounts'], null, 'SAR');
            $this->fail('Conflicting tariff published');
        } catch (PDOException $e) {
            $this->assertStringContainsString('Conflicting published', $e->getMessage());
        }
        try {
            DB::table('pricing_versions')->where('definition_id', $f['tariff'])->delete();
            $this->fail('History removed');
        } catch (PDOException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
    }

    public function test_effective_versions_preserve_old_quote_after_repricing(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $f = $this->fixture();
        $token = $this->token($u, $m);
        $input = $this->quoteInput($f['service']);
        $q = $this->bearer($token, 'POST', '/api/v1/rates/quote', $input)->assertCreated()->json('data');
        $row = (array) DB::table('pricing_versions')->where('definition_id', $f['rule'])->first();
        unset($row['service_id'],$row['tier_id'],$row['tax_id'],$row['origin_zone'],$row['destination_zone'],$row['weight_from'],$row['weight_to'],$row['packages_from'],$row['packages_to']);
        $row['version'] = 2;
        $row['configuration'] = json_encode(array_replace($f['ruleConfig'], ['name_en' => 'Updated test offer', 'name_ar' => 'عرض اختبار محدث', 'evidence' => 'Synthetic replacement fixture', 'base_minor' => '2500']));
        DB::transaction(function () use ($row, $f) {
            DB::table('pricing_versions')->insert($row);
            DB::table('pricing_definitions')->where('id', $f['rule'])->update(['version' => 2, 'active_version' => 2]);
        });
        $this->bearer($token, 'GET', '/api/v1/rates/quotes/'.$q['id'])->assertOk()->assertJsonPath('data.total_minor', '2100');
        $input['idempotency_key'] = 'repriced-test-key';
        $new = $this->bearer($token, 'POST', '/api/v1/rates/quotes/'.$q['id'].'/recalculate', $input)->assertCreated()->json('data');
        $this->assertSame('2625', $new['total_minor']);
        $this->assertNotSame($q['pricing_version'], $new['pricing_version']);
    }

    public function test_private_quote_costs_need_current_target_grant_even_for_super_admin(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $f = $this->fixture();
        $q = $this->bearer($this->token($u, $m), 'POST', '/api/v1/rates/quote', $this->quoteInput($f['service']))->assertCreated()->json('data.id');
        $a = $this->platformUser();
        $b = $this->platformUser();
        $grant = $this->grant($a, $b, $m, ['platform.pricing.view']);
        $url = "/api/v1/admin/pricing/merchants/$m/quotes/$q";
        $this->browser('GET', $url)->assertUnprocessable();
        $this->browser('GET', $url.'?access_grant='.$grant)->assertOk()->assertJsonPath('data.internal.carrier_cost_minor', '1000');
        $this->browser('POST', '/api/v1/admin/pricing/definitions', $this->draftPayload(), false)->assertStatus(419);
    }

    public function test_international_route_requires_matching_static_tariff_zones_and_tax_policy(): void
    {
        $this->user();
        $f = $this->fixture();
        $this->configRow('zone', ['country' => 'AE', 'cities' => ['dubai'], 'remote' => false]);
        $this->configRow('tariff', array_replace($f['selectors'], ['destination_country' => 'AE', 'lane' => 'international']) + $f['amounts'], null, 'SAR');
        $this->configRow('rule', array_replace($f['ruleConfig'], ['destination_country' => 'AE', 'lane' => 'international', 'base_minor' => '4000']), null, 'SAR');
        $input = $this->quoteInput($f['service']);
        $input['destination'] = ['country' => 'AE', 'city' => 'dubai'];
        $this->assertSame('4200', app(Calculator::class)->calculate($input, 'unused', app(Catalog::class)->active())['public']['total_minor']);
        $input['destination']['city'] = 'unknown';
        $this->expectException(DomainException::class);
        app(Calculator::class)->calculate($input, 'unused', app(Catalog::class)->active());
    }

    public function test_discount_and_fee_tax_rounding_do_not_reduce_below_approved_margin(): void
    {
        $this->user();
        $f = $this->fixture();
        $rows = app(Catalog::class)->active();
        $input = $this->quoteInput($f['service']);
        foreach ($rows as &$row) {
            if ($row['kind'] === 'rule') {
                $row['config']['discount_minor'] = '100';
                $row['config']['discount_bps'] = 1000;
            }
        }unset($row);
        $result = app(Calculator::class)->calculate($input, 'unused', $rows);
        $this->assertSame('300', $result['public']['discount_minor']);
        $this->assertSame('1785', $result['public']['total_minor']);
        foreach ($rows as &$row) {
            if ($row['kind'] === 'rule') {
                $row['config']['min_margin_minor'] = '1000';
            }
        }unset($row);
        $this->expectException(DomainException::class);
        app(Calculator::class)->calculate($input, 'unused', $rows);
    }
}
