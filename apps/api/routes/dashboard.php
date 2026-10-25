<?php

use App\Modules\MerchantDashboard\Http\DashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', 'identity.active', 'verified', 'auth.throttle:auth-actions', 'merchant'])->group(function (): void {
    Route::get('dashboard', [DashboardController::class, 'show']);
    Route::get('dashboard/activity', [DashboardController::class, 'activity']);
});
