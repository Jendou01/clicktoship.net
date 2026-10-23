<?php

namespace App\Modules\Identity\Http;

use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;

final class RequireSession
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_unless($request->hasSession(), 400);
        abort_if($request->user()?->currentAccessToken() instanceof PersonalAccessToken, 403);

        return $next($request);
    }
}
