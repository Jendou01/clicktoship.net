<?php

namespace App\Modules\Analytics\Http;

use App\Modules\Analytics\Application\Authority;
use App\Modules\Analytics\Application\Definitions;
use App\Modules\Analytics\Application\Exports;
use App\Modules\Analytics\Application\Filters;
use App\Modules\Analytics\Application\Overview;
use App\Modules\Analytics\Application\Reports;
use App\Modules\Operations\Application\Overview\ReadAuthority;
use App\Support\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

final class AnalyticsController
{
    public function handle(Request $r): mixed
    {
        $platform = (bool) $r->route('platform', false);
        $scope = $platform ? 'platform' : 'merchant';
        $action = $r->route('action');
        $id = $r->route('id');
        $report = $r->route('report');
        $read = function ($m, $p) use ($r, $scope, $platform, $action, $id, $report) {
            $exports = app(Exports::class);
            if ($action === 'catalog') {
                return ['reports' => $platform ? array_map(fn ($s) => ['id' => $s, 'permission' => 'platform.metrics.'.$s, 'source' => 'existing_operations_counts', 'columns' => ['metric', 'value'], 'filters' => [], 'export_formats' => ['csv', 'xlsx']], array_values(array_filter(Overview::SECTIONS, fn ($s) => in_array('platform.metrics.'.$s, $p, true)))) : Definitions::catalog($p), 'permissions' => $p, 'artifacts_enabled' => $exports->available(), 'max_days' => 366, 'max_rows' => 1000, 'max_bytes' => 2097152, 'expiry_minutes' => 15];
            }
            if ($action === 'overview' || $action === 'report') {
                if ($report) {
                    app(Authority::class)->require($p, $report, $scope);
                }
                $f = Filters::read($r, $platform ? null : $report);
                if ($platform || ! $report) {
                    foreach (['provider', 'currency', 'status', 'service', 'after'] as $key) {
                        abort_if(isset($f[$key]), 422);
                    }
                }

                return $platform ? app(Overview::class)->platform($p, $f, $report, $r->user()->id, $r->session()->get('auth_session_id')) : ($report ? app(Reports::class)->read($report, $m, $f) : app(Overview::class)->merchant($m, $p, $f));
            }

            return match ($action) {
                'request' => $exports->request($r, $scope, $m, $p),'download' => $exports->download($r, $scope, $m, $p, $id),'revoke' => $exports->revoke($r, $scope, $m, $p, $id),default => $exports->history($r, $scope, $m, $p, $id)
            };
        };
        try {
            if ($platform) {
                $response = app(ReadAuthority::class)->respond($r, 'platform.analytics.read', function ($p) use ($read) {
                    abort_unless(in_array('platform.overview', $p, true), 403);

                    return $read(null, $p);
                });
                if ($action === 'request') {
                    $response->setStatusCode(202);
                }

                return $response;
            }
            $data = app(Authority::class)->merchant($r, $read);
            if ($action === 'download') {
                return $data;
            }

            return ApiResponse::success($data, $action === 'request' ? 202 : 200)->header('Cache-Control', 'private, no-store')->header('Vary', 'Cookie, Authorization, X-Merchant-ID');
        } catch (QueryException) {
            abort(503);
        }
    }
}
