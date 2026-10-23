<?php

use App\Http\Middleware\RequestId;
use App\Modules\Identity\Http\ActiveIdentity;
use App\Modules\Identity\Http\RequireSession;
use App\Modules\Merchants\Http\ClearMerchantContext;
use App\Modules\Merchants\Http\ResolveMerchant;
use App\Support\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([RequestId::class, ClearMerchantContext::class]);
        $middleware->statefulApi();
        $middleware->redirectGuestsTo(fn (Request $request) => null);
        $middleware->alias([
            'identity.active' => ActiveIdentity::class,
            'identity.session' => RequireSession::class,
            'merchant' => ResolveMerchant::class,
            'auth.throttle' => ThrottleRequestsWithRedis::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*'));
        $exceptions->respond(function (Response $response): Response {
            if (! request()->is('api/*')) {
                return $response;
            }
            if ($response->getStatusCode() < 400) {
                $response->headers->set('X-Request-ID', ApiResponse::requestId());

                return $response;
            }
            $status = $response->getStatusCode();
            [$code, $message] = match ($status) {
                400 => ['BAD_REQUEST', 'The request is invalid.'],
                401 => ['UNAUTHENTICATED', 'Authentication is required.'],
                403 => ['FORBIDDEN', 'This action is not permitted.'],
                404 => ['NOT_FOUND', 'The resource is unavailable.'],
                405 => ['METHOD_NOT_ALLOWED', 'This method is not allowed.'],
                409 => ['CONFLICT', 'The request conflicts with the current state.'],
                419 => ['CSRF_TOKEN_MISMATCH', 'The session has expired.'],
                422 => ['VALIDATION_FAILED', 'The submitted data is invalid.'],
                429 => ['RATE_LIMITED', 'Too many requests.'],
                503 => ['SERVICE_UNAVAILABLE', 'The service is unavailable.'],
                default => ['INTERNAL_ERROR', 'The request could not be completed.'],
            };
            $body = json_decode($response->getContent(), true);
            $details = $status === 422 ? ($body['errors'] ?? []) : [];

            return ApiResponse::error($code, $message, $status, $details)
                ->withHeaders(array_intersect_key($response->headers->all(), array_flip(['retry-after', 'allow'])));
        });
    })->create();
