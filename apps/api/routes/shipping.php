<?php

use App\Modules\Shipping\Http\ShipmentController as Shipping;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', 'identity.active', 'verified', 'auth.throttle:auth-actions'])->group(function (): void {
    Route::middleware('merchant')->group(function (): void {
        Route::get('shipments', [Shipping::class, 'index']);
        Route::post('shipments', [Shipping::class, 'write']);
        Route::get('shipments/{shipment}', [Shipping::class, 'show'])->whereUuid('shipment');
        Route::patch('shipments/{shipment}', [Shipping::class, 'write'])->whereUuid('shipment');
        Route::post('shipments/{shipment}/validate', [Shipping::class, 'validateDraft'])->whereUuid('shipment');
        Route::post('shipments/{shipment}/quote-acceptance', [Shipping::class, 'acceptQuote'])->whereUuid('shipment');
        Route::post('shipments/{shipment}/submit', [Shipping::class, 'submit'])->whereUuid('shipment');
        Route::post('shipments/{shipment}/cancel', [Shipping::class, 'cancel'])->whereUuid('shipment');
        Route::get('shipments/{shipment}/events', [Shipping::class, 'history'])->whereUuid('shipment');
        Route::get('shipments/{shipment}/documents', [Shipping::class, 'documents'])->whereUuid('shipment');
        Route::get('shipment-documents/{document}/download', [Shipping::class, 'download'])->whereUuid('document');
        Route::post('shipment-documents/{document}/revoke', [Shipping::class, 'revoke'])->whereUuid('document');
        Route::get('pickups', [Shipping::class, 'pickups']);
        Route::post('pickups', [Shipping::class, 'createPickup']);
        Route::get('pickups/{pickup}', [Shipping::class, 'pickup'])->whereUuid('pickup');
        Route::post('pickups/{pickup}/{action}', [Shipping::class, 'pickupCommand'])->whereUuid('pickup')->whereIn('action', ['submit', 'cancel']);
    });
    Route::prefix('admin/merchants/{merchant}/shipping')->middleware('identity.session')->whereUuid('merchant')->group(function (): void {
        Route::get('exceptions', [Shipping::class, 'exceptions']);
        Route::post('submissions/{submission}/reconciliations', [Shipping::class, 'requestReconciliation'])->whereUuid('submission');
        Route::post('reconciliations/{reconciliation}/approve', [Shipping::class, 'approveReconciliation'])->whereUuid('reconciliation');
    });
});
