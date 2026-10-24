<?php

namespace App\Modules\Shipping\Http;

use App\Modules\Carriers\Domain\CarrierFailure;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Operations\Application\PlatformSecurity;
use App\Modules\Pickups\Application\Pickups;
use App\Modules\Pricing\Application\QuoteAcceptance;
use App\Modules\Shipping\Application\Documents;
use App\Modules\Shipping\Application\ShipmentReconciliation;
use App\Modules\Shipping\Application\Shipments;
use App\Modules\Shipping\Application\Submission;
use App\Support\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ShipmentController
{
    public function index(Request $r, MerchantContext $c): mixed
    {
        $c->require('shipments.read');
        $d = $r->validate(['page' => 'sometimes|integer|min:1|max:100000', 'state' => 'sometimes|in:draft,ready,queued,sending,accepted,rejected,unknown,cancelled,blocked']);
        $p = DB::table('shipments')->where('merchant_id', $c->id())->when($d['state'] ?? null, fn ($q, $s) => $q->where('state', $s))->orderByDesc('created_at')->orderByDesc('id')->paginate(25, ['id', 'version', 'revision', 'state', 'financial_state', 'document_state', 'created_at'], 'page', $d['page'] ?? 1);

        return ApiResponse::success(['shipments' => $p->items(), 'page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'submission_enabled' => false, 'reason' => 'CARRIER_EVIDENCE_AND_FINANCIAL_ACTIVATION_REQUIRED']);
    }

    public function show(Request $r, MerchantContext $c, string $shipment): mixed
    {
        $c->require('shipments.read');

        return ApiResponse::success(app(Shipments::class)->show($c->id(), $shipment));
    }

    public function write(Request $r, MerchantContext $c, ?string $shipment = null): mixed
    {
        return ApiResponse::success(app(Shipments::class)->write($r, $c, $shipment), $shipment ? 200 : 201);
    }

    public function validateDraft(Request $r, MerchantContext $c, string $shipment): mixed
    {
        return ApiResponse::success(app(Shipments::class)->validate($r, $c, $shipment));
    }

    public function acceptQuote(Request $r, MerchantContext $c, string $shipment): mixed
    {
        return ApiResponse::success(app(QuoteAcceptance::class)->accept($r, $c, $shipment));
    }

    public function submit(Request $r, MerchantContext $c, string $shipment): mixed
    {
        try {
            return ApiResponse::success(app(Submission::class)->create($r, $c, $shipment), 202);
        } catch (CarrierFailure) {
            abort(503);
        } catch (QueryException $e) {
            if ($e->getCode() === '23514') {
                abort(409);
            } throw $e;
        }
    }

    public function cancel(Request $r, MerchantContext $c, string $shipment): mixed
    {
        return ApiResponse::success(app(Shipments::class)->cancel($r, $c, $shipment));
    }

    public function history(Request $r, MerchantContext $c, string $shipment): mixed
    {
        $c->require('shipments.read');
        app(Shipments::class)->row($c->id(), $shipment);
        $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);
        $p = DB::table('shipment_events')->where('merchant_id', $c->id())->where('shipment_id', $shipment)->orderBy('created_at')->orderBy('id')->paginate(50, ['id', 'kind', 'metadata', 'created_at']);

        return ApiResponse::success(['events' => $p->items(), 'page' => $p->currentPage(), 'last_page' => $p->lastPage()]);
    }

    public function documents(Request $r, MerchantContext $c, string $shipment): mixed
    {
        $c->require('shipments.documents');
        app(Shipments::class)->row($c->id(), $shipment);

        return ApiResponse::success(['documents' => DB::table('shipment_documents')->where('merchant_id', $c->id())->where('shipment_id', $shipment)->limit(100)->get(['id', 'mime', 'size_bytes', 'state', 'created_at']), 'real_labels_enabled' => false]);
    }

    public function download(Request $r, MerchantContext $c, string $document): mixed
    {
        return app(Documents::class)->download($r, $c, $document);
    }

    public function revoke(Request $r, MerchantContext $c, string $document): mixed
    {
        return ApiResponse::success(app(Documents::class)->revoke($r, $c, $document));
    }

    public function pickups(Request $r, MerchantContext $c): mixed
    {
        $c->require('pickups.read');
        $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);
        $p = DB::table('pickups')->where('merchant_id', $c->id())->orderByDesc('created_at')->orderByDesc('id')->paginate(25, ['id', 'state', 'version', 'window_start', 'window_end', 'timezone']);

        return ApiResponse::success(['pickups' => $p->items(), 'page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'external_enabled' => false]);
    }

    public function createPickup(Request $r, MerchantContext $c): mixed
    {
        return ApiResponse::success(app(Pickups::class)->create($r, $c), 201);
    }

    public function pickup(Request $r, MerchantContext $c, string $pickup): mixed
    {
        $c->require('pickups.read');

        return ApiResponse::success(app(Pickups::class)->show($c->id(), $pickup));
    }

    public function pickupCommand(Request $r, MerchantContext $c, string $pickup, string $action): mixed
    {
        try {
            return ApiResponse::success(app(Pickups::class)->command($r, $c, $pickup, $action));
        } catch (CarrierFailure) {
            abort(503);
        }
    }

    public function exceptions(Request $r, string $merchant): mixed
    {
        return ApiResponse::success(DB::transaction(function () use ($r, $merchant) {
            PlatformSecurity::lock();
            app(ShipmentReconciliation::class)->authorize($r, $merchant, 'platform.shipments.view');

            return ['shipments' => DB::table('shipments')->where('merchant_id', $merchant)->where(function ($q) {
                $q->whereNotNull('exception_code')->orWhereIn('state', ['unknown', 'blocked'])->orWhereIn('financial_state', ['capture_pending', 'release_pending', 'review']);
            })->orderBy('updated_at')->limit(100)->get(),
                'submissions' => DB::table('shipment_submissions')->where('merchant_id', $merchant)->limit(100)->get(['id', 'shipment_id']),
                'observations' => DB::table('shipping_observations')->where('merchant_id', $merchant)->orderByDesc('created_at')->limit(100)->get(['id', 'submission_id', 'outcome', 'evidence_hash', 'created_at']),
                'requests' => DB::table('shipping_reconciliation_requests')->where('merchant_id', $merchant)->orderByDesc('created_at')->limit(100)->get(['id', 'submission_id', 'observation_id', 'maker_id', 'expires_at']), 'execution_enabled' => false];
        }));
    }

    public function requestReconciliation(Request $r, string $merchant, string $submission): mixed
    {
        return ApiResponse::success(app(ShipmentReconciliation::class)->request($r, $merchant, $submission), 201);
    }

    public function approveReconciliation(Request $r, string $merchant, string $reconciliation): mixed
    {
        return ApiResponse::success(app(ShipmentReconciliation::class)->approve($r, $merchant, $reconciliation));
    }
}
