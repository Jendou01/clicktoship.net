<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class TrustedApplicationProxy
{
    public function handle(Request $request, Closure $next): mixed
    {
        // Resolve after configuration boot, including config:cache. Never trust wildcard/cloud defaults.
        $addresses = array_values(array_filter(config('security.trusted_proxies', []), fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false));
        Request::setTrustedProxies($addresses, Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);

        return $next($request);
    }
}
