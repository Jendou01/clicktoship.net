<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DemoBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('demo.enabled')) {
            return $next($request);
        }
        // Registration remains normal, including CSRF/email verification/rate
        // limiting. Only reserved synthetic identities may enter this database.
        if ($request->is('api/v1/auth/register')) {
            abort_unless(str_ends_with(strtolower((string) $request->input('email')), '@example.invalid'), 422);
        }
        // Read-only integration/wallet screens remain visible. Demo never opens
        // a new financial, provider-credential or external-execution route.
        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            abort_if($request->is('api/v1/carriers*', 'api/v1/admin/carriers*', 'api/v1/commerce*', 'api/v1/wallet*', 'api/v1/payments*', 'api/v1/finance*', 'api/v1/admin/finance*', 'api/v1/admin/merchants/*/financial-requests*', 'api/v1/admin/merchants/*/reconciliation*', 'api/v1/shipments/*/submit', 'api/v1/pickups*', 'api/v1/*/exports*'), 403);
        }
        $response = $next($request);
        $response->headers->set('X-ClickToShip-Environment', 'DISPOSABLE-SYNTHETIC-DEMO');

        return $response;
    }
}
