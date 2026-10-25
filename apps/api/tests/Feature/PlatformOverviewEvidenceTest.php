<?php

namespace Tests\Feature;

use App\Modules\WalletBilling\Application\Ledger;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\TrackingTestCase;

final class PlatformOverviewEvidenceTest extends TrackingTestCase
{
    use DatabaseMigrations;

    public function test_recorded_synthetic_success_cannot_inflate_live_carrier_or_tracking_kpis(): void
    {
        $f = $this->trackingFixture();
        $a = $this->platformUser();
        $this->adminLogin($a);
        $this->browser('GET', '/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.widgets.shipping.submitted_in_period', 1)->assertJsonPath('data.widgets.shipping.verified_accepted_in_period', 0)->assertJsonPath('data.widgets.tracking.verified_statuses', [])->assertJsonPath('data.widgets.carriers.sandbox_verified', false)->assertJsonPath('data.widgets.carriers.external_enabled', false);
        $this->assertDatabaseHas('shipments', ['id' => $f['s']['id'], 'state' => 'accepted']);
    }

    public function test_existing_target_financial_authority_reads_exact_amount_without_new_effects(): void
    {
        $owner = $this->user();
        $m = $this->organization($owner);
        $w = app(Ledger::class)->provision($m);
        app(Ledger::class)->command($m, $w, 'synthetic', '12345', 'private-synthetic', 'private-synthetic', 'test');
        $a = $this->platformUser();
        $b = $this->platformUser();
        $g = $this->grant($a, $b, $m, ['platform.wallet.view']);
        $this->browser('GET', "/api/v1/admin/dashboard/merchants/$m?section=finance&access_grant=$g")->assertOk()->assertJsonPath('data.wallets.0.posted', '12345')->assertJsonPath('data.wallets.0.currency', 'SAR');
        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertDatabaseCount('carrier_operations', 0);
    }
}
