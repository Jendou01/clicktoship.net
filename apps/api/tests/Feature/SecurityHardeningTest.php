<?php

namespace Tests\Feature;

use App\Support\Security\ConfigurationAudit;
use App\Support\Security\SanitizeLogs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SecurityHardeningTest extends TestCase
{
    public function test_api_success_and_errors_are_never_storable(): void
    {
        foreach ([['GET', '/api/v1/health/live', 200], ['GET', '/sanctum/csrf-cookie', 204], ['GET', '/api/v1/shipments', 401], ['GET', '/api/v1/unavailable', 404], ['POST', '/api/v1/health/live', 405]] as [$method,$path,$status]) {
            $r = $this->json($method, $path)->assertStatus($status)->assertHeader('X-Request-ID');
            $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        }
    }

    public function test_exact_host_allowlist_rejects_suffixes_and_spoofed_forwarded_hosts(): void
    {
        config(['security.allowed_hosts' => ['localhost']]);
        $this->getJson('http://localhost/api/v1/health/live')->assertOk();
        $this->getJson('http://localhost.attacker.example/api/v1/health/live')->assertStatus(400);
        $this->getJson('http://evil.example/api/v1/health/live', ['X-Forwarded-Host' => 'localhost'])->assertStatus(400);
    }

    public function test_non_development_without_host_configuration_fails_closed(): void
    {
        $env = app()->environment();
        try {
            app()->instance('env', 'staging');
            config(['security.allowed_hosts' => []]);
            $this->getJson('/api/v1/health/live')->assertStatus(400);
        } finally {
            app()->instance('env', $env);
        }
    }

    public function test_actual_log_sink_redacts_messages_context_and_exception_traces(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qa-redaction-');
        $id = (string) Str::uuid();
        try {
            config(['logging.channels.qa-sink' => ['driver' => 'single', 'path' => $path, 'tap' => [SanitizeLogs::class], 'replace_placeholders' => true]]);
            $logger = Log::channel('qa-sink');
            $logger->error('SQL password=synthetic-private-value customer@example.test', ['exception' => new \RuntimeException('synthetic-private-value'), 'authorization' => 'Bearer synthetic-private-value', 'payload' => ['recipient' => 'customer@example.test'], 'submission_id' => $id, 'error_code' => 'SOURCE_UNAVAILABLE']);
            $logger->error('UPPERCASE_PRIVATE_PASSWORD', ['exception' => new \RuntimeException('UPPERCASE_PRIVATE_PASSWORD')]);
            $logger->warning('SHIPMENT_FINANCIAL_FINALIZATION_PENDING', ['submission_id' => $id, 'attempt' => 2]);
            $bytes = file_get_contents($path);
            $this->assertStringNotContainsString('synthetic-private-value', $bytes);
            $this->assertStringNotContainsString('UPPERCASE_PRIVATE_PASSWORD', $bytes);
            $this->assertStringNotContainsString('customer@example.test', $bytes);
            $this->assertStringNotContainsString('stacktrace', $bytes);
            $this->assertStringContainsString('APPLICATION_EXCEPTION', $bytes);
            $this->assertStringContainsString('RuntimeException', $bytes);
            $this->assertStringContainsString('SOURCE_UNAVAILABLE', $bytes);
            $this->assertStringContainsString($id, $bytes);
            $this->assertStringContainsString('SHIPMENT_FINANCIAL_FINALIZATION_PENDING', $bytes);
            $this->assertStringContainsString('"attempt":2', $bytes);
        } finally {
            unlink($path);
        }
    }

    public function test_unhandled_http_exception_is_redacted_at_the_real_sink_too(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qa-http-redaction-');
        config(['logging.default' => 'qa-http', 'logging.channels.qa-http' => ['driver' => 'single', 'path' => $path, 'tap' => [SanitizeLogs::class]]]);
        Route::get('/api/v1/qa-private-error', fn () => throw new \RuntimeException('do-not-log-customer-or-token'));
        try {
            $r = $this->getJson('/api/v1/qa-private-error')->assertStatus(500)->assertDontSee('do-not-log-customer-or-token');
            $this->assertStringNotContainsString('do-not-log-customer-or-token', file_get_contents($path));
            $this->assertStringContainsString($r->headers->get('X-Request-ID'), file_get_contents($path));
            $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        } finally {
            unlink($path);
        }
    }

    public function test_hardened_staging_configuration_is_only_a_preflight_not_activation(): void
    {
        $env = app()->environment();
        try {
            app()->instance('env', 'staging');
            config(['app.debug' => false, 'app.url' => 'https://preview.example.test', 'security.allowed_hosts' => ['preview.example.test'], 'security.trusted_proxies' => ['172.30.71.10'], 'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax', 'session.domain' => null, 'session.driver' => 'redis', 'session.encrypt' => true, 'sanctum.stateful' => ['preview.example.test'], 'mail.default' => 'smtp', 'bulk.structural_only' => false, 'analytics.test_artifacts_enabled' => false]);
            DB::shouldReceive('selectOne')->once()->andReturn((object) ['rolsuper' => false, 'rolcreatedb' => false, 'rolcreaterole' => false, 'rolbypassrls' => false]);
            $this->assertSame([], array_values(array_filter(app(ConfigurationAudit::class)->checks(), fn ($c) => ! $c['ok'])));
            $this->assertFalse(config('finance.corrections_enabled'));
            $this->assertFalse(config('analytics.test_artifacts_enabled'));
        } finally {
            app()->instance('env', $env);
        }
    }

    public function test_configuration_preflight_detects_development_and_unsafe_settings_without_values(): void
    {
        config(['app.debug' => true, 'app.url' => 'http://localhost', 'session.secure' => false, 'security.allowed_hosts' => ['*'], 'analytics.test_artifacts_enabled' => true]);
        $checks = array_column(app(ConfigurationAudit::class)->checks(), 'ok', 'code');
        foreach (['DEBUG_DISABLED', 'HTTPS_APPLICATION_ORIGIN', 'SECURE_HTTPONLY_COOKIES', 'EXACT_ALLOWED_HOSTS', 'EXPORT_GATE_CLOSED', 'RESTRICTED_DATABASE_LOGIN'] as $code) {
            $this->assertFalse($checks[$code], $code);
        }
        $this->assertStringNotContainsString(config('app.key'), json_encode($checks));
        $this->artisan('security:check-config')->assertExitCode(1);
    }
}
