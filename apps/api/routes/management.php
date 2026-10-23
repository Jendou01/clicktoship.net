<?php

use App\Modules\Identity\Http\IdentityController;
use App\Modules\Identity\Http\MfaController;
use App\Modules\Merchants\Http\ManagementController as MerchantManagement;
use App\Modules\Operations\Http\AdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', 'identity.active', 'verified', 'auth.throttle:auth-actions'])->group(function (): void {
    Route::middleware('identity.session')->group(function (): void {
        Route::patch('/auth/me', [IdentityController::class, 'update']);
        Route::get('/auth/mfa', [MfaController::class, 'status']);
        Route::middleware('auth.throttle:auth-mfa')->group(function (): void {
            Route::post('/auth/mfa/enrollment', [MfaController::class, 'enroll']);
            Route::post('/auth/mfa/confirm', [MfaController::class, 'confirm']);
            Route::post('/auth/mfa/challenge', [MfaController::class, 'challenge']);
            Route::post('/auth/mfa/recovery-codes', [MfaController::class, 'recovery']);
            Route::delete('/auth/mfa', [MfaController::class, 'disable']);
        });
        Route::post('/auth/invitations/accept', [MerchantManagement::class, 'accept'])->middleware('auth.throttle:auth-invite');
        Route::prefix('admin')->group(function (): void {
            Route::get('/merchants', [AdminController::class, 'directory']);
            Route::match(['GET', 'POST'], '/access-requests', [AdminController::class, 'grants']);
            Route::post('/access-requests/{grant}/approve', [AdminController::class, 'approve'])->whereUuid('grant');
            Route::delete('/access-grants/{grant}', [AdminController::class, 'revoke'])->whereUuid('grant');
            Route::get('/merchants/{merchant}', [AdminController::class, 'merchant'])->whereUuid('merchant');
            Route::patch('/merchants/{merchant}/status', [AdminController::class, 'status'])->whereUuid('merchant');
            Route::get('/merchants/{merchant}/verification', [AdminController::class, 'verification'])->whereUuid('merchant');
            Route::post('/merchants/{merchant}/verification/{submission}/decision', [AdminController::class, 'decide'])->whereUuid(['merchant', 'submission']);
            Route::get('/merchants/{merchant}/audit-events', [AdminController::class, 'merchantAudit'])->whereUuid('merchant');
            Route::get('/users', [AdminController::class, 'users']);
            Route::get('/users/{user}', [AdminController::class, 'user'])->whereUuid('user');
            Route::patch('/users/{user}/status', [AdminController::class, 'userStatus'])->whereUuid('user');
            Route::patch('/users/{user}/platform-roles', [AdminController::class, 'role'])->whereUuid('user');
            Route::get('/audit-events', [AdminController::class, 'audit']);
        });
    });
    Route::middleware('merchant')->group(function (): void {
        Route::match(['GET', 'PATCH'], '/merchants/current/profile', [MerchantManagement::class, 'profile']);
        Route::match(['GET', 'PATCH'], '/merchants/current/settings', [MerchantManagement::class, 'settings']);
        Route::get('/merchants/current/eligibility', [MerchantManagement::class, 'eligibility']);
        Route::get('/merchants/current/audit-events', [MerchantManagement::class, 'audit']);
        Route::get('/merchants/current/verification', [MerchantManagement::class, 'verification']);
        Route::post('/merchants/current/verification/submissions', [MerchantManagement::class, 'verification']);
        Route::match(['GET', 'POST'], '/addresses', [MerchantManagement::class, 'addresses']);
        Route::match(['GET', 'PATCH', 'DELETE'], '/addresses/{address}', [MerchantManagement::class, 'addresses'])->whereUuid('address');
        Route::middleware('auth.throttle:auth-invite')->group(function (): void {
            Route::match(['GET', 'POST'], '/team/invitations', [MerchantManagement::class, 'invitations']);
            Route::post('/team/invitations/{invitation}/resend', [MerchantManagement::class, 'resend'])->whereUuid('invitation');
            Route::delete('/team/invitations/{invitation}', [MerchantManagement::class, 'revoke'])->whereUuid('invitation');
        });
    });
});
