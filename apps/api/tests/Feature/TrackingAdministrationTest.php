<?php

namespace Tests\Feature;

use App\Modules\Tracking\Application\TrackingPollScheduler;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\TrackingTestCase;

final class TrackingAdministrationTest extends TrackingTestCase
{
    use DatabaseMigrations;

    public function test_recent_mfa_and_independent_target_grant_required_and_review_has_no_state_effect(): void
    {
        $f = $this->trackingFixture();
        $ep = $this->endpoint($f);
        $w = $this->inbound($ep, $this->envelope($f, [$this->event('unknown', 'UNDOCUMENTED')]))->assertStatus(202)->json('data.receipt');
        app(TrackingPollScheduler::class)->tick($f['m'], $w);
        $case = DB::table('tracking_cases')->first();
        $actor = $this->platformUser();
        $checker = $this->platformUser();
        $this->login($actor);
        $this->browser('GET', '/api/v1/admin/tracking/health')->assertForbidden();
        $g = $this->grant($actor, $checker, $f['m'], ['platform.tracking.view', 'platform.tracking.review']);
        $this->browser('GET', '/api/v1/admin/tracking/health')->assertOk()->assertJsonPath('data.external_enabled', false);
        $this->browser('GET', "/api/v1/admin/merchants/{$f['m']}/tracking")->assertUnprocessable();
        $result = $this->browser('GET', "/api/v1/admin/merchants/{$f['m']}/tracking?access_grant=$g")->assertOk()->json('data');
        $this->assertArrayNotHasKey('payload_ciphertext', $result['deliveries'][0]);
        $path = "/api/v1/admin/merchants/{$f['m']}/tracking/cases/{$case->id}/reviews";
        $body = ['access_grant' => $g, 'idempotency_key' => 'review-only-command', 'note' => 'Synthetic human review only; no state correction'];
        $id = $this->browser('POST', $path, $body)->assertCreated()->assertJsonPath('data.effect', 'review_only')->json('data.id');
        $this->browser('POST', $path, $body)->assertCreated()->assertJsonPath('data.id', $id);
        $this->browser('POST', $path, $body + ['status' => 'delivered'])->assertUnprocessable();
        $this->assertDatabaseCount('tracking_reviews', 1);
        $this->assertDatabaseHas('shipments', ['id' => $f['s']['id'], 'state' => 'accepted', 'financial_state' => 'captured']);
        DB::table('platform_access_grants')->where('id', $g)->update(['revoked_at' => now()]);
        $this->browser('GET', "/api/v1/admin/merchants/{$f['m']}/tracking?access_grant=$g")->assertForbidden();
        $this->bearer($f['token'], 'GET', '/api/v1/admin/tracking/health')->assertStatus(400); // Existing RequireSession rejects sessionless API tokens before platform access.
    }

    public function test_stale_originating_browser_session_blocks_poll_even_with_valid_actor(): void
    {
        $f = $this->trackingFixture();
        $this->jar = [];
        $this->login($f['u']);
        $this->browser('POST', '/api/v1/merchants/select', ['merchant_id' => $f['m']])->assertOk();
        $w = $this->browser('POST', '/api/v1/tracking/refresh', ['shipment_ids' => [$f['s']['id']], 'idempotency_key' => 'session-poll-command'])->assertStatus(202)->json('data.refreshes.0.work_id');
        $origin = DB::table('tracking_work')->where('id', $w)->first();
        $this->assertNotNull($origin->origin_session_id);
        DB::table('auth_sessions')->where('id', $origin->origin_session_id)->update(['revoked_at' => now()]);
        app(TrackingPollScheduler::class)->tick($f['m'], $w);
        $this->assertDatabaseHas('tracking_work', ['id' => $w, 'state' => 'blocked']);
        $this->assertDatabaseCount('carrier_tracking_events', 0);
    }
}
