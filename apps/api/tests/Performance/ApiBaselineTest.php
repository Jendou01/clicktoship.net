<?php

namespace Tests\Performance;

use App\Modules\Analytics\Application\Exports;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AnalyticsTestCase;
use Tests\Support\MeasuresQa;

final class ApiBaselineTest extends AnalyticsTestCase
{
    use DatabaseMigrations, MeasuresQa;

    public function test_source_backed_api_baseline(): void
    {
        $this->analytics(true);
        $f = $this->setupCommerce();
        $this->import($f, array_map(fn ($i) => $this->order('qa-order-'.$i), range(1, 100)));
        $second = $this->organization($f['u']);
        $this->select($f['m']);
        $draft = $this->draft($f['f']['service']);
        foreach (range(1, 100) as $i) {
            $this->ip = '2001:db8:16:'.dechex($i).'::1';
            $this->browser('POST', '/api/v1/shipments', ['draft' => $draft, 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        }
        $this->measure('merchant_switch', fn ($i) => $this->browser('POST', '/api/v1/merchants/select', ['merchant_id' => $i % 2 ? $f['m'] : $second])->assertOk());
        $this->select($f['m']);
        foreach (['shipment_list' => '/shipments', 'tracking_query' => '/tracking/shipments', 'merchant_dashboard' => '/dashboard?range=7d', 'analytics' => '/analytics/reports/shipment_summary?range=7d', 'commerce_orders' => '/commerce/orders', 'pricing_services' => '/rates'] as $name => $path) {
            $this->ip = '2001:db8:16:'.dechex(random_int(1000, 60000)).'::1';
            $this->measure($name, fn () => $this->browser('GET', '/api/v1'.$path)->assertOk());
        }
        $this->ip = '2001:db8:16:aaaa::1';
        $this->measure('draft_creation', fn () => $this->browser('POST', '/api/v1/shipments', ['draft' => $draft, 'idempotency_key' => (string) Str::uuid()])->assertCreated());
        $this->ip = '2001:db8:16:bbbb::1';
        $this->measure('pricing_quote', fn () => $this->browser('POST', '/api/v1/rates/quote', array_replace($this->quoteInput($f['f']['service']), ['idempotency_key' => (string) Str::uuid()]))->assertCreated());
        $this->ip = '2001:db8:16:cccc::1';
        $this->measure('synthetic_export_generation', function ($i) {
            $e = $this->export(['idempotency_key' => 'qa-export-'.$i]);
            app(Exports::class)->tick($e['id']);
            $this->assertDatabaseHas('report_exports', ['id' => $e['id'], 'state' => 'ready']);

            return $this->browser('GET', '/api/v1/analytics/exports/'.$e['id'].'/download')->assertOk();
        }, 10);
        $a = $this->platformUser();
        $this->adminLogin($a);
        $this->measure('platform_dashboard', fn () => $this->browser('GET', '/api/v1/admin/dashboard?range=7d')->assertOk());
        $this->measure('admin_approvals', fn () => $this->browser('GET', '/api/v1/admin/dashboard/approvals')->assertOk());
        config(['hashing.bcrypt.rounds' => 12]);
        app('hash')->forgetDrivers();
        $u = $this->user();
        $this->assertSame(12, password_get_info($u->password)['options']['cost']);
        $this->measure('login_bcrypt12', function ($i) use ($u) {
            $this->ip = '2001:db8:17:'.dechex($i + 1).'::1';
            $this->csrf();

            return $this->browser('POST', '/api/v1/auth/login', ['email' => $u->email, 'password' => self::PASSWORD])->assertOk();
        });
        $this->measure('invalid_existing_login_bcrypt12', function ($i) use ($u) {
            $this->ip = '2001:db8:18:'.dechex($i + 1).'::1';
            $this->csrf();

            return $this->browser('POST', '/api/v1/auth/login', ['email' => $u->email, 'password' => 'not-the-user-password'])->assertUnprocessable();
        });
        $this->measure('unknown_login_bcrypt12', function ($i) {
            $this->ip = '2001:db8:19:'.dechex($i + 1).'::1';
            $this->csrf();

            return $this->browser('POST', '/api/v1/auth/login', ['email' => 'unknown-'.Str::uuid().'@example.test', 'password' => 'not-a-real-password'])->assertUnprocessable();
        });
        $plan = DB::select('EXPLAIN (ANALYZE,BUFFERS,FORMAT JSON) SELECT id FROM shipments WHERE merchant_id=? ORDER BY id DESC LIMIT 25', [$f['m']]);
        fwrite(STDERR, 'QA_PLAN '.json_encode($plan).PHP_EOL);
        $this->emitMeasures();
    }
}
