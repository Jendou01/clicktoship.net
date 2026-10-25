<?php

use App\Modules\Analytics\Http\AnalyticsController;
use Illuminate\Support\Facades\Route;

foreach ([false, true] as $platform) {
    $middleware = ['auth:sanctum', 'identity.active', 'verified', 'auth.throttle:auth-actions', $platform ? 'identity.session' : 'merchant'];
    Route::prefix($platform ? 'v1/admin/analytics' : 'v1/analytics')->middleware($middleware)->group(function () use ($platform) {
        foreach ([['get', 'catalog', 'catalog'], ['get', 'overview', 'overview'], ['get', 'reports/{report}', 'report'], ['get', 'exports', 'history'], ['post', 'exports', 'request'], ['get', 'exports/{id}', 'history'], ['get', 'exports/{id}/download', 'download'], ['post', 'exports/{id}/revoke', 'revoke']] as [$method,$path,$action]) {
            Route::$method($path, [AnalyticsController::class, 'handle'])->defaults('platform', $platform)->defaults('action', $action)->whereUuid('id')->where('report', '[a-z_]{1,50}');
        }
    });
}
