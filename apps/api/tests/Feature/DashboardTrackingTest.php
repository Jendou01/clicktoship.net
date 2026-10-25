<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\TrackingTestCase;

final class DashboardTrackingTest extends TrackingTestCase
{
    use DatabaseMigrations;

    public function test_synthetic_acceptance_and_tracking_are_not_verified_dashboard_facts(): void
    {
        $f = $this->trackingFixture();
        $this->bearer($f['token'], 'GET', '/api/v1/dashboard')->assertOk()->assertJsonPath('data.widgets.shipping.submitted', 1)->assertJsonPath('data.widgets.shipping.carrier_accepted', 0)->assertJsonPath('data.widgets.tracking.verified_statuses', []);
    }
}
