<?php

use App\Modules\Pricing\Http\PricingController as Pricing;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', 'identity.active', 'verified', 'auth.throttle:auth-actions'])->group(function (): void {
    Route::middleware('merchant')->group(function (): void {
        Route::get('/rates', [Pricing::class, 'rates']);
        Route::post('/rates/quote', [Pricing::class, 'quote']);
        Route::get('/rates/quotes', [Pricing::class, 'history']);
        Route::get('/rates/quotes/{quote}/{action?}', [Pricing::class, 'show'])->whereUuid('quote')->whereIn('action', ['validate']);
        Route::post('/rates/quotes/{quote}/recalculate', [Pricing::class, 'quote'])->whereUuid('quote');
    });
    Route::prefix('admin/pricing')->middleware('identity.session')->group(function (): void {
        Route::get('/audit', [Pricing::class, 'audit']);
        Route::match(['GET', 'POST'], '/definitions', [Pricing::class, 'admin']);
        Route::get('/definitions/{definition}/versions', [Pricing::class, 'versions'])->whereUuid('definition');
        Route::patch('/definitions/{definition}', [Pricing::class, 'update'])->whereUuid('definition');
        Route::post('/definitions/{definition}/{action}', [Pricing::class, 'change'])->whereUuid('definition')->whereIn('action', ['publish', 'disable']);
        Route::get('/merchants/{merchant}/audit', [Pricing::class, 'audit'])->whereUuid('merchant');
        Route::get('/merchants/{merchant}/quotes/{quote}', [Pricing::class, 'privateQuote'])->whereUuid(['merchant', 'quote']);
        Route::match(['GET', 'POST'], '/merchants/{merchant}/definitions', [Pricing::class, 'admin'])->whereUuid('merchant');
        Route::get('/merchants/{merchant}/definitions/{definition}/versions', [Pricing::class, 'merchantVersions'])->whereUuid(['merchant', 'definition']);
        Route::patch('/merchants/{merchant}/definitions/{definition}', [Pricing::class, 'merchantUpdate'])->whereUuid(['merchant', 'definition']);
        Route::post('/merchants/{merchant}/definitions/{definition}/{action}', [Pricing::class, 'merchantChange'])->whereUuid(['merchant', 'definition'])->whereIn('action', ['publish', 'disable']);
    });
});
