<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

class PersonalAccessToken extends SanctumToken
{
    use HasUuids;

    protected function casts(): array
    {
        return ['abilities' => 'json', 'last_used_at' => 'datetime', 'expires_at' => 'datetime', 'auth_version' => 'integer'];
    }
}
