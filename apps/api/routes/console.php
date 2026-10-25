<?php

use App\Models\User;
use App\Modules\Bulk\Application\Processor;
use App\Modules\Commerce\Application\Work;
use App\Modules\Customs\Application\DocumentWork;
use App\Modules\Identity\Application\Audit;
use App\Modules\Operations\Application\PlatformSecurity;
use App\Modules\Pickups\Application\Pickups;
use App\Modules\Shipping\Application\Recovery;
use App\Modules\Shipping\Jobs\ProcessShipment;
use App\Modules\Tracking\Application\TrackingHealthMonitor;
use App\Modules\Tracking\Application\TrackingPollScheduler;
use App\Modules\WalletBilling\Application\Reconciliation;
use App\Support\SchemaIntegrity;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('platform:bootstrap {first : Existing verified MFA-enrolled user UUID} {second : Independent second verified MFA-enrolled user UUID}', function (): void {
    DB::transaction(function (): void {
        PlatformSecurity::lock();
        $ids = [$this->argument('first'), $this->argument('second')];
        if ($ids[0] === $ids[1] || DB::table('platform_role_assignments')->exists()) {
            throw new RuntimeException('Bootstrap requires two independent administrators and an empty platform assignment table.');
        }
        $users = User::whereIn('id', $ids)->whereNull('suspended_at')->whereNotNull('email_verified_at')->orderBy('id')->lockForUpdate()->get();
        if ($users->count() !== 2 || DB::table('user_mfa_credentials')->whereIn('user_id', $ids)->whereNotNull('confirmed_at')->count() !== 2) {
            throw new RuntimeException('Both existing accounts must be verified, active and MFA-enrolled.');
        }
        $role = DB::table('roles')->where('code', 'platform.super_admin')->value('id');
        foreach ($users as $user) {
            DB::table('platform_role_assignments')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'role_id' => $role, 'role_scope' => 'platform', 'created_at' => now()]);
            Audit::record('platform.bootstrap', null, subject: $user->id);
        }
        $this->info('Two existing MFA-enrolled users assigned; no credentials created.');
    });
})->purpose('One-time audited bootstrap of two independent platform administrators');

Artisan::command('finance:outbox', function (): void {
    $this->info((string) app(Reconciliation::class)->drain());
})->purpose('Process committed financial monitor events idempotently');
Schedule::command('finance:outbox')->everyMinute()->withoutOverlapping();

Artisan::command('finance:reconcile', function (): void {
    DB::table('wallets')->orderBy('id')->chunkById(100, function ($wallets): void {
        foreach ($wallets as $wallet) {
            app(Reconciliation::class)->run($wallet->merchant_id, $wallet->id);
        }
    });
    $this->info('Structural reconciliation completed; no payment or settlement performed.');
})->purpose('Reconcile ledger and reservation projections without changing financial history');
Schedule::command('finance:reconcile')->daily()->withoutOverlapping();

Artisan::command('shipping:recover', function (): void {
    $this->info((string) app(Recovery::class)->run());
    app(Pickups::class)->recover();
})->purpose('Recover durable shipping work; quarantine uncertain dispatches without external retry');
Schedule::command('shipping:recover')->everyMinute()->withoutOverlapping();

Artisan::command('shipping:outbox', function (): void {
    DB::table('shipping_outbox as o')->leftJoin('shipping_deliveries as d', 'd.event_id', '=', 'o.id')->whereNull('d.event_id')->select('o.*')->orderBy('o.created_at')->chunk(100, function ($rows): void {
        foreach ($rows as $row) {
            ProcessShipment::dispatch($row->merchant_id, $row->submission_id);
        }
    });
})->purpose('Publish encrypted shipment jobs; PostgreSQL recovery remains authoritative');

Artisan::command('schema:verify', function (): int {
    $failures = app(SchemaIntegrity::class)->failures();
    if ($failures !== []) {
        $this->error('Schema drift: '.implode(', ', $failures));

        return 1;
    }
    $this->info('Security-critical shipment schema verified.');

    return 0;
})->purpose('Read-only verification of critical schema independently of migration history');

Artisan::command('e2e:rebuild', function (): int {
    if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'clicktoship_e2e') {
        $this->error('Refusing reset outside testing/clicktoship_e2e.');

        return 1;
    }

    return $this->call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
})->purpose('Rebuild only the explicitly disposable browser database');

Artisan::command('bulk:recover', function (): void {
    $this->info((string) app(Processor::class)->recover());
})->purpose('Republish durable pending import work without requiring Redis delivery history');
Schedule::command('bulk:recover')->everyMinute()->withoutOverlapping();

Artisan::command('tracking:recover', function (): void {
    $this->info((string) app(TrackingPollScheduler::class)->recover());
})->purpose('Republish durable tracking work, with bounded leases and no shipment creation');
Schedule::command('tracking:recover')->everyMinute()->withoutOverlapping();
Artisan::command('tracking:schedule', function (): void {
    $this->info((string) app(TrackingPollScheduler::class)->schedule());
})->purpose('Incremental tracking backfill; external polling remains evidence-gated');
Schedule::command('tracking:schedule')->everyMinute()->withoutOverlapping();
Artisan::command('tracking:retention-candidates', function (): void {
    $this->info((string) app(TrackingHealthMonitor::class)->retentionCandidates());
})->purpose('Count retention review candidates without deleting encrypted history');

Artisan::command('customs:recover', function (): void {
    $this->info((string) app(DocumentWork::class)->recover());
})->purpose('Recover durable draft document work without external filings or charges');
Schedule::command('customs:recover')->everyMinute()->withoutOverlapping();

Artisan::command('commerce:recover', function (): void {
    $this->info((string) app(Work::class)->recover());
})->purpose('Recover durable authorized commerce work without live carrier or financial operations');
Schedule::command('commerce:recover')->everyMinute()->withoutOverlapping();
