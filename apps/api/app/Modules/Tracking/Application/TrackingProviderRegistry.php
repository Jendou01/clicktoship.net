<?php

namespace App\Modules\Tracking\Application;

use App\Modules\Carriers\Domain\CarrierCapabilityRegistry;
use App\Modules\Carriers\Domain\CarrierFailure;
use App\Modules\Tracking\Domain\TrackingProviderAdapter;
use App\Modules\Tracking\Infrastructure\DisabledTrackingAdapter;
use Illuminate\Support\Facades\DB;

final class TrackingProviderRegistry
{
    public function for(string $account): TrackingProviderAdapter
    {
        $a = DB::table('carrier_accounts')->where('id', $account)->first();
        abort_unless($a, 404);
        $adapter = app(TrackingProviderAdapter::class);
        if ($adapter->provider() !== $a->carrier) {
            return new DisabledTrackingAdapter;
        }
        if ($adapter->capabilities()['synthetic'] ?? false) {
            abort_unless(app()->environment('testing') && in_array(DB::connection()->getDatabaseName(), ['clicktoship_test', 'clicktoship_e2e']), 503);
        }

        return $adapter;
    }

    public function allowed(string $account, string $capability): bool
    {
        $c = $this->for($account)->capabilities();

        if (! (($c['verified'] ?? false) && ($c[$capability] ?? false))) {
            return false;
        }
        if ($c['synthetic'] ?? false) {
            return true;
        } // for() enforces the isolated testing database.
        try {
            app(CarrierCapabilityRegistry::class)->assertExternal($capability === 'webhook' ? 'Webhook' : 'TrackShipments', app()->environment());
        } catch (CarrierFailure) {
            return false;
        }

        return false;
    }
}
