<?php

use App\Modules\Tracking\Http\TrackingController as Tracking;
use Illuminate\Support\Facades\Route;

// Separate external authentication boundary; no Aramex endpoint is provisioned or enabled.
Route::post('v1/tracking/webhooks/{endpoint}', [Tracking::class, 'webhook'])->whereUuid('endpoint')->middleware('auth.throttle:tracking-inbound');
Route::prefix('v1')->middleware(['auth:sanctum', 'identity.active', 'verified', 'auth.throttle:auth-actions'])->group(function (): void {
    Route::middleware('merchant')->group(function (): void {
        Route::get('tracking/shipments', [Tracking::class, 'index']);
        Route::get('tracking/shipments/{shipment}', [Tracking::class, 'detail'])->whereUuid('shipment');
        Route::get('tracking/shipments/{shipment}/timeline', [Tracking::class, 'detail'])->whereUuid('shipment');
        Route::post('tracking/refresh', [Tracking::class, 'refresh']);
    });
    Route::middleware('identity.session')->group(function (): void {
        Route::get('admin/tracking/health', [Tracking::class, 'health']);
        Route::get('admin/merchants/{merchant}/tracking', [Tracking::class, 'diagnostics'])->whereUuid('merchant');
        Route::post('admin/merchants/{merchant}/tracking/cases/{case}/reviews', [Tracking::class, 'review'])->whereUuid('merchant')->whereUuid('case');
    });
});
