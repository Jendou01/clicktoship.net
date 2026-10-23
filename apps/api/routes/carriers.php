<?php

use App\Modules\Carriers\Http\CarrierController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/admin/carriers')->middleware(['auth:sanctum', 'identity.active', 'verified', 'identity.session', 'auth.throttle:auth-actions'])->group(function (): void {
    Route::get('/', [CarrierController::class, 'index']);
    Route::post('/accounts', [CarrierController::class, 'store']);
    Route::patch('/accounts/{account}', [CarrierController::class, 'update'])->whereUuid('account');
    Route::post('/accounts/{account}/{action}', [CarrierController::class, 'change'])->whereUuid('account')->whereIn('action', ['approve', 'disable']);
    Route::get('/accounts/{account}/history', [CarrierController::class, 'history'])->whereUuid('account');
});
// Public endpoint is deliberately unavailable; no payload or header is treated as authenticated evidence.
Route::post('v1/carrier-webhooks/aramex/{integration}', [CarrierController::class, 'webhook'])->whereUuid('integration')->middleware('throttle:health');
