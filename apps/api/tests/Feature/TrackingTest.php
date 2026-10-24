<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Shipping\Application\Submission;
use App\Modules\Tracking\Application\TrackingNotificationOutbox;
use App\Modules\Tracking\Application\TrackingPollScheduler;
use App\Modules\Tracking\Application\TrackingProviderRegistry;
use App\Modules\Tracking\Application\TrackingService;
use App\Modules\Tracking\Application\TrackingStateProjector;
use App\Modules\Tracking\Domain\TrackingProviderAdapter;
use App\Modules\Tracking\Domain\WebhookAuthenticator;
use App\Modules\Tracking\Infrastructure\DisabledTrackingAdapter;
use App\Modules\Tracking\Jobs\ProcessTracking;
use App\Modules\WalletBilling\Application\Ledger;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\TrackingTestCase;

final class TrackingTest extends TrackingTestCase
{
    use DatabaseMigrations;

    public function test_tracking_drafts_disabled_contracts_and_cross_tenant_access(): void
    {
        $f = $this->trackingFixture();
        app()->instance(TrackingProviderAdapter::class, new DisabledTrackingAdapter);
        $this->refreshTracking($f)->assertStatus(202)->assertJsonPath('data.refreshes.0.state', 'disabled');
        $this->bearer($f['token'], 'GET', '/api/v1/tracking/shipments/'.$f['s']['id'])->assertOk()->assertJsonPath('data.tracking_enabled', false)->assertJsonPath('data.verified_data', false);
        $u = $this->user();
        $m = $this->organization($u);
        $t = $this->token($u, $m);
        $this->bearer($t, 'GET', '/api/v1/tracking/shipments/'.$f['s']['id'])->assertNotFound();
        $this->bearer($t, 'POST', '/api/v1/tracking/refresh', ['shipment_ids' => [$f['s']['id']], 'idempotency_key' => 'cross-merchant-test'])->assertNotFound();
        $this->bearer($t, 'GET', '/api/v1/tracking/shipments?reference=confidential&account_id='.$f['binding']->account_id)->assertOk()->assertJsonCount(0, 'data.shipments');
        $limited = $this->token($f['u'], $f['m'], ['shipments.read']);
        $this->bearer($limited, 'GET', '/api/v1/tracking/shipments')->assertForbidden();
        $this->bearer($limited, 'POST', '/api/v1/tracking/refresh', ['shipment_ids' => [$f['s']['id']], 'idempotency_key' => 'limited-scope-test'])->assertForbidden();
        $this->assertDatabaseCount('tracking_work', 0);
    }

    public function test_poll_idempotency_synthetic_visibility_and_no_financial_or_shipping_effects(): void
    {
        $f = $this->trackingFixture();
        $financial = DB::table('ledger_transactions')->count();
        $calls = $f['gateway']->calls;
        $f['adapter']->events = [$this->event()];
        $first = $this->refreshTracking($f)->assertStatus(202)->json('data.refreshes.0.work_id');
        $this->assertSame($first, $this->refreshTracking($f)->assertStatus(202)->json('data.refreshes.0.work_id'));
        app(TrackingPollScheduler::class)->tick($f['m'], $first);
        app(TrackingPollScheduler::class)->tick($f['m'], $first);
        $this->assertDatabaseCount('carrier_tracking_events', 1);
        $this->assertDatabaseHas('tracking_work', ['id' => $first, 'state' => 'completed']);
        $this->assertDatabaseHas('carrier_tracking_projections', ['status' => 'in_transit']);
        $this->bearer($f['token'], 'GET', '/api/v1/tracking/shipments/'.$f['s']['id'])->assertOk()->assertJsonCount(0, 'data.events')->assertJsonPath('data.verified_data', false);
        $this->assertSame($financial, DB::table('ledger_transactions')->count());
        $this->assertSame($calls, $f['gateway']->calls);
        $this->assertDatabaseHas('shipments', ['id' => $f['s']['id'], 'state' => 'accepted', 'financial_state' => 'captured']);
        $this->assertDatabaseCount('tracking_notifications', 0);
        $this->assertStringNotContainsString('Synthetic location', DB::table('carrier_webhook_deliveries')->value('payload_ciphertext'));
    }

    public function test_duplicate_events_ordering_unknown_codes_and_terminal_preservation(): void
    {
        $f = $this->trackingFixture();
        $ep = $this->endpoint($f);
        $events = [$this->event('delivered', 'TEST_DELIVERED', 10), $this->event('stale', 'TEST_TRANSIT', 20), $this->event('late', 'TEST_TRANSIT', 5), $this->event('unknown', 'UNVERIFIED_AR_CODE', 4)];
        $w = $this->inbound($ep, $this->envelope($f, $events))->assertStatus(202)->json('data.receipt');
        app(TrackingPollScheduler::class)->tick($f['m'], $w);
        $this->assertDatabaseHas('carrier_tracking_projections', ['status' => 'delivered']);
        $this->assertDatabaseCount('tracking_cases', 2);
        $this->assertDatabaseHas('tracking_event_details', ['outcome' => 'stale']);
        $w2 = $this->inbound($ep, $this->envelope($f, $events))->assertStatus(202)->json('data.receipt');
        app(TrackingPollScheduler::class)->tick($f['m'], $w2);
        $this->assertDatabaseCount('carrier_tracking_events', 4);
        $events[0]['code'] = 'TEST_TRANSIT';
        $w3 = $this->inbound($ep, $this->envelope($f, $events))->assertStatus(202)->json('data.receipt');
        app(TrackingPollScheduler::class)->tick($f['m'], $w3);
        $this->assertDatabaseHas('tracking_work', ['id' => $w3, 'state' => 'quarantined']);
        $this->assertDatabaseCount('carrier_tracking_events', 4);
    }

    public function test_newer_receipt_does_not_control_projection_and_equal_conflict_is_reviewed(): void
    {
        $p = app(TrackingStateProjector::class);
        $old = (object) ['status' => 'delivered', 'occurred_at' => '2026-01-01T10:00:00Z'];
        $this->assertSame('stale', $p->outcome($old, 'in_transit', '2026-01-01T09:00:00Z'));
        $this->assertSame('conflict', $p->outcome($old, 'in_transit', '2026-01-01T11:00:00Z'));
        $this->assertSame('conflict', $p->outcome($old, 'returned', '2026-01-01T10:00:00Z'));
        $this->assertSame('projected', $p->outcome($old, 'returning', '2026-01-01T11:00:00Z'));
        $this->assertSame('unknown', $p->outcome($old, 'unknown', '2026-01-01T12:00:00Z'));
        foreach (['returned', 'cancelled'] as $s) {
            $this->assertSame('conflict', $p->outcome((object) ['status' => $s, 'occurred_at' => '2026-01-01T10:00:00Z'], 'in_transit', '2026-01-01T11:00:00Z'));
        }
    }

    public function test_revocation_during_provider_call_fences_event_commit(): void
    {
        $f = $this->trackingFixture();
        $w = $this->refreshTracking($f)->assertStatus(202)->json('data.refreshes.0.work_id');
        $f['adapter']->events = [$this->event()];
        $f['adapter']->duringPoll = function () use ($f) {
            DB::table('personal_access_tokens')->where('tokenable_id', $f['u']->id)->delete();
        };
        app(TrackingPollScheduler::class)->tick($f['m'], $w);
        $this->assertDatabaseHas('tracking_work', ['id' => $w, 'state' => 'blocked']);
        $this->assertDatabaseCount('carrier_tracking_events', 0);
    }

    public function test_suspension_and_revocation_before_work_prevent_effects(): void
    {
        $f = $this->trackingFixture();
        $w = $this->refreshTracking($f)->assertStatus(202)->json('data.refreshes.0.work_id');
        DB::table('merchants')->where('id', $f['m'])->update(['status' => 'suspended']);
        app(TrackingPollScheduler::class)->tick($f['m'], $w);
        $this->assertDatabaseHas('tracking_work', ['id' => $w, 'state' => 'blocked']);
        $this->assertDatabaseCount('carrier_tracking_events', 0);
    }

    public function test_recovery_republishes_postgres_work_and_exhaustion_is_bounded(): void
    {
        $f = $this->trackingFixture();
        $w = $this->refreshTracking($f)->assertStatus(202)->json('data.refreshes.0.work_id');
        $f['adapter']->fail = true;
        for ($i = 1; $i <= 5; $i++) {
            DB::table('tracking_work')->where('id', $w)->update(['available_at' => now()->subSecond()]);
            DB::table('tracking_account_health')->where('account_id', $f['binding']->account_id)->update(['next_call_at' => now()->subSecond(), 'circuit_until' => null]);
            app(TrackingPollScheduler::class)->tick($f['m'], $w);
        }
        $this->assertDatabaseHas('tracking_work', ['id' => $w, 'state' => 'dead_letter', 'attempts' => 5]);
        $this->assertDatabaseCount('carrier_tracking_events', 0);
        $w2 = $this->refreshTracking($f, 'new-after-exhaustion')->assertStatus(202)->json('data.refreshes.0.work_id');
        DB::table('tracking_work')->where('id', $w2)->update(['state' => 'processing', 'available_at' => now()->subSecond(), 'lease_until' => now()->subSecond(), 'lease_token' => (string) Str::uuid()]);
        Queue::fake();
        $this->assertSame(1, app(TrackingPollScheduler::class)->recover());
        Queue::assertPushed(ProcessTracking::class);
    }

    public function test_runtime_scheduler_cannot_poll_unverified_adapter(): void
    {
        $f = $this->trackingFixture();
        app()->instance(TrackingProviderAdapter::class, new DisabledTrackingAdapter);
        $this->assertSame(0, app(TrackingPollScheduler::class)->schedule());
        $this->assertDatabaseCount('tracking_work', 0);
    }

    public function test_account_scoped_reference_collisions_do_not_cross_merchants(): void
    {
        Queue::fake();
        $a = $this->setupShipment();
        $a['gateway']->reference = 'SYNTHETIC-SHARED-REFERENCE';
        $sa = $this->submit($a);
        app(Submission::class)->process($a['m'], $sa['submission_id']);
        app(TrackingService::class)->bind($a['m'], DB::table('shipments')->where('id', $a['s']['id'])->value('carrier_observation_id'));
        $u = $this->user();
        $m = $this->organization($u);
        $b = $this->additionalShipment($a, $u, $m);
        $sb = $this->submit($b);
        app(Submission::class)->process($m, $sb['submission_id']);
        app(TrackingService::class)->bind($m, DB::table('shipments')->where('id', $b['s']['id'])->value('carrier_observation_id'));
        $third = $this->additionalShipment($a, $a['u'], $a['m']);
        $sc = $this->submit($third);
        app(Submission::class)->process($a['m'], $sc['submission_id']);
        app(TrackingService::class)->bind($a['m'], DB::table('shipments')->where('id', $third['s']['id'])->value('carrier_observation_id'));
        $this->assertDatabaseHas('audit_logs', ['merchant_id' => $a['m'], 'subject_id' => $third['s']['id'], 'action' => 'tracking.reference_collision']);
        $this->assertDatabaseCount('tracking_bindings', 2);
        $this->assertSame(1, DB::table('tracking_bindings')->distinct()->count('reference_hash'));
        $this->assertSame(1, DB::table('tracking_bindings')->distinct()->count('account_id'));
        $this->bearer($a['token'], 'GET', '/api/v1/tracking/shipments/'.$b['s']['id'])->assertNotFound();
    }

    public function test_actual_redis_message_loss_keeps_postgres_recoverable_and_job_payload_encrypted(): void
    {
        $f = $this->trackingFixture();
        $work = $this->refreshTracking($f)->assertStatus(202)->json('data.refreshes.0.work_id');
        DB::table('tracking_work')->where('id', $work)->update(['available_at' => now()->subSecond()]);
        $name = 'tracking-isolated-test-'.Str::uuid();
        $redisQueue = new RedisQueue(app('redis'), $name, 'default', 90);
        $redisQueue->setContainer(app());
        try {
            $redisQueue->pushOn($name, new ProcessTracking($f['m'], $work));
            $this->assertSame(1, $redisQueue->size($name));
            $payload = app('redis')->connection('default')->lrange('queues:'.$name, 0, -1)[0];
            $this->assertStringNotContainsString($f['m'], $payload);
            $this->assertStringNotContainsString($work, $payload);
            $redisQueue->clear($name);
            $this->assertSame(0, $redisQueue->size($name));
            $this->assertSame(1, app(TrackingPollScheduler::class)->recover());
            Queue::assertPushed(ProcessTracking::class);
            $f['adapter']->events = [$this->event()];
            app(TrackingPollScheduler::class)->tick($f['m'], $work);
            $this->assertDatabaseHas('tracking_work', ['id' => $work, 'state' => 'completed']);
        } finally {
            $redisQueue->clear($name);
        }
    }

    public function test_notification_intents_are_unique_and_disabled_even_with_explicit_test_consent(): void
    {
        $f = $this->trackingFixture();
        $ep = $this->endpoint($f);
        $work = $this->inbound($ep, $this->envelope($f))->assertStatus(202)->json('data.receipt');
        app(TrackingPollScheduler::class)->tick($f['m'], $work);
        $event = DB::table('tracking_event_details')->value('event_id');
        DB::table('tracking_preferences')->insert(['merchant_id' => $f['m'], 'user_id' => $f['u']->id, 'consent' => true]);
        app(TrackingNotificationOutbox::class)->stage($f['m'], $event);
        $this->assertDatabaseCount('tracking_notifications', 0); // Synthetic evidence cannot cause customer notifications.
        $row = ['id' => (string) Str::uuid(), 'merchant_id' => $f['m'], 'event_id' => $event, 'recipient_id' => $f['u']->id];
        DB::table('tracking_notifications')->insert($row);
        $row['id'] = (string) Str::uuid();
        DB::table('tracking_notifications')->insertOrIgnore($row);
        $this->assertDatabaseCount('tracking_notifications', 1);
        $this->assertDatabaseHas('tracking_notifications', ['state' => 'disabled']);
    }

    private function additionalShipment(array $base, User $u, string $m): array
    {
        $token = $this->token($u, $m);
        $service = $base['d']['service_id'];
        $s = $this->bearer($token, 'POST', '/api/v1/shipments', ['draft' => $base['d'], 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data.shipment');
        $s = $this->bearer($token, 'POST', "/api/v1/shipments/{$s['id']}/validate", ['version' => 1])->assertOk()->json('data.shipment');
        $q = $this->bearer($token, 'POST', '/api/v1/rates/quote', $this->quoteInput($service))->assertCreated()->json('data');
        $this->bearer($token, 'POST', "/api/v1/shipments/{$s['id']}/quote-acceptance", ['version' => $s['version'], 'quote_id' => $q['id'], 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $w = app(Ledger::class)->provision($m);
        $key = (string) Str::uuid();
        app(Ledger::class)->command($m, $w, 'synthetic', '10000', $key, $key, 'synthetic-tracking-reference-fixture');

        return compact('u', 'm', 'token', 's');
    }

    public function test_adapter_flags_cannot_bypass_phase6_carrier_evidence_gate(): void
    {
        $f = $this->trackingFixture();
        $delegate = $f['adapter'];
        app()->instance(TrackingProviderAdapter::class, new class($delegate) implements TrackingProviderAdapter
        {
            public function __construct(private TrackingProviderAdapter $delegate) {}

            public function provider(): string
            {
                return 'aramex';
            }

            public function capabilities(): array
            {
                return ['verified' => true, 'poll' => true, 'webhook' => true, 'synthetic' => false];
            }

            public function poll(object $binding): array
            {
                throw new \LogicException('Unverified transport must not execute');
            }

            public function normalize(array $event): array
            {
                return ['status' => 'unknown'];
            }

            public function authenticator(): WebhookAuthenticator
            {
                return $this->delegate->authenticator();
            }
        });
        $registry = app(TrackingProviderRegistry::class);
        $this->assertFalse($registry->allowed($f['binding']->account_id, 'poll'));
        $this->assertFalse($registry->allowed($f['binding']->account_id, 'webhook'));
        $this->refreshTracking($f)->assertStatus(202)->assertJsonPath('data.refreshes.0.state', 'disabled');
    }

    public function test_shipping_acceptance_commits_independently_of_tracking_availability(): void
    {
        Queue::fake();
        $f = $this->setupShipment();
        $s = $this->submit($f);
        DB::statement('ALTER TABLE tracking_bindings DISABLE TRIGGER tracking_binding_guard');
        DB::unprepared("CREATE OR REPLACE FUNCTION testing_tracking_unavailable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic tracking outage'; END $$; CREATE TRIGGER testing_tracking_outage BEFORE INSERT ON tracking_bindings FOR EACH ROW EXECUTE FUNCTION testing_tracking_unavailable();");
        try {
            app(Submission::class)->process($f['m'], $s['submission_id']);
            $this->assertDatabaseHas('shipments', ['id' => $f['s']['id'], 'state' => 'accepted', 'financial_state' => 'captured']);
            $this->assertDatabaseCount('tracking_bindings', 0);
        } finally {
            DB::statement('DROP TRIGGER testing_tracking_outage ON tracking_bindings');
            DB::statement('ALTER TABLE tracking_bindings ENABLE TRIGGER tracking_binding_guard');
            DB::statement('DROP FUNCTION testing_tracking_unavailable()');
        }
        app(TrackingPollScheduler::class)->schedule();
        $this->assertDatabaseCount('tracking_bindings', 1);
        $this->assertSame(1, $f['gateway']->calls);
    }
}
