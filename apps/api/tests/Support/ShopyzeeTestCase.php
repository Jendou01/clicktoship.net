<?php

namespace Tests\Support;

use App\Modules\Commerce\Application\Tokens;
use App\Modules\Commerce\Domain\ShopyzeeProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class ShopyzeeTestCase extends CommerceTestCase
{
    protected SyntheticShopyzeeProvider $shopyzee;

    protected function setupShopyzee(): array
    {
        $f = $this->setupCommerce();
        $this->shopyzee = new SyntheticShopyzeeProvider;
        app()->instance(ShopyzeeProvider::class, $this->shopyzee);
        $f['salla'] = $f['connection'];
        $f['connection'] = $this->syntheticConnection($f);

        return $f;
    }

    protected function syntheticConnection(array $f, string $platform = 'instance-A', string $tenant = 'tenant-A'): string
    {
        abort_unless(app()->environment('testing') && DB::connection()->getDatabaseName() === 'clicktoship_test', 403);
        $c = (array) DB::table('commerce_connections')->where('id', $f['salla'])->first();
        $c = array_replace($c, ['id' => (string) Str::uuid(), 'provider' => 'shopyzee', 'external_platform_id' => $platform, 'external_store_id' => $tenant, 'display_domain' => 'synthetic.example.test']);
        DB::table('commerce_connections')->insert($c);
        DB::transaction(fn () => app(Tokens::class)->store($f['m'], $c['id'], ['access_token' => 'synthetic-only', 'refresh_token' => 'synthetic-only', 'expires_at' => now()->addHour(), 'scopes' => ['synthetic.orders.read']]));

        return $c['id'];
    }

    // Only disposable integration DB: preserve conservative production downgrade refusal.
    protected function tearDown(): void
    {
        if ($this->app && app()->environment('testing') && DB::connection()->getDatabaseName() === 'clicktoship_test') {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
        parent::tearDown();
    }
}
