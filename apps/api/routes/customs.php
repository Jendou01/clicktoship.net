<?php

use App\Modules\Customs\Http\CustomsController as Customs;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', 'identity.active', 'verified', 'auth.throttle:auth-actions'])->group(function (): void {
    Route::prefix('customs')->middleware('merchant')->group(function (): void {
        Route::get('declarations', [Customs::class, 'index']);
        Route::match(['GET', 'PUT'], 'profile', [Customs::class, 'profile']);
        Route::get('countries/{country}', [Customs::class, 'country'])->where('country', '[A-Z]{2}');
        Route::get('shipments/{shipment}', [Customs::class, 'show'])->whereUuid('shipment');
        Route::put('shipments/{shipment}/declaration', [Customs::class, 'save'])->whereUuid('shipment');
        Route::post('shipments/{shipment}/sync', [Customs::class, 'sync'])->whereUuid('shipment');
        Route::post('shipments/{shipment}/validate', [Customs::class, 'validate'])->whereUuid('shipment');
        Route::get('shipments/{shipment}/pricing', [Customs::class, 'price'])->whereUuid('shipment');
        Route::post('shipments/{shipment}/documents', [Customs::class, 'document'])->whereUuid('shipment');
        Route::get('documents/{document}/download', [Customs::class, 'download'])->whereUuid('document');
        Route::post('documents/{document}/revoke', [Customs::class, 'revoke'])->whereUuid('document');
    });
    Route::middleware('identity.session')->group(function (): void {
        Route::match(['GET', 'POST'], 'admin/customs/rules', [Customs::class, 'rules']);
        Route::post('admin/customs/rules/{country}/review', [Customs::class, 'approve'])->where('country', '[A-Z]{2}');
        Route::get('admin/merchants/{merchant}/customs', [Customs::class, 'diagnostics'])->whereUuid('merchant');
        Route::get('admin/merchants/{merchant}/customs/declarations/{declaration}', [Customs::class, 'reviewData'])->whereUuid('merchant')->whereUuid('declaration');
        Route::post('admin/merchants/{merchant}/customs/declarations/{declaration}/reviews', [Customs::class, 'review'])->whereUuid('merchant')->whereUuid('declaration');
    });
});
