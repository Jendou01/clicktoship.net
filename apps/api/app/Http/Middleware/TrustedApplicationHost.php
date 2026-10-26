<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TrustedApplicationHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $hosts = config('security.allowed_hosts', []);
        if ($hosts || ! app()->environment('local', 'testing')) {
            abort_unless(in_array(strtolower($request->getHost()), array_map('strtolower', $hosts), true), 400);
        }

        return $next($request);
    }
}
