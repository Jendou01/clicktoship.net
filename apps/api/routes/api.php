<?php

use App\Http\Controllers\HealthController;
use App\Modules\Identity\Http\IdentityController;
use App\Modules\Identity\Http\TokenController;
use App\Modules\Merchants\Http\MerchantController;
use App\Modules\Merchants\Http\TeamController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:health')->group(function (): void {
    Route::get('/health/live', [HealthController::class, 'live']);
    Route::get('/health/ready', [HealthController::class, 'ready']);
});

Route::prefix('v1')->group(function (): void {
    $identity = IdentityController::class;
    $merchants = MerchantController::class;
    $team = TeamController::class;
    $tokens = TokenController::class;

    Route::middleware('identity.session')->group(function () use ($identity): void {
        Route::post('/auth/register', [$identity, 'register'])->middleware('auth.throttle:auth-register');
        Route::post('/auth/login', [$identity, 'login'])->middleware('auth.throttle:auth-login');
        Route::post('/auth/password/forgot', [$identity, 'forgot'])->middleware('auth.throttle:auth-recovery');
        Route::post('/auth/password/reset', [$identity, 'reset'])->middleware('auth.throttle:auth-password-reset');
    });
    Route::post('/auth/logout', [$identity, 'logout'])->middleware('auth:sanctum');
    Route::middleware(['auth:sanctum', 'identity.active', 'auth.throttle:auth-actions'])->group(function () use ($identity, $merchants, $team, $tokens): void {
        Route::get('/auth/me', [$identity, 'me']);
        Route::middleware('identity.session')->group(function () use ($identity): void {
            Route::post('/auth/email/verify', [$identity, 'verify'])->name('auth.email.verify');
            Route::post('/auth/email/resend', [$identity, 'resend'])->middleware('auth.throttle:auth-recovery');
            Route::get('/auth/sessions', [$identity, 'sessions']);
            Route::delete('/auth/sessions/{session}', [$identity, 'revokeSession'])->whereUuid('session');
        });
        Route::middleware('verified')->group(function () use ($merchants, $team, $tokens): void {
            Route::get('/merchants', [$merchants, 'index']);
            Route::get('/auth/memberships', [$merchants, 'index']);
            Route::post('/merchants', [$merchants, 'store'])->middleware('identity.session');
            Route::post('/merchants/select', [$merchants, 'select'])->middleware('identity.session');
            Route::middleware('merchant')->group(function () use ($merchants, $team, $tokens): void {
                Route::get('/merchants/current', [$merchants, 'current']);
                Route::get('/roles', [$merchants, 'roles']);
                Route::get('/permissions', [$merchants, 'permissions']);
                Route::get('/team', [$team, 'index']);
                Route::post('/team', [$team, 'store']);
                Route::get('/team/{membership}', [$team, 'show'])->whereUuid('membership');
                Route::patch('/team/{membership}', [$team, 'update'])->whereUuid('membership');
                Route::delete('/team/{membership}', [$team, 'destroy'])->whereUuid('membership');
                Route::middleware('identity.session')->group(function () use ($tokens): void {
                    Route::get('/tokens', [$tokens, 'index']);
                    Route::post('/tokens', [$tokens, 'store'])->middleware('auth.throttle:auth-credentials');
                    Route::delete('/tokens/{token}', [$tokens, 'destroy'])->whereUuid('token');
                });
            });
        });
    });
});

require __DIR__.'/management.php';

require __DIR__.'/finance.php';

require __DIR__.'/pricing.php';

require __DIR__.'/carriers.php';

require __DIR__.'/shipping.php';

require __DIR__.'/bulk.php';

require __DIR__.'/tracking.php';

require __DIR__.'/customs.php';

require __DIR__.'/dashboard.php';

require __DIR__.'/overview.php';

require __DIR__.'/commerce.php';
