<?php

namespace Tests\Feature;

use App\Models\PersonalAccessToken;
use App\Modules\Carriers\Domain\ShipmentOutcome;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Pickups\Application\Pickups;
use App\Modules\Shipping\Application\Recovery;
use App\Modules\Shipping\Application\Submission;
use App\Modules\WalletBilling\Application\Ledger;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Predis\Connection\Resource\Exception\StreamInitException;
use Tests\Support\ShippingTestCase;

final class ShipmentLifecycleTest extends ShippingTestCase
{
    use DatabaseMigrations;

    public function test_acceptance_is_persisted_and_captured_once_despite_replays_and_redis_loss(): void
    {
        $f = $this->setupShipment();
        $a = $this->submit($f);
        $this->assertSame($a, $this->submit($f));
        Redis::set('shipping:test:ephemeral', 'lost');
        Redis::del('shipping:test:ephemeral');
        app(Recovery::class)->run();
        app(Recovery::class)->run();
        app(Submission::class)->process($f['m'], $a['submission_id']);
        $this->assertSame(1, $f['gateway']->calls);
        $this->assertDatabaseHas('shipments', ['id' => $f['s']['id'], 'state' => 'accepted', 'financial_state' => 'captured']);
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $f['w'], 'posted' => 7900, 'reserved' => 0]);
        $this->assertDatabaseCount('ledger_transactions', 2);
        $this->assertDatabaseCount('shipping_observations', 1);
        Http::assertNothingSent();
    }

    public function test_definitive_rejection_releases_protected_hold_without_posted_charge(): void
    {
        $f = $this->setupShipment();
        $f['gateway']->outcome = 'rejected';
        $a = $this->submit($f);
        app(Recovery::class)->run();
        $this->assertDatabaseHas('shipments', ['id' => $f['s']['id'], 'state' => 'rejected', 'financial_state' => 'released']);
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $f['w'], 'posted' => 10000, 'reserved' => 0]);
        $this->assertDatabaseHas('balance_holds', ['protected' => true, 'released' => 2100]);
    }

    public function test_lost_response_and_expiry_never_release_or_retry_external_creation(): void
    {
        $f = $this->setupShipment();
        $f['gateway']->throw = true;
        $a = $this->submit($f);
        app(Recovery::class)->run();
        $this->travel(2)->hours();
        app(Recovery::class)->run();
        $this->assertSame(1, $f['gateway']->calls);
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $f['w'], 'posted' => 10000, 'reserved' => 2100]);
        $this->assertDatabaseHas('balance_holds', ['protected' => true, 'unknown' => true]);
        $this->assertDatabaseHas('shipments', ['id' => $f['s']['id'], 'state' => 'unknown', 'financial_state' => 'review']);
    }

    public function test_capture_failure_does_not_erase_acceptance_or_resubmit(): void
    {
        $f = $this->setupShipment();
        $a = $this->submit($f);
        // Crash after successful dispatch but before settlement: observe first, freeze later.
        $op = DB::table('carrier_operations')->first();
        DB::table('carrier_operations')->where('id', $op->id)->update(['state' => 'sending']);
        DB::table('shipments')->where('id', $f['s']['id'])->update(['state' => 'sending']);
        app(Ledger::class)->command($f['m'], $f['w'], 'protect', '0', 'protect', 'protect', 'test', DB::table('balance_holds')->value('id'));
        app(Submission::class)->observe($f['m'], $a['submission_id'], new ShipmentOutcome('accepted', hash('sha256', 'synthetic-acceptance'), 'SYNTHETIC-ACCEPTED'));
        DB::table('wallet_balances')->where('wallet_id', $f['w'])->update(['frozen' => true]);
        app(Recovery::class)->run();
        $this->assertDatabaseHas('shipments', ['state' => 'accepted', 'financial_state' => 'capture_pending']);
        $this->assertDatabaseCount('shipping_observations', 1);
        DB::table('wallet_balances')->where('wallet_id', $f['w'])->update(['frozen' => false]);
        app(Recovery::class)->run();
        $this->assertDatabaseHas('shipments', ['state' => 'accepted', 'financial_state' => 'captured']);
        $this->assertSame(0, $f['gateway']->calls);
    }

    public function test_stale_sending_marker_is_quarantined_after_worker_crash(): void
    {
        $f = $this->setupShipment();
        $a = $this->submit($f);
        DB::table('carrier_operations')->where('id', DB::table('carrier_operations')->value('id'))->update(['state' => 'sending']);
        DB::table('shipments')->where('id', $f['s']['id'])->update(['state' => 'sending', 'updated_at' => now()->subMinutes(6)]);
        app(Ledger::class)->command($f['m'], $f['w'], 'protect', '0', 'protect', 'protect', 'test', DB::table('balance_holds')->value('id'));
        app(Recovery::class)->run();
        $this->assertSame(0, $f['gateway']->calls);
        $this->assertDatabaseHas('shipments', ['state' => 'unknown']);
        $this->assertDatabaseHas('wallet_balances', ['reserved' => 2100]);
    }

    public function test_expired_quote_blocks_before_dispatch_and_releases_only_unsent_hold(): void
    {
        $f = $this->setupShipment();
        $this->submit($f);
        $this->travel(301)->seconds();
        app(Recovery::class)->run();
        $this->assertSame(0, $f['gateway']->calls);
        $this->assertDatabaseHas('shipments', ['state' => 'blocked', 'financial_state' => 'released']);
        $this->assertDatabaseHas('balance_holds', ['protected' => false]);
    }

    public function test_token_revocation_blocks_async_dispatch(): void
    {
        $f = $this->setupShipment();
        $this->submit($f);
        DB::table('personal_access_tokens')->where('tokenable_id', $f['u']->id)->delete();
        app(Recovery::class)->run();
        $this->assertSame(0, $f['gateway']->calls);
        $this->assertDatabaseHas('shipments', ['state' => 'blocked', 'financial_state' => 'released']);
    }

    public function test_suspension_preserves_hold_until_local_settlement_is_permitted(): void
    {
        $f = $this->setupShipment();
        $this->submit($f);
        DB::table('merchants')->where('id', $f['m'])->update(['status' => 'suspended']);
        app(Recovery::class)->run();
        $this->assertSame(0, $f['gateway']->calls);
        $this->assertDatabaseHas('wallet_balances', ['reserved' => 2100]);
        $this->assertDatabaseHas('shipments', ['state' => 'blocked', 'financial_state' => 'release_pending']);
        DB::table('merchants')->where('id', $f['m'])->update(['status' => 'active']);
        app(Recovery::class)->run();
        $this->assertDatabaseHas('wallet_balances', ['reserved' => 0]);
    }

    public function test_insufficient_balance_rolls_back_intent_outbox_and_submission(): void
    {
        $f = $this->setupShipment();
        app(Ledger::class)->command($f['m'], $f['w'], 'reserve', '9000', 'competing', 'competing', 'test', expires: now()->addHour()->toIso8601String());
        $this->bearer($f['token'], 'POST', "/api/v1/shipments/{$f['s']['id']}/submit", ['version' => 2, 'idempotency_key' => 'low-balance-key'])->assertConflict();
        $this->assertDatabaseCount('shipment_submissions', 0);
        $this->assertDatabaseCount('carrier_operations', 0);
        $this->assertDatabaseCount('shipping_outbox', 0);
        $this->assertDatabaseHas('wallet_balances', ['reserved' => 9000]);
    }

    public function test_normal_gateway_rejects_before_financial_effects_and_cannot_be_enabled_by_config(): void
    {
        $f = $this->setupShipment(false);
        config(['shipping.enabled' => true, 'carriers.enabled' => true]);
        $this->bearer($f['token'], 'POST', "/api/v1/shipments/{$f['s']['id']}/submit", ['version' => 2, 'idempotency_key' => 'normal-disabled'])->assertStatus(503);
        $this->assertDatabaseCount('balance_holds', 0);
        $this->assertDatabaseCount('carrier_operations', 0);
        Http::assertNothingSent();
    }

    public function test_pickup_creation_and_cancellation_do_not_change_shipment_or_money(): void
    {
        $f = $this->setupShipment();
        $this->submit($f);
        app(Recovery::class)->run();
        $p = $this->bearer($f['token'], 'POST', '/api/v1/pickups', ['shipment_ids' => [$f['s']['id']], 'window_start' => now()->addDay()->toIso8601String(), 'window_end' => now()->addDay()->addHour()->toIso8601String(), 'timezone' => 'Asia/Riyadh', 'idempotency_key' => 'synthetic-pickup'])->assertCreated()->json('data');
        $this->bearer($f['token'], 'POST', "/api/v1/pickups/{$p['id']}/submit", ['version' => 1, 'idempotency_key' => 'pickup-submit'])->assertOk();
        app(Pickups::class)->recover();
        $this->assertDatabaseHas('pickups', ['id' => $p['id'], 'state' => 'confirmed']);
        $this->bearer($f['token'], 'POST', "/api/v1/pickups/{$p['id']}/cancel", ['version' => 2, 'idempotency_key' => 'pickup-cancel'])->assertOk();
        app(Pickups::class)->recover();
        app(Pickups::class)->recover();
        $this->assertDatabaseHas('pickups', ['id' => $p['id'], 'state' => 'cancelled']);
        $this->assertDatabaseHas('shipments', ['state' => 'accepted', 'financial_state' => 'captured']);
        $this->assertDatabaseHas('wallet_balances', ['posted' => 7900, 'reserved' => 0]);
    }

    public function test_postgresql_outbox_recovers_when_redis_connection_is_unavailable(): void
    {
        $f = $this->setupShipment();
        $this->submit($f);
        $manager = app('redis');
        $redisConfig = config('database.redis');
        $redisConfig['default'] = array_replace($redisConfig['default'], ['host' => '127.0.0.1', 'port' => 1, 'url' => null]);
        Redis::swap(new RedisManager(app(), config('database.redis.client'), $redisConfig));
        try {
            try {
                Redis::connection()->ping();
                $this->fail('Expected an unavailable Redis connection');
            } catch (StreamInitException) {
                $this->assertTrue(true);
            }
            app(Recovery::class)->run();
            $this->assertDatabaseHas('shipments', ['state' => 'accepted', 'financial_state' => 'captured']);
            $this->assertSame(1, $f['gateway']->calls);
        } finally {
            Redis::swap($manager);
        }
    }

    public function test_permission_removal_after_queueing_prevents_dispatch(): void
    {
        $f = $this->setupShipment();
        $this->submit($f);
        DB::table('merchant_role_assignments')->where('merchant_id', $f['m'])->update(['role_id' => DB::table('roles')->where('code', 'merchant.viewer')->value('id')]);
        app(Recovery::class)->run();
        $this->assertSame(0, $f['gateway']->calls);
        $this->assertDatabaseHas('shipments', ['state' => 'blocked', 'financial_state' => 'released']);
    }

    public function test_failure_before_commit_rolls_back_reserve_intent_and_outbox(): void
    {
        $f = $this->setupShipment();
        $token = PersonalAccessToken::findToken($f['token']);
        $f['u']->withAccessToken($token);
        $r = Request::create('/api/v1/shipments', 'POST', ['version' => 2, 'idempotency_key' => 'rollback-submission']);
        $r->setUserResolver(fn () => $f['u']);
        $c = app(MerchantContext::class);
        $c->establish($f['u'], $f['m'], $token);
        try {
            DB::transaction(function () use ($r, $c, $f) {
                app(Submission::class)->create($r, $c, $f['s']['id']);
                throw new \RuntimeException('synthetic crash before commit');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic crash before commit', $e->getMessage());
        }
        $this->assertDatabaseCount('balance_holds', 0);
        $this->assertDatabaseCount('carrier_operations', 0);
        $this->assertDatabaseCount('shipment_submissions', 0);
        $this->assertDatabaseCount('shipping_outbox', 0);
        $this->assertDatabaseHas('wallet_balances', ['reserved' => 0, 'posted' => 10000]);
    }

    public function test_unknown_pickup_never_retries_or_changes_shipment_finances(): void
    {
        $f = $this->setupShipment();
        $this->submit($f);
        app(Recovery::class)->run();
        $p = $this->bearer($f['token'], 'POST', '/api/v1/pickups', ['shipment_ids' => [$f['s']['id']], 'window_start' => now()->addDay()->toIso8601String(), 'window_end' => now()->addDay()->addHour()->toIso8601String(), 'timezone' => 'Asia/Riyadh', 'idempotency_key' => 'unknown-pickup-draft'])->assertCreated()->json('data');
        $f['gateway']->throw = true;
        $this->bearer($f['token'], 'POST', "/api/v1/pickups/{$p['id']}/submit", ['version' => 1, 'idempotency_key' => 'unknown-pickup-submit'])->assertOk();
        app(Pickups::class)->recover();
        $this->travel(6)->minutes();
        app(Pickups::class)->recover();
        $this->assertSame(2, $f['gateway']->calls);
        $this->assertDatabaseHas('pickups', ['state' => 'unknown']);
        $this->assertDatabaseHas('wallet_balances', ['posted' => 7900, 'reserved' => 0]);
        $this->assertDatabaseHas('shipments', ['state' => 'accepted', 'financial_state' => 'captured']);
    }

    public function test_pickup_window_bound_rejects_more_than_twenty_four_hours(): void
    {
        $f = $this->setupShipment(false);
        $this->bearer($f['token'], 'POST', '/api/v1/pickups', ['shipment_ids' => [$f['s']['id']], 'window_start' => now()->addDay()->toIso8601String(), 'window_end' => now()->addDays(3)->toIso8601String(), 'timezone' => 'Asia/Riyadh', 'idempotency_key' => 'oversized-pickup-window'])->assertUnprocessable();
        $this->assertDatabaseCount('pickups', 0);
    }
}
