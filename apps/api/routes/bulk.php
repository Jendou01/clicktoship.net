<?php

use App\Modules\Bulk\Http\BulkController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/bulk')->middleware(['auth:sanctum', 'identity.active', 'verified', 'merchant', 'auth.throttle:auth-actions'])->group(function (): void {
    Route::get('/templates/{locale}/{format}', [BulkController::class, 'template']);
    Route::get('/imports', [BulkController::class, 'index']);
    Route::post('/imports', [BulkController::class, 'initialize']);
    Route::get('/imports/{batch}', [BulkController::class, 'show'])->whereUuid('batch');
    Route::get('/imports/{batch}/progress', [BulkController::class, 'show'])->whereUuid('batch');
    Route::get('/imports/{batch}/rows', [BulkController::class, 'rows'])->whereUuid('batch');
    Route::get('/imports/{batch}/events', [BulkController::class, 'events'])->whereUuid('batch');
    Route::get('/imports/{batch}/errors', [BulkController::class, 'errors'])->whereUuid('batch');
    Route::post('/imports/{batch}/rows/{row}/{action}', [BulkController::class, 'command'])->whereUuid(['batch', 'row'])->whereIn('action', ['decision']);
    Route::post('/imports/{batch}/{action}', [BulkController::class, 'command'])->whereUuid('batch')->whereIn('action', ['file', 'mapping', 'pricing', 'prepare', 'cancel']);
});
