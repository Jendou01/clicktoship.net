<?php

use App\Modules\Commerce\Http\CommerceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/commerce')->middleware(['auth:sanctum', 'identity.active', 'verified', 'merchant', 'auth.throttle:auth-actions'])->group(function (): void {
    foreach ([
        ['post', 'shopyzee/authorize', 'shopyzee-unavailable'], ['get', 'providers', 'providers'], ['get', 'connections', 'connections'], ['post', 'salla/authorize', 'begin'], ['get', 'salla/callback', 'callback'],
        ['post', 'connections/{id}/disconnect', 'disconnect'], ['post', 'connections/{id}/sync', 'sync'], ['post', 'connections/{id}/prepare', 'prepare'], ['get', 'connections/{id}/events', 'events'],
        ['get', 'orders', 'orders'], ['get', 'orders/{id}', 'order'], ['post', 'orders/{id}/preview', 'preview'], ['post', 'orders/{id}/draft', 'draft'],
        ['get', 'runs', 'runs'], ['get', 'runs/{id}', 'run'], ['post', 'runs/{id}/retry', 'retry'],
    ] as [$method,$path,$action]) {
        $route = Route::$method($path, [CommerceController::class, 'invoke'])->defaults('action', $action)->whereUuid('id');
        if (in_array($action, ['begin', 'callback'])) {
            $route->middleware('identity.session');
        }
    }
});
Route::post('v1/commerce/webhooks/shopyzee', [CommerceController::class, 'webhook'])->defaults('provider', 'shopyzee')->middleware('auth.throttle:tracking-inbound');
Route::post('v1/commerce/webhooks/salla', [CommerceController::class, 'webhook'])->middleware('auth.throttle:tracking-inbound');
