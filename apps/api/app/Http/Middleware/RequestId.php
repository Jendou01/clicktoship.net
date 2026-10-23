<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('request_id', (string) Str::uuid());
        $response = $next($request);
        $response->headers->set('X-Request-ID', $request->attributes->get('request_id'));
        if ($request->is('api/v1/auth/*', 'api/v1/merchants*', 'api/v1/team*', 'api/v1/tokens*', 'api/v1/addresses*', 'api/v1/admin*', 'api/v1/roles', 'api/v1/permissions')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
