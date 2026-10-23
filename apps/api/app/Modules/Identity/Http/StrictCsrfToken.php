<?php

namespace App\Modules\Identity\Http;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

final class StrictCsrfToken extends VerifyCsrfToken
{
    protected function runningUnitTests(): bool
    {
        // Security tests must exercise the real CSRF check, not Laravel's test bypass.
        return false;
    }
}
