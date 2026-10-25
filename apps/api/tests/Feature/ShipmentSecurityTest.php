<?php

namespace Tests\Feature;

use App\Modules\Shipping\Application\Documents;
use App\Modules\Shipping\Domain\DocumentScanner;
use App\Modules\Shipping\Domain\ShipmentInput;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ShippingTestCase;

final class ShipmentSecurityTest extends ShippingTestCase
{
    use DatabaseMigrations;

    public function test_directory_summary_is_tenant_scoped_and_excludes_private_addresses(): void
    {
        $f = $this->setupShipment(false);
        $this->bearer($f['token'], 'GET', '/api/v1/shipments')->assertOk()
            ->assertJsonPath('data.shipments.0.summary.description', 'Synthetic goods')
            ->assertJsonPath('data.shipments.0.summary.recipient', 'Synthetic contact')
            ->assertJsonMissingPath('data.shipments.0.summary.phone')
            ->assertJsonMissingPath('data.shipments.0.summary.line1');
        $other = $this->user();
        $merchant = $this->organization($other);
        $this->bearer($this->token($other, $merchant), 'GET', '/api/v1/shipments')->assertOk()->assertJsonCount(0, 'data.shipments');
    }

    public function test_idor_scope_intersection_and_mass_assignment_fail_closed(): void
    {
        $f = $this->setupShipment(false);
        $other = $this->user();
        $m = $this->organization($other);
        $token = $this->token($other, $m);
        foreach (['', '/events', '/documents'] as $suffix) {
            $this->bearer($token, 'GET', "/api/v1/shipments/{$f['s']['id']}$suffix")->assertNotFound();
        }
        $this->bearer($this->token($f['u'], $f['m'], ['rates.quote']), 'GET', '/api/v1/shipments')->assertForbidden();
        $d = $f['d'];
        $d['merchant_id'] = $m;
        $this->bearer($f['token'], 'POST', '/api/v1/shipments', ['draft' => $d, 'idempotency_key' => 'mass-assignment'])->assertUnprocessable();
        $this->bearer('invalid-token', 'GET', '/api/v1/shipments')->assertUnauthorized();
    }

    public function test_revision_immutability_quote_substitution_single_use_and_stale_edits(): void
    {
        $f = $this->setupShipment(false);
        $id = $f['s']['id'];
        $d = $f['d'];
        $d['description'] = 'Updated synthetic goods';
        $this->bearer($f['token'], 'PATCH', "/api/v1/shipments/$id", ['draft' => $d, 'version' => 1, 'idempotency_key' => 'stale-revision'])->assertConflict();
        $this->bearer($f['token'], 'PATCH', "/api/v1/shipments/$id", ['draft' => $d, 'version' => 2, 'idempotency_key' => 'updated-revision'])->assertOk()->assertJsonPath('data.quote', null);
        $this->assertDatabaseCount('shipment_revisions', 2);
        $this->bearer($f['token'], 'POST', "/api/v1/shipments/$id/validate", ['version' => 3])->assertOk();
        $this->bearer($f['token'], 'POST', "/api/v1/shipments/$id/quote-acceptance", ['version' => 4, 'quote_id' => $f['q']['id'], 'idempotency_key' => 'reuse-old-quote'])->assertConflict();
        $bad = $this->quoteInput($f['q']['service_id']);
        $bad['packages'][0]['weight_grams'] = 2000;
        $q = $this->bearer($f['token'], 'POST', '/api/v1/rates/quote', $bad)->assertCreated()->json('data');
        $this->bearer($f['token'], 'POST', "/api/v1/shipments/$id/quote-acceptance", ['version' => 4, 'quote_id' => $q['id'], 'idempotency_key' => 'substitute-quote'])->assertConflict();
        $this->expectException(QueryException::class);
        DB::table('shipment_revisions')->update(['payload' => 'tamper']);
    }

    public function test_international_readiness_requires_truthful_manufacture_and_items(): void
    {
        $f = $this->setupShipment(false);
        $draft = $f['d'];
        $draft['destination']['country'] = 'AE';
        $draft['goods_origin_country'] = null;
        $report = ShipmentInput::report($draft);
        $this->assertFalse($report['valid']);
        $this->assertContains('items', $report['missing']);
        $this->assertContains('goods_origin_country', $report['missing']);
        $draft['goods_origin_country'] = 'CN';
        $draft['items'] = [['description' => 'Synthetic item', 'quantity' => 1, 'value_minor' => '1000', 'country' => 'CN']];
        $report = ShipmentInput::report($draft);
        $this->assertTrue($report['valid']);
        $this->assertFalse($report['carrier_ready']);
    }

    public function test_documents_are_quarantined_revocable_scoped_and_never_public(): void
    {
        Storage::fake('local');
        $f = $this->setupShipment(false);
        $docs = app(Documents::class);
        $id = $docs->quarantine($f['m'], $f['s']['id'], "%PDF-1.4\nSYNTHETIC TEST ONLY\n%%EOF");
        $this->bearer($f['token'], 'GET', "/api/v1/shipment-documents/$id/download")->assertConflict();
        $docs->scan($f['m'], $id);
        $this->assertDatabaseHas('shipment_documents', ['id' => $id, 'state' => 'failed']);
        $second = $docs->quarantine($f['m'], $f['s']['id'], "%PDF-1.4\nSYNTHETIC TEST ONLY\n%%EOF");
        app()->instance(DocumentScanner::class, new class implements DocumentScanner
        {
            public function clean(string $content): bool
            {
                return str_contains($content, 'SYNTHETIC TEST ONLY');
            }
        });
        $docs->scan($f['m'], $second);
        $u = $this->user();
        $m = $this->organization($u);
        $t = $this->token($u, $m);
        $this->bearer($t, 'GET', "/api/v1/shipment-documents/$second/download")->assertNotFound();
        $this->bearer($f['token'], 'GET', "/api/v1/shipment-documents/$second/download")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Disposition', 'attachment; filename="shipping-label.pdf"');
        $list = $this->bearer($f['token'], 'GET', "/api/v1/shipments/{$f['s']['id']}/documents")->assertOk()->json('data');
        $this->assertStringNotContainsString('object_key', json_encode($list));
        $this->bearer($f['token'], 'POST', "/api/v1/shipment-documents/$second/revoke")->assertOk();
        $this->bearer($f['token'], 'GET', "/api/v1/shipment-documents/$second/download")->assertConflict();
        $this->assertDatabaseHas('audit_logs', ['action' => 'shipping.document_downloaded', 'subject_id' => $second]);
    }

    public function test_composite_foreign_keys_reject_cross_merchant_revisions(): void
    {
        $f = $this->setupShipment(false);
        $m = $this->organization($this->user());
        $this->expectException(QueryException::class);
        DB::table('shipment_revisions')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $m, 'shipment_id' => $f['s']['id'], 'revision' => 2, 'payload' => 'x', 'input_hash' => str_repeat('0', 64), 'pricing_hash' => str_repeat('0', 64), 'actor_id' => $f['u']->id]);
    }

    public function test_shipping_migration_rollback_reapply_preserves_existing_finance_and_identity(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $role = DB::table('roles')->where('code', 'merchant.owner')->value('id');
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Remove additive Phase 11 indexes before prior-phase rollback.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Remove additive Phase 10 before the original rollback target.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Remove additive Phase 9 before the existing preservation assertions.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Remove additive Phase 8 before exercising the original rollback target.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertDatabaseHas('merchant_users', ['merchant_id' => $m, 'user_id' => $u->id]);
        $this->assertSame($role, DB::table('roles')->where('code', 'merchant.owner')->value('id'));
        $this->assertDatabaseCount('ledger_transactions', 0);
    }

    public function test_revocation_winning_scanner_race_cannot_restore_document_readiness(): void
    {
        Storage::fake('local');
        $f = $this->setupShipment(false);
        $docs = app(Documents::class);
        $id = $docs->quarantine($f['m'], $f['s']['id'], "%PDF-1.4\nSYNTHETIC TEST ONLY\n%%EOF");
        app()->instance(DocumentScanner::class, new class($id) implements DocumentScanner
        {
            public function __construct(private string $id) {}

            public function clean(string $content): bool
            {
                DB::table('shipment_documents')->where('id', $this->id)->update(['state' => 'revoked']);

                return true;
            }
        });
        try {
            $docs->scan($f['m'], $id);
            $this->fail('Revoked document became ready');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseHas('shipment_documents', ['id' => $id, 'state' => 'revoked']);
        $this->bearer($f['token'], 'GET', "/api/v1/shipment-documents/$id/download")->assertConflict();
    }

    public function test_arbitrary_document_url_is_not_fetched_or_stored(): void
    {
        Storage::fake('local');
        $f = $this->setupShipment(false);
        try {
            app(Documents::class)->quarantine($f['m'], $f['s']['id'], 'http://169.254.169.254/latest/meta-data');
            $this->fail('URL accepted as PDF');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertDatabaseCount('shipment_documents', 0);
        Http::assertNothingSent();
    }

    public function test_normal_downgrade_refuses_retained_shipment_history(): void
    {
        $f = $this->setupShipment(false);
        $migration = require database_path('migrations/2026_10_24_110000_create_shipment_management.php');
        app()->detectEnvironment(fn () => 'local');
        try {
            try {
                $migration->down();
                $this->fail('Retained history was destroyed');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Retained shipment history', $e->getMessage());
            }
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
        $this->assertDatabaseHas('shipments', ['id' => $f['s']['id']]);
    }

    public function test_optional_return_address_is_encrypted_revision_bound_and_country_validated(): void
    {
        $f = $this->setupShipment(false);
        $d = $f['d'];
        $d['return_address'] = ['country' => 'US', 'city' => 'New York', 'name' => 'Synthetic returns', 'phone' => '+12125550123', 'line1' => 'Synthetic return street', 'postal' => '10001'];
        $saved = $this->bearer($f['token'], 'PATCH', "/api/v1/shipments/{$f['s']['id']}", ['draft' => $d, 'version' => 2, 'idempotency_key' => 'return-address-revision'])->assertOk()->json('data');
        $this->assertNull($saved['quote']);
        $this->assertContains('return_address.state', $saved['validation']['missing']);
        $this->assertSame('new york', $saved['draft']['return_address']['city']);
        $this->assertStringNotContainsString('Synthetic return street', DB::table('shipment_revisions')->where('shipment_id', $f['s']['id'])->where('revision', 2)->value('payload'));
    }
}
