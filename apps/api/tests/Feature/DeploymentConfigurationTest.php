<?php

namespace Tests\Feature;

use App\Support\Security\ConfigurationAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Horizon\ProvisioningPlan;
use Tests\TestCase;

final class DeploymentConfigurationTest extends TestCase
{
    public function test_staging_workers_have_reserved_disjoint_capacity_and_no_inherited_development_pool(): void
    {
        $prior = getenv('HORIZON_ROLE');
        try {
            foreach (['critical', 'background', 'invalid'] as $role) {
                putenv('HORIZON_ROLE='.$role);
                $c = require config_path('horizon.php');
                $plan = new ProvisioningPlan('qa', $c['environments'], $c['defaults']);
                $actual = $plan->plan['staging'];
                $this->assertSame($role === 'invalid' ? [] : [$role], array_keys($actual));
                if ($role !== 'invalid') {
                    $this->assertSame(1, $actual[$role]['maxProcesses']);
                    $this->assertSame(1, $actual[$role]['tries']);
                    $this->assertLessThan(config('queue.connections.redis.retry_after'), $actual[$role]['timeout']);
                    $this->assertSame($role === 'critical', in_array('critical', $actual[$role]['queue'], true));
                }
            }
        } finally {
            $prior === false ? putenv('HORIZON_ROLE') : putenv('HORIZON_ROLE='.$prior);
        }
    }

    public function test_proxy_configuration_preflight_rejects_wildcards_and_cidrs(): void
    {
        foreach ([[], ['*'], ['0.0.0.0/0'], ['172.30.71.10', '*'], ['172.30.71.10']] as $proxies) {
            config(['security.trusted_proxies' => $proxies]);
            DB::shouldReceive('selectOne')->once()->andReturn((object) ['rolsuper' => false, 'rolcreatedb' => false, 'rolcreaterole' => false, 'rolbypassrls' => false]);
            $checks = array_column(app(ConfigurationAudit::class)->checks(), 'ok', 'code');
            $this->assertSame($proxies === ['172.30.71.10'], $checks['EXACT_TRUSTED_PROXY_ADDRESSES']);
        }
    }

    public function test_http_proxy_trust_uses_loaded_configuration_without_environment_reads(): void
    {
        config(['security.allowed_hosts' => ['localhost'], 'security.trusted_proxies' => ['172.30.71.10']]);
        Route::get('/api/v1/qa-proxy', fn (Request $r) => ['secure' => $r->isSecure(), 'ip' => $r->ip(), 'host' => $r->getHost()]);
        $headers = ['X-Forwarded-For' => '198.51.100.23', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.example.test'];
        $this->withServerVariables(['REMOTE_ADDR' => '172.30.71.10'])->getJson('http://localhost/api/v1/qa-proxy', $headers)
            ->assertOk()->assertJsonPath('secure', true)->assertJsonPath('ip', '198.51.100.23')->assertJsonPath('host', 'localhost');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.24'])->getJson('http://localhost/api/v1/qa-proxy', $headers)
            ->assertOk()->assertJsonPath('secure', false)->assertJsonPath('ip', '198.51.100.24')->assertJsonPath('host', 'localhost');
        config(['security.trusted_proxies' => ['*']]);
        $this->withServerVariables(['REMOTE_ADDR' => '172.30.71.10'])->getJson('http://localhost/api/v1/qa-proxy', $headers)
            ->assertOk()->assertJsonPath('secure', false)->assertJsonPath('ip', '172.30.71.10');
    }
}
