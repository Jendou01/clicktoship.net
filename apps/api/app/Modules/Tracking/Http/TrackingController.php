<?php

namespace App\Modules\Tracking\Http;

use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Operations\Application\PlatformSecurity;
use App\Modules\Tracking\Application\TrackingHealthMonitor;
use App\Modules\Tracking\Application\TrackingReconciliationService;
use App\Modules\Tracking\Application\TrackingService;
use App\Modules\Tracking\Application\TrackingWebhookProcessor;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TrackingController
{
    public function index(Request $r, MerchantContext $c): mixed
    {
        $c->require('tracking.read');
        $d = $r->validate(['page' => 'sometimes|integer|min:1|max:100000', 'shipment_id' => 'sometimes|uuid', 'reference' => 'sometimes|string|min:1|max:128', 'account_id' => 'required_with:reference|uuid', 'exceptions' => 'sometimes|boolean']);
        $q = DB::table('shipments as s')->leftJoin('tracking_bindings as b', fn ($j) => $j->on('b.shipment_id', '=', 's.id')->on('b.merchant_id', '=', 's.merchant_id'))->leftJoin('carrier_tracking_projections as p', fn ($j) => $j->on('p.operation_id', '=', 'b.operation_id')->on('p.merchant_id', '=', 'b.merchant_id')->on('p.account_id', '=', 'b.account_id'))->where('s.merchant_id', $c->id());
        if (isset($d['shipment_id'])) {
            $q->where('s.id', $d['shipment_id']);
        }
        if (isset($d['reference'])) {
            $q->where('b.account_id', $d['account_id'])->where('b.reference_hash', TrackingService::referenceHash($d['reference']))->where('b.provenance', 'verified');
        }
        if ($d['exceptions'] ?? false) {
            $q->where('b.provenance', 'verified')->where(fn ($q) => $q->whereIn('p.status', ['exception', 'unknown', 'delivery_attempted'])->orWhereExists(fn ($cases) => $cases->selectRaw('1')->from('tracking_cases as cases')->whereColumn('cases.binding_id', 'b.id')->whereColumn('cases.merchant_id', 's.merchant_id')));
        }
        $p = $q->orderByDesc('s.created_at')->orderByDesc('s.id')->paginate(25, ['s.id', 's.state', 's.created_at', 'b.provenance', 'p.status', 'p.occurred_at'], 'page', $d['page'] ?? 1);
        $rows = [];
        foreach ($p->items() as $s) {
            $rows[] = ['id' => $s->id, 'shipping_state' => $s->state, 'status' => $s->provenance === 'verified' ? ($s->status ?? 'unknown') : (in_array($s->state, ['draft', 'ready', 'queued', 'cancelled', 'blocked']) ? 'not_submitted' : ($s->state === 'accepted' ? 'carrier_accepted' : 'unknown')), 'last_confirmed_update' => $s->provenance === 'verified' ? $s->occurred_at : null];
        }

        return ApiResponse::success(['shipments' => $rows, 'page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'external_enabled' => false]);
    }

    public function detail(Request $r, MerchantContext $c, string $shipment): mixed
    {
        $c->require('tracking.read');
        $d = $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);

        return ApiResponse::success(app(TrackingService::class)->detail($c->id(), $shipment, $d['page'] ?? 1));
    }

    public function refresh(Request $r, MerchantContext $c): mixed
    {
        return ApiResponse::success(['refreshes' => app(TrackingService::class)->refresh($r, $c)], 202);
    }

    public function webhook(Request $r, string $endpoint): mixed
    {
        return ApiResponse::success(app(TrackingWebhookProcessor::class)->receive($r, $endpoint), 202);
    }

    public function health(Request $r): mixed
    {
        return ApiResponse::success(DB::transaction(function () use ($r) {
            PlatformSecurity::lock();
            PlatformSecurity::require($r, 'platform.tracking.health');

            return app(TrackingHealthMonitor::class)->global();
        }));
    }

    public function diagnostics(Request $r, string $merchant): mixed
    {
        return ApiResponse::success(DB::transaction(function () use ($r, $merchant) {
            app(TrackingReconciliationService::class)->authorize($r, $merchant, 'platform.tracking.view');

            return app(TrackingHealthMonitor::class)->target($merchant);
        }));
    }

    public function review(Request $r, string $merchant, string $case): mixed
    {
        return ApiResponse::success(app(TrackingReconciliationService::class)->review($r, $merchant, $case), 201);
    }
}
