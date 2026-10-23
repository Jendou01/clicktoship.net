<?php

namespace Tests\Feature;

use App\Models\PersonalAccessToken;
use App\Modules\Carriers\Application\CarrierCredentialsManager;
use App\Modules\Carriers\Application\CarrierIntegrationHealth;
use App\Modules\Carriers\Application\CarrierOperations;
use App\Modules\Carriers\Application\CarrierTrackingInbox;
use App\Modules\Carriers\Domain\CarrierFailure;
use App\Modules\Carriers\Domain\TrackingOrder;
use App\Modules\Carriers\Jobs\ProcessCarrierOperation;
use App\Modules\Merchants\Domain\MerchantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\CarrierTestCase;

final class CarrierSecurityTest extends CarrierTestCase
{
    use DatabaseMigrations;

    public function test_configuration_has_recent_mfa_dual_control_and_no_credential_export(): void
    {
        $a = $this->platformUser();
        $b = $this->platformUser();
        $p = $this->accountPayload();
        $this->login($a);
        $this->browser('GET', '/api/v1/admin/carriers')->assertForbidden();
        $this->adminLogin($a);
        $row = $this->browser('POST', '/api/v1/admin/carriers/accounts', $p)->assertCreated()->json('data');
        $url = "/api/v1/admin/carriers/accounts/{$row['id']}";
        $this->browser('POST', $url.'/approve', ['version' => 1, 'state_version' => 1])->assertForbidden();
        $data = $this->browser('GET', '/api/v1/admin/carriers')->assertOk()->json('data');
        $this->assertStringNotContainsString('Synthetic-not-a-carrier-password', json_encode($data));
        $this->assertStringNotContainsString('credentials_ciphertext', json_encode($data));
        $this->assertFalse($data['production_enabled']);
        $cipher = DB::table('carrier_account_versions')->value('credentials_ciphertext');
        $this->assertStringNotContainsString($p['credentials']['Password'], $cipher);
        $this->assertSame($p['credentials'], json_decode(Crypt::decryptString($cipher), true));
        $this->browser('PATCH', $url, $p)->assertConflict();
        $this->adminLogin($b);
        $this->browser('POST', $url.'/approve', ['version' => 1, 'state_version' => 1])->assertOk()->assertJsonPath('data.external_enabled', false);
        $this->assertSame($p['credentials'], app(CarrierCredentialsManager::class)->forSandbox($row['id'], 1));
        $this->browser('POST', $url.'/disable', ['version' => 1, 'state_version' => 2])->assertOk();
        $this->expectException(CarrierFailure::class);
        app(CarrierCredentialsManager::class)->forSandbox($row['id'], 1);
    }

    public function test_revoked_maker_cannot_be_approved_and_merchants_cannot_configure_master_account(): void
    {
        $a = $this->platformUser();
        $b = $this->platformUser();
        $this->adminLogin($a);
        $row = $this->browser('POST', '/api/v1/admin/carriers/accounts', $this->accountPayload())->assertCreated()->json('data');
        DB::table('auth_sessions')->where('user_id', $a->id)->update(['revoked_at' => now()]);
        $this->adminLogin($b);
        $this->browser('POST', "/api/v1/admin/carriers/accounts/{$row['id']}/approve", ['version' => 1, 'state_version' => 1])->assertForbidden();
        $u = $this->user();
        $this->jar = [];
        $this->login($u);
        $this->browser('GET', '/api/v1/admin/carriers')->assertForbidden();
        $this->browser('POST', '/api/v1/admin/carriers/accounts', $this->accountPayload())->assertForbidden();
        $this->browser('POST', '/api/v1/admin/carriers/accounts', $this->accountPayload(), false)->assertStatus(419);
    }

    public function test_production_configuration_and_unknown_activation_fields_never_enable_calls(): void
    {
        $a = $this->platformUser();
        $b = $this->platformUser();
        $this->adminLogin($a);
        $p = $this->accountPayload();
        $p['environment'] = 'production';
        $this->browser('POST', '/api/v1/admin/carriers/accounts', $p + ['external_enabled' => true])->assertUnprocessable();
        $this->browser('POST', '/api/v1/admin/carriers/accounts', $p + ['endpoint' => 'https://attacker.invalid'])->assertUnprocessable();
        $row = $this->browser('POST', '/api/v1/admin/carriers/accounts', $p)->assertCreated()->json('data');
        $this->adminLogin($b);
        $this->browser('POST', "/api/v1/admin/carriers/accounts/{$row['id']}/approve", ['version' => 1, 'state_version' => 1])->assertOk()->assertJsonPath('data.external_enabled', false);
        $this->expectException(CarrierFailure::class);
        app(CarrierCredentialsManager::class)->forSandbox($row['id'], 1);
    }

    public function test_disabled_webhook_never_trusts_headers_or_retains_unverified_payload(): void
    {
        $this->postJson('/api/v1/carrier-webhooks/aramex/'.Str::uuid(), ['Key' => 'invented', 'Value' => ['UpdateCode' => 'delivered']], ['x-api-key' => 'invented'])->assertStatus(503);
        $this->assertDatabaseCount('carrier_webhook_deliveries', 0);
        $this->assertDatabaseCount('carrier_tracking_events', 0);
    }

    public function test_local_operations_are_scoped_deduplicated_and_blocked_before_any_http(): void
    {
        $a = $this->account();
        $c = $this->merchantContext();
        $merchant = $c->id();
        $ops = app(CarrierOperations::class);
        $payload = ['country' => 'SA', 'prefix' => 'Synthetic'];
        $one = $ops->prepare($c, $a, 'FetchCities', 'synthetic-key', $payload);
        $this->assertSame($one, $ops->prepare($c, $a, 'FetchCities', 'synthetic-key', $payload));
        try {
            $ops->prepare($c, $a, 'FetchCities', 'synthetic-key', ['country' => 'SA', 'prefix' => 'Changed']);
            $this->fail('Replay changed');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        Http::fake();
        $result = $ops->execute($merchant, $one['id']);
        $this->assertSame('blocked', $result['state']);
        $this->assertSame('CONTRACT_UNVERIFIED', $result['error_code']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('carrier_attempts', 1);
        $this->assertSame($result, $ops->execute($merchant, $one['id']));
        $this->assertDatabaseCount('carrier_attempts', 1);
        $other = $this->organization($this->user());
        try {
            $ops->execute($other, $one['id']);
            $this->fail('IDOR');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertDatabaseCount('ledger_transactions', 0);
    }

    public function test_queued_operations_keep_origin_token_scope_and_clear_context_after_revocation(): void
    {
        $account = $this->account();
        $u = $this->user();
        $merchant = $this->organization($u);
        $plain = $this->token($u, $merchant, ['rates.quote']);
        $id = explode('|', $plain)[0];
        $token = PersonalAccessToken::findOrFail($id);
        $c = app(MerchantContext::class);
        $c->establish($u, $merchant, $token);
        $row = app(CarrierOperations::class)->prepare($c, $account, 'FetchCities', 'token-synthetic', ['country' => 'SA', 'prefix' => 'Test']);
        $token->delete();
        $job = new ProcessCarrierOperation($merchant, $row['id']);
        try {
            $job->handle(app(CarrierOperations::class));
            $this->fail('Revoked token accepted');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertNull($c->id());
        $this->assertDatabaseHas('carrier_operations', ['id' => $row['id'], 'state' => 'pending']);
    }

    public function test_worker_crash_marker_is_never_resubmitted_and_unknown_cannot_reset_to_pending(): void
    {
        $account = $this->account();
        $c = $this->merchantContext();
        $m = $c->id();
        $ops = app(CarrierOperations::class);
        $row = $ops->prepare($c, $account, 'FetchCities', 'crash-synthetic', ['country' => 'SA', 'prefix' => 'Test']);
        DB::table('carrier_operations')->where('id', $row['id'])->update(['state' => 'sending', 'updated_at' => now()->subMinutes(6)]);
        Http::fake();
        $this->assertTrue($ops->execute($m, $row['id'])['reconciliation_required']);
        Http::assertNothingSent();
        $this->assertSame('unknown', $ops->quarantineStale($m, $row['id'])['state']);
        try {
            DB::table('carrier_operations')->where('id', $row['id'])->update(['state' => 'pending']);
            $this->fail('Ambiguous operation retried');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('transition', $e->getMessage());
        }
    }

    public function test_event_replay_and_ordering_retain_unknown_raw_events_without_fabricating_status(): void
    {
        $a = $this->account();
        $c = $this->merchantContext();
        $op = app(CarrierOperations::class)->prepare($c, $a, 'FetchCities', 'event-synthetic', ['country' => 'SA', 'prefix' => 'Test']);
        $inbox = app(CarrierTrackingInbox::class);
        $at = now()->subHour()->toIso8601String();
        $m = $c->id();
        $one = $inbox->retain($a, $m, $op['id'], 'synthetic-1', 'UNVERIFIED-CODE', $at, ['SyntheticSensitive' => 'test-only']);
        $this->assertSame($one, $inbox->retain($a, $m, $op['id'], 'synthetic-1', 'UNVERIFIED-CODE', $at, ['SyntheticSensitive' => 'test-only']));
        try {
            $inbox->retain($a, $m, $op['id'], 'synthetic-1', 'DIFFERENT', $at, ['changed' => true]);
            $this->fail('Changed event replay');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $inbox->retain($a, $m, $op['id'], 'synthetic-older', 'ANOTHER', now()->subHours(2)->toIso8601String(), ['test' => true]);
        $this->assertDatabaseCount('carrier_tracking_events', 2);
        $this->assertDatabaseCount('carrier_tracking_projections', 0);
        $this->assertDatabaseHas('carrier_tracking_events', ['raw_code' => 'UNVERIFIED-CODE', 'normalized_status' => 'unknown']);
        $this->assertStringNotContainsString('SyntheticSensitive', DB::table('carrier_webhook_deliveries')->value('payload_ciphertext'));
        $this->assertFalse(TrackingOrder::accepts('delivered', $at, 'in_transit', now()->toIso8601String()));
        $this->assertTrue(TrackingOrder::accepts('delivered', $at, 'returning', now()->toIso8601String()));
        $this->assertFalse(TrackingOrder::accepts('in_transit', $at, 'picked_up', now()->subHours(2)->toIso8601String()));
        $this->assertFalse(TrackingOrder::accepts('returned', $at, 'returning', now()->toIso8601String()));
    }

    public function test_redis_circuit_readiness_never_changes_account_enablement(): void
    {
        $account = $this->account();
        $h = app(CarrierIntegrationHealth::class);
        for ($i = 0; $i < 5; $i++) {
            $h->record($account, 'degraded', 'NETWORK_FAILURE');
        }$this->assertTrue($h->open($account));
        $this->assertDatabaseCount('carrier_health_records', 6);
    }

    public function test_additive_rollback_reapply_preserves_phase5_and_identity(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]));
        $this->assertDatabaseHas('users', ['id' => $u->id]);
        $this->assertTrue(Schema::hasTable('shipping_quotes'));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertDatabaseHas('merchants', ['id' => $m]);
        $this->assertDatabaseCount('carrier_accounts', 0);
    }
}
