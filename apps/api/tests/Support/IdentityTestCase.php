<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Merchants\Application\MerchantService;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class IdentityTestCase extends TestCase
{
    protected array $jar = [];

    protected string $ip;

    protected const PASSWORD = 'Secure-Example-12345';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ip = '2001:db8:'.implode(':', str_split(bin2hex(random_bytes(12)), 4));
        config(['sanctum.stateful' => ['localhost:8080', 'localhost'], 'session.driver' => 'redis', 'session.secure' => false]);
    }

    protected function resetHttpState(): void
    {
        app('auth')->forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
    }

    protected function browser(string $method, string $path, array $data = [], bool $csrf = true): TestResponse
    {
        $this->resetHttpState();
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost:8080', 'REMOTE_ADDR' => $this->ip];
        if ($csrf && isset($this->jar['XSRF-TOKEN'])) {
            $server['HTTP_X_XSRF_TOKEN'] = rawurldecode($this->jar['XSRF-TOKEN']);
        }
        $response = $this->call($method, $path, [], $this->jar, [], $server, json_encode($data));
        foreach ($response->headers->getCookies() as $cookie) {
            $this->jar[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }

    protected function csrf(): void
    {
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
    }

    protected function login(User $user): void
    {
        $this->csrf();
        $this->browser('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
    }

    protected function user(array $attributes = []): User
    {
        return User::factory()->create(['password' => self::PASSWORD, ...$attributes])->fresh();
    }

    protected function organization(User $user, string $name = 'Test organization'): string
    {
        return app(MerchantService::class)->create($user, $name)->id;
    }

    protected function token(User $user, string $merchant, array $abilities = ['*']): string
    {
        $token = $user->createToken('Test token', $abilities, now()->addDay());
        $token->accessToken->forceFill(['merchant_id' => $merchant, 'auth_version' => $user->auth_version])->save();

        return $token->plainTextToken;
    }

    protected function bearer(string $token, string $method, string $path, array $data = [], array $headers = []): TestResponse
    {
        $this->resetHttpState();
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'REMOTE_ADDR' => $this->ip];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, $path, [], [], [], $server, json_encode($data));
    }

    protected function select(string $merchant): void
    {
        $this->browser('POST', '/api/v1/merchants/select', ['merchant_id' => $merchant])->assertOk();
    }
}
