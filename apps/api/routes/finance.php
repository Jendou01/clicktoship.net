<?php

use App\Modules\WalletBilling\Http\FinanceAdminController;
use App\Modules\WalletBilling\Http\WalletController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', 'identity.active', 'verified', 'auth.throttle:auth-actions'])->group(function (): void {
    Route::middleware('merchant')->group(function (): void {
        Route::match(['GET', 'POST'], '/wallets', [WalletController::class, 'index']);
        Route::get('/wallets/{wallet}/{kind?}/{entry?}', [WalletController::class, 'show'])->whereUuid(['wallet', 'entry'])->whereIn('kind', ['transactions', 'holds', 'statement']);
    });
    Route::prefix('admin/merchants/{merchant}')->whereUuid('merchant')->middleware('identity.session')->group(function (): void {
        Route::get('/wallets', [FinanceAdminController::class, 'wallets']);
        Route::get('/wallets/{wallet}/{kind?}/{entry?}', [FinanceAdminController::class, 'wallet'])->whereUuid(['wallet', 'entry'])->whereIn('kind', ['transactions', 'holds', 'statement']);
        Route::match(['GET', 'POST'], '/financial-requests', [FinanceAdminController::class, 'requests']);
        Route::post('/financial-requests/{approval}/{action}', [FinanceAdminController::class, 'change'])->whereUuid('approval')->whereIn('action', ['approve', 'execute', 'revoke']);
        Route::match(['GET', 'POST'], '/reconciliation', [FinanceAdminController::class, 'reconcile']);
    });
});
