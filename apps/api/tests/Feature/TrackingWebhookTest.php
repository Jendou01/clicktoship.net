<?php

namespace Tests\Feature;

use App\Modules\Tracking\Application\TrackingPollScheduler;
use App\Modules\Tracking\Domain\TrackingProviderAdapter;
use App\Modules\Tracking\Infrastructure\DisabledTrackingAdapter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Tests\Support\TrackingTestCase;

final class TrackingWebhookTest extends TrackingTestCase
{
    use DatabaseMigrations;

    public function test_forgery_and_replays_cannot_change_state(): void
    {
        $f = $this->trackingFixture();
        $ep = $this->endpoint($f);
        $body = $this->envelope($f);
        $this->inbound($ep, $body, false)->assertUnauthorized();
        $this->assertDatabaseCount('tracking_work', 0);
        $id = $this->inbound($ep, $body)->assertStatus(202)->json('data.receipt');
        $this->inbound($ep, $body)->assertStatus(202)->assertJsonPath('data.duplicate', true)->assertJsonPath('data.receipt', $id);
        $changed = $body;
        $changed['events'][0]['code'] = 'TEST_DELIVERED';
        $this->inbound($ep, $changed)->assertConflict();
        $changed = $body;
        $changed['delivery_id'] = 'different-delivery';
        $this->inbound($ep, $changed)->assertConflict();
        $this->assertDatabaseCount('tracking_work', 1);
        $this->inbound($ep, $this->envelope($f, [], ['sent_at' => now()->subMinutes(10)->toIso8601String()]))->assertUnprocessable();
        app(TrackingPollScheduler::class)->tick($f['m'], $id);
        $this->assertDatabaseCount('carrier_tracking_events', 1);
    }

    public function test_unknown_reference_is_private_quarantine_and_external_contract_gate_stays_closed(): void
    {
        $f = $this->trackingFixture();
        $ep = $this->endpoint($f);
        $this->inbound($ep, $this->envelope($f, [], ['reference' => 'unknown-private-reference']))->assertStatus(202);
        $this->assertDatabaseHas('tracking_work', ['state' => 'quarantined', 'error_code' => 'REFERENCE_UNBOUND']);
        $this->assertDatabaseCount('carrier_tracking_events', 0);
        app()->instance(TrackingProviderAdapter::class, new DisabledTrackingAdapter);
        $this->inbound($ep, $this->envelope($f))->assertStatus(503);
        $this->assertDatabaseCount('tracking_work', 1);
    }

    public function test_malicious_content_is_bounded_and_urls_never_fetched(): void
    {
        $f = $this->trackingFixture();
        $ep = $this->endpoint($f);
        $this->inbound($ep, str_repeat('x', 65537))->assertStatus(413);
        $this->inbound($ep, '<xml/>', true, 'application/xml')->assertStatus(415);
        $events = [$this->event()];
        $events[0]['location'] = 'https://169.254.169.254/latest/meta-data';
        $events[0]['url'] = 'http://localhost/secrets';
        $id = $this->inbound($ep, $this->envelope($f, $events))->assertStatus(202)->json('data.receipt');
        app(TrackingPollScheduler::class)->tick($f['m'], $id);
        $this->assertDatabaseCount('carrier_tracking_events', 1);
        Http::assertNothingSent();
    }

    public function test_invalid_timestamp_and_oversized_event_set_roll_back_atomically(): void
    {
        $f = $this->trackingFixture();
        $ep = $this->endpoint($f);
        $events = [$this->event(), $this->event('future')];
        $events[1]['at'] = now()->addDay()->toIso8601String();
        $id = $this->inbound($ep, $this->envelope($f, $events))->assertStatus(202)->json('data.receipt');
        app(TrackingPollScheduler::class)->tick($f['m'], $id);
        $this->assertDatabaseCount('carrier_tracking_events', 0);
        $this->assertDatabaseHas('tracking_work', ['id' => $id, 'state' => 'quarantined']);
        $this->inbound($ep, $this->envelope($f, array_fill(0, 101, $this->event())))->assertUnprocessable();
    }
}
