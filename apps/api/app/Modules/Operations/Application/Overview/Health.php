<?php

namespace App\Modules\Operations\Application\Overview;

use App\Http\Controllers\HealthController;
use App\Modules\Carriers\Domain\CarrierCapabilityRegistry;
use App\Support\SchemaIntegrity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

final class Health
{
    public function read(): array
    {
        $checks = ['api' => 'running', 'dependencies' => 'unknown', 'schema' => 'unknown', 'horizon' => 'unknown'];
        try {
            $r = app(HealthController::class)->ready();
            $checks['dependencies'] = $r->getStatusCode() === 200 ? 'ready' : 'unavailable';
        } catch (Throwable) {
        }
        try {
            $checks['schema'] = app(SchemaIntegrity::class)->failures() === [] ? 'verified' : 'drift_detected';
        } catch (Throwable) {
        }
        try {
            $masters = array_values(array_filter(app(MasterSupervisorRepository::class)->all(), fn ($m) => $m->environment === app()->environment()));
            $checks['horizon'] = ! $masters ? 'inactive' : (collect($masters)->contains(fn ($m) => $m->status === 'paused') ? 'paused' : 'running');
        } catch (Throwable) {
        }
        $queues = [];
        foreach (['critical', 'default', 'carrier', 'carrier-events', 'shipments', 'bulk', 'tracking', 'customs'] as $q) {
            try {
                $queues[] = ['queue' => $q, 'pending' => Queue::connection('redis')->size($q)];
            } catch (Throwable) {
                $queues[] = ['queue' => $q, 'pending' => null];
            }
        }
        $failed = null;
        try {
            $failed = DB::table('failed_jobs')->count();
        } catch (Throwable) {
        }

        return ['checks' => $checks, 'queues' => $queues, 'failed_jobs' => $failed, 'scheduler' => 'not_independently_observable', 'recovery_modules' => ['shipping', 'bulk', 'tracking', 'customs', 'commerce'], 'capabilities' => app(CarrierCapabilityRegistry::class)->all(), 'production_readiness' => 'not_certified', 'generated_at' => now()->toIso8601String()];
    }

    public function settings(): array
    {
        return ['reporting_timezone' => 'UTC', 'mfa_window_minutes' => 15, 'access_grant_max_minutes' => 15, 'currencies' => DB::table('financial_currencies')->orderBy('code')->limit(200)->get(['code', 'exponent', 'enabled']), 'bulk_limits' => ['max_bytes' => config('bulk.max_bytes'), 'max_rows' => config('bulk.max_rows'), 'max_cell_bytes' => config('bulk.max_cell_bytes')], 'gates' => ['carrier_requests' => false, 'wallet_funding' => false, 'financial_corrections' => false, 'customer_notifications' => false, 'customs_clearance' => false], 'editable' => false, 'policy' => 'existing_versioned_domain_workflows_only'];
    }
}
