<?php

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return ApiResponse::success(['status' => 'live']);
    }

    public function ready(): JsonResponse
    {
        $checks = ['database' => false, 'redis' => false, 'schema' => false];
        try {
            DB::select('select 1');
            $checks['database'] = true;
            if (Schema::hasTable('migrations')) {
                $expected = array_map(fn ($path) => basename($path, '.php'), glob(database_path('migrations/*.php')));
                $applied = DB::table('migrations')->pluck('migration')->all();
                $checks['schema'] = count($expected) > 0 && array_diff($expected, $applied) === [];
            }
        } catch (Throwable) {
            // Probe failures deliberately omit sensitive connection diagnostics.
        }
        try {
            $pong = Redis::connection()->ping();
            $checks['redis'] = in_array(strtoupper((string) $pong), ['PONG', '1'], true);
        } catch (Throwable) {
            // Dependency diagnostics belong in restricted operational tooling.
        }
        if (in_array(false, $checks, true)) {
            return ApiResponse::error('DEPENDENCIES_UNAVAILABLE', 'Required services are unavailable.', 503, ['checks' => $checks]);
        }

        return ApiResponse::success(['status' => 'ready', 'checks' => $checks]);
    }
}
