<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_is_minimal_and_does_not_reflect_user_request_ids(): void
    {
        $response = $this->withHeader('X-Request-ID', 'untrusted-secret')->getJson('/api/v1/health/live');
        $response->assertOk()->assertJsonPath('data.status', 'live')->assertJsonPath('error', null);
        $id = $response->json('meta.request_id');
        $this->assertTrue(Str::isUuid($id));
        $this->assertSame($id, $response->headers->get('X-Request-ID'));
        $this->assertStringNotContainsString('untrusted-secret', $response->getContent());
    }

    public function test_readiness_uses_actual_postgres_and_redis(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->getJson('/api/v1/health/ready')->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.checks', ['database' => true, 'redis' => true, 'schema' => true]);
    }

    public function test_redis_failure_returns_503_without_connection_details(): void
    {
        Redis::shouldReceive('connection')->once()->andThrow(new RuntimeException('secret-redis-password'));
        $this->getJson('/api/v1/health/ready')->assertStatus(503)
            ->assertJsonPath('error.code', 'DEPENDENCIES_UNAVAILABLE')
            ->assertJsonPath('error.details.checks.redis', false)
            ->assertDontSee('secret-redis-password');
    }

    public function test_database_failure_is_not_reported_as_ready(): void
    {
        DB::shouldReceive('select')->once()->andThrow(new RuntimeException('secret-db-password'));
        $this->getJson('/api/v1/health/ready')->assertStatus(503)
            ->assertJsonPath('error.details.checks.database', false)
            ->assertJsonPath('error.details.checks.schema', false)
            ->assertDontSee('secret-db-password');
    }

    public function test_missing_schema_is_not_reported_as_ready(): void
    {
        Schema::shouldReceive('hasTable')->with('migrations')->once()->andReturn(false);
        $this->getJson('/api/v1/health/ready')->assertStatus(503)
            ->assertJsonPath('error.details.checks.schema', false);
    }

    public function test_pending_migrations_are_not_reported_as_ready(): void
    {
        DB::table('migrations')->where('migration', '2026_10_23_000000_create_personal_access_tokens_table')->delete();
        $this->getJson('/api/v1/health/ready')->assertStatus(503)
            ->assertJsonPath('error.details.checks.schema', false);
    }

    public function test_missing_resource_has_consistent_safe_error_envelope(): void
    {
        $response = $this->getJson('/api/v1/shipments/not-a-real-id');
        $response->assertNotFound()->assertJsonPath('data', null)->assertJsonPath('error.code', 'NOT_FOUND');
        $this->assertSame($response->json('meta.request_id'), $response->headers->get('X-Request-ID'));
        $this->assertArrayNotHasKey('exception', $response->json());
    }

    public function test_business_and_dashboard_routes_are_not_exposed(): void
    {
        $this->postJson('/api/v1/shipments')->assertNotFound();
        $this->postJson('/api/v1/wallet/topups')->assertNotFound();
        $this->get('/horizon')->assertNotFound();
        $this->assertFalse(config('platform.carrier_operations_enabled'));
        $this->assertFalse(config('platform.payments_enabled'));
    }

    public function test_horizon_worker_commands_exist_without_dashboard_routes(): void
    {
        $commands = Artisan::all();
        $this->assertArrayHasKey('horizon', $commands);
        $this->assertArrayHasKey('horizon:status', $commands);
        foreach (app('router')->getRoutes() as $route) {
            $this->assertFalse(str_starts_with($route->getName() ?? '', 'horizon.'));
        }
        $this->get('/horizon')->assertNotFound();
    }

    public function test_health_rate_limit_returns_a_safe_error_and_retry_header(): void
    {
        $ip = '2001:db8:'.implode(':', str_split(bin2hex(random_bytes(12)), 4));
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v1/health/live')->assertOk();
        }
        $this->getJson('/api/v1/health/live')->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED')->assertHeader('Retry-After');
    }

    public function test_unhandled_errors_do_not_expose_internal_diagnostics(): void
    {
        Route::get('/api/v1/test-error', function (): void {
            throw new RuntimeException('private-upstream-diagnostic');
        });
        $this->getJson('/api/v1/test-error')->assertStatus(500)
            ->assertJsonPath('error.code', 'INTERNAL_ERROR')->assertDontSee('private-upstream-diagnostic');
    }

    public function test_validation_errors_keep_field_details_in_the_api_envelope(): void
    {
        Route::post('/api/v1/test-validation', function () {
            return request()->validate(['email' => 'required|email']);
        });
        $this->postJson('/api/v1/test-validation', [])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['email']]]);
    }

    public function test_method_errors_preserve_allow_header(): void
    {
        $this->postJson('/api/v1/health/live')->assertStatus(405)
            ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED')->assertHeader('Allow');
    }
}
