<?php

namespace App\Modules\Identity\Application;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class DummyPassword
{
    public function key(): string
    {
        return 'identity:dummy-password:'.hash('sha256', json_encode([Hash::getDefaultDriver(), config('hashing')]));
    }

    /** Non-credential, shared by configuration, never keyed by user or merchant. */
    public function hash(): string
    {
        return Cache::store('redis')->rememberForever($this->key(), fn () => Hash::make(Str::random(64)));
    }
}
