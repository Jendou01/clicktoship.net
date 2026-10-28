<?php

namespace Tests\Feature;

use App\Http\Middleware\DemoBoundary;
use App\Support\Security\DemoFixtures;
use App\Support\Security\DisposableDemo;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class DisposableDemoTest extends TestCase
{
    public function test_complete_demo_configuration_accepts_only_its_exact_boundary(): void
    {
        $this->app->detectEnvironment(fn () => 'demo');
        config([
            'demo.enabled' => true,
            'demo.acknowledgment' => 'SYNTHETIC_ONLY_PERMANENT_DATA_LOSS_ACCEPTED',
            'demo.instance' => 'cts-demo-123456abcdef',
            'app.debug' => false,
            'app.url' => 'https://staging.zeeship.cloud',
            'security.allowed_hosts' => ['staging.zeeship.cloud'],
            'security.trusted_proxies' => ['172.30.73.10'],
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => 'postgres',
            'database.connections.pgsql.database' => 'clicktoship_demo',
            'database.connections.pgsql.username' => 'cts_runtime',
            'database.connections.pgsql.url' => null,
            'database.redis.default.host' => 'redis',
            'database.redis.default.url' => null,
            'database.redis.options.prefix' => 'cts-demo-123456abcdef:',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'mailpit',
            'mail.mailers.smtp.port' => 1025,
            'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
            'session.driver' => 'redis',
            'session.encrypt' => true,
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'session.domain' => null,
            'sanctum.stateful' => ['staging.zeeship.cloud'],
            'finance.provider' => 'disabled',
            'finance.corrections_enabled' => false,
            'analytics.test_artifacts_enabled' => false,
            'bulk.structural_only' => false,
        ]);
        DisposableDemo::assertConfiguration();
        $this->assertSame('clicktoship_demo', config('database.connections.pgsql.database'));
        config(['mail.mailers.smtp.host' => 'external.example.com']);
        $this->expectException(LogicException::class);
        DisposableDemo::assertConfiguration();
    }

    public function test_production_cannot_opt_into_demo_with_one_flag(): void
    {
        config(['demo.enabled' => true]);
        $this->expectException(LogicException::class);
        DisposableDemo::assertConfiguration();
    }

    public function test_normal_testing_policy_is_unchanged_when_demo_is_absent(): void
    {
        config(['demo.enabled' => false]);
        DisposableDemo::assertConfiguration();
        $this->assertTrue(app()->environment('testing'));
    }

    public function test_demo_fixtures_refuse_every_non_demo_database_before_writes(): void
    {
        $this->expectException(HttpException::class);
        app(DemoFixtures::class)->create('unused', 'unused');
    }

    public function test_demo_registration_rejects_non_synthetic_identity(): void
    {
        config(['demo.enabled' => true]);
        $this->expectException(HttpException::class);
        (new DemoBoundary)->handle(Request::create('/api/v1/auth/register', 'POST', ['email' => 'person@example.com']), fn () => response('unreachable'));
    }

    public function test_demo_cannot_submit_to_carrier_or_modify_wallet(): void
    {
        config(['demo.enabled' => true]);
        foreach (['/api/v1/shipments/any/submit', '/api/v1/wallet/fund', '/api/v1/commerce/salla/connect'] as $path) {
            try {
                (new DemoBoundary)->handle(Request::create($path, 'POST'), fn () => response('unreachable'));
                $this->fail('Live operation was not rejected.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
    }
}
