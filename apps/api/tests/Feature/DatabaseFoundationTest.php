<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DatabaseFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_and_scoped_hashed_token_persist_with_uuid_identifiers(): void
    {
        $user = User::factory()->create();
        $this->assertTrue(Str::isUuid($user->id));
        $this->assertNotSame('password', $user->password);
        $token = $user->createToken('test integration', ['tracking:read']);
        $this->assertTrue(Str::isUuid($token->accessToken->id));
        $this->assertTrue($token->accessToken->can('tracking:read'));
        $this->assertFalse($token->accessToken->can('shipments:create'));
        $this->assertNotSame($token->plainTextToken, $token->accessToken->token);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id, 'id' => $token->accessToken->id]);
        $this->assertArrayNotHasKey('password', $user->toArray());
    }
}
