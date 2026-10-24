<?php

namespace Tests\Feature;

use App\Modules\Carriers\Domain\ShipmentOutcome;
use App\Modules\Shipping\Application\Recovery;
use App\Modules\Shipping\Application\Submission;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\ShippingTestCase;

final class ShipmentReconciliationTest extends ShippingTestCase
{
    use DatabaseMigrations;

    private function unknown(): array
    {
        $f = $this->setupShipment();
        $f['gateway']->outcome = 'unknown';
        $a = $this->submit($f);
        app(Recovery::class)->run();
        $observation = app(Submission::class)->observe($f['m'], $a['submission_id'], new ShipmentOutcome('accepted', hash('sha256', 'synthetic-late-evidence'), 'SYNTHETIC-LATE'));

        return $f + compact('a', 'observation');
    }

    public function test_unknown_requires_independent_mfa_grants_and_evidence_without_rewriting_carrier_terminal_state(): void
    {
        $f = $this->unknown();
        $maker = $this->platformUser();
        $makerApprover = $this->platformUser();
        $g = $this->grant($maker, $makerApprover, $f['m'], ['platform.shipments.view', 'platform.shipments.reconcile', 'platform.finance.reconcile']);
        $makerJar = $this->jar;
        $base = "/api/v1/admin/merchants/{$f['m']}/shipping";
        $this->browser('GET', "$base/exceptions?access_grant=$g")->assertOk();
        $r = $this->browser('POST', "$base/submissions/{$f['a']['submission_id']}/reconciliations", ['access_grant' => $g, 'observation_id' => $f['observation'], 'reason' => 'Synthetic independently evidenced acceptance'])->assertCreated()->json('data.id');
        $this->browser('POST', "$base/reconciliations/$r/approve", ['access_grant' => $g])->assertForbidden();
        $checker = $this->platformUser();
        $checkerApprover = $this->platformUser();
        $cg = $this->grant($checker, $checkerApprover, $f['m'], ['platform.shipments.view', 'platform.shipments.reconcile', 'platform.finance.reconcile']);
        $this->browser('POST', "$base/reconciliations/$r/approve", ['access_grant' => $cg])->assertOk()->assertJsonPath('data.financial_state', 'captured');
        $this->browser('POST', "$base/reconciliations/$r/approve", ['access_grant' => $cg])->assertOk();
        $this->assertDatabaseHas('shipments', ['state' => 'unknown', 'financial_state' => 'captured']);
        $this->assertDatabaseHas('carrier_operations', ['state' => 'unknown']);
        $this->assertDatabaseHas('balance_holds', ['unknown' => true, 'protected' => true, 'captured' => 2100]);
        $this->assertDatabaseCount('ledger_transactions', 2);
        $this->assertSame(1, $f['gateway']->calls);
    }

    public function test_revoked_maker_session_prevents_resolution_even_with_current_checker(): void
    {
        $f = $this->unknown();
        $maker = $this->platformUser();
        $g = $this->grant($maker, $this->platformUser(), $f['m'], ['platform.shipments.reconcile']);
        $base = "/api/v1/admin/merchants/{$f['m']}/shipping";
        $id = $this->browser('POST', "$base/submissions/{$f['a']['submission_id']}/reconciliations", ['access_grant' => $g, 'observation_id' => $f['observation'], 'reason' => 'Synthetic evidence resolution proposal'])->assertCreated()->json('data.id');
        DB::table('auth_sessions')->where('user_id', $maker->id)->update(['revoked_at' => now()]);
        $checker = $this->platformUser();
        $cg = $this->grant($checker, $this->platformUser(), $f['m'], ['platform.shipments.reconcile', 'platform.finance.reconcile']);
        $this->browser('POST', "$base/reconciliations/$id/approve", ['access_grant' => $cg])->assertForbidden();
        $this->assertDatabaseHas('wallet_balances', ['reserved' => 2100, 'posted' => 10000]);
        $this->assertDatabaseCount('financial_hold_resolutions', 0);
    }

    public function test_super_admin_without_exact_grant_and_mfa_cannot_read_exceptions(): void
    {
        $f = $this->setupShipment(false);
        $u = $this->platformUser();
        $this->login($u);
        $this->browser('GET', "/api/v1/admin/merchants/{$f['m']}/shipping/exceptions")->assertForbidden();
    }

    public function test_conflicting_observation_cannot_reconcile_or_reverse_an_already_settled_hold(): void
    {
        $f = $this->setupShipment();
        $a = $this->submit($f);
        app(Recovery::class)->run();
        app(Submission::class)->observe($f['m'], $a['submission_id'], new ShipmentOutcome('rejected', hash('sha256', 'conflicting-synthetic')));
        app(Recovery::class)->run();
        $this->assertDatabaseHas('shipments', ['state' => 'accepted', 'financial_state' => 'captured']);
        $this->assertDatabaseCount('ledger_transactions', 2);
        $this->assertDatabaseCount('shipping_observations', 2);
        $this->assertDatabaseHas('wallet_balances', ['posted' => 7900, 'reserved' => 0]);
    }
}
