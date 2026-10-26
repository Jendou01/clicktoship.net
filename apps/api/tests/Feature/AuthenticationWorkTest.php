<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\DummyPassword;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\IdentityTestCase;

final class AuthenticationWorkTest extends IdentityTestCase
{
    use DatabaseMigrations;

    public function test_cold_and_warm_negative_paths_share_one_configured_dummy_not_one_per_identity(): void
    {
        $u = $this->user();
        config(['hashing.qa_nonce' => (string) Str::uuid()]);
        $key = app(DummyPassword::class)->key();
        $dummy = Hash::make('not-a-real-account-password');
        try {
            Hash::shouldReceive('getDefaultDriver')->andReturn('bcrypt');
            Hash::shouldReceive('make')->once()->andReturn($dummy);
            Hash::shouldReceive('check')->times(3)->andReturn(false);
            $this->csrf();
            foreach ([$u->email, 'unknown-one@example.test', 'unknown-two@example.test'] as $email) {
                $this->browser('POST', '/api/v1/auth/login', ['email' => $email, 'password' => 'invalid-synthetic'])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
            }
            $this->assertSame($dummy, Cache::store('redis')->get($key));
            $this->assertDatabaseCount('auth_sessions', 0);
        } finally {
            Cache::store('redis')->forget($key);
        }
    }

    public function test_matching_dummy_password_never_authenticates_an_unknown_identity_and_configuration_keys_differ(): void
    {
        config(['hashing.qa_nonce' => (string) Str::uuid()]);
        $service = app(DummyPassword::class);
        $key = $service->key();
        try {
            Cache::store('redis')->forever($key, Hash::make('synthetic-dummy-only-password'));
            $this->csrf();
            $this->browser('POST', '/api/v1/auth/login', ['email' => 'not-an-account@example.test', 'password' => 'synthetic-dummy-only-password'])->assertStatus(422);
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('auth_sessions', 0);
            config(['hashing.qa_nonce' => (string) Str::uuid()]);
            $this->assertNotSame($key, $service->key());
        } finally {
            Cache::store('redis')->forget($key);
        }
    }
}
