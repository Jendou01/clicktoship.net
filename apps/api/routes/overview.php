<?php

use App\Modules\Operations\Http\OverviewController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/admin/dashboard')->middleware(['auth:sanctum', 'identity.active', 'verified', 'identity.session', 'auth.throttle:auth-actions'])->group(function (): void {
    Route::get('/', [OverviewController::class, 'show']);
    Route::get('merchants/{merchant}', [OverviewController::class, 'target'])->whereUuid('merchant');
    Route::get('grants', [OverviewController::class, 'grants']);
    Route::get('activity', [OverviewController::class, 'activity']);
    Route::get('health', [OverviewController::class, 'health']);
    Route::get('settings', [OverviewController::class, 'settings']);
    Route::get('approvals', [OverviewController::class, 'approvals']);
    Route::get('alerts', [OverviewController::class, 'alerts']);
    Route::post('alerts/{alert}/read', [OverviewController::class, 'alerts'])->where('alert', '[a-f0-9]{64}');
});
