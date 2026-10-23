<?php

namespace App\Modules\Identity\Http;

use Closure;
use Illuminate\Http\Request;

final class RequireVerifiedEmail
{
    public function handle(Request $request, Closure $next): mixed
    {
        // API authorization never redirects to a web verification route.
        abort_unless($request->user()?->hasVerifiedEmail(), 403);

        return $next($request);
    }
}
