<?php

namespace App\Modules\Commerce\Http;

use App\Modules\Commerce\Application\Access;
use App\Modules\Commerce\Application\Connections;
use App\Modules\Commerce\Application\Drafts;
use App\Modules\Commerce\Application\Orders;
use App\Modules\Commerce\Application\Providers;
use App\Modules\Commerce\Application\Webhooks;
use App\Modules\Commerce\Application\Work;
use App\Modules\Commerce\Domain\IntegrationFailure;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CommerceController
{
    public function invoke(Request $r, MerchantContext $c, Access $a, Connections $connections, Orders $orders, Drafts $drafts, Work $work): mixed
    {
        $action = $r->route('action');
        $id = $r->route('id');
        $status = 200;
        try {
            $data = match ($action) {
                'providers' => $a->locked($r, $c, 'integrations.read', fn () => ['providers' => app(Providers::class)->catalog()]),
                'connections' => $a->locked($r, $c, 'integrations.read', fn ($m) => $connections->list($m)),
                'shopyzee-unavailable' => $a->locked($r, $c, 'integrations.manage', fn () => abort(503)),
                'begin' => $connections->begin($r, $c),'callback' => $connections->complete($r, $c),
                'disconnect' => $connections->disconnect($r, $c, $id),
                'sync' => $work->queue($r, $c, $id, 'sync'), 'prepare' => $work->queue($r, $c, $id, 'prepare'),
                'orders' => $a->locked($r, $c, 'orders.read', fn ($m) => $orders->directory($r, $m)),
                'order' => $a->locked($r, $c, 'orders.read', fn ($m) => $orders->detail($m, $id)),
                'preview' => $a->locked($r, $c, 'orders.prepare', function ($m) use ($c, $drafts, $r, $id) {
                    $c->require('orders.read');
                    $c->require('shipments.write');

                    return $drafts->preview($m, $id, $drafts->input($r));
                }),
                'draft' => $drafts->create($r, $c, $id),
                'runs' => $a->locked($r, $c, 'integrations.read', function ($m) use ($r) {
                    $d = $r->validate(['connection' => 'sometimes|uuid', 'after' => 'sometimes|uuid']);
                    $q = DB::table('commerce_runs')->where('merchant_id', $m);
                    if (isset($d['connection'])) {
                        $q->where('connection_id', $d['connection']);
                    }if (isset($d['after'])) {
                        $q->where('id', '>', $d['after']);
                    }$rows = $q->orderBy('id')->limit(26)->get(['id', 'connection_id', 'kind', 'state', 'position', 'pages', 'attempt', 'failure_code', 'created_at', 'updated_at']);
                    $more = $rows->count() > 25;
                    $rows = $rows->take(25)->values();

                    return ['runs' => $rows, 'next_after' => $more ? $rows->last()->id : null];
                }),
                'run' => $a->locked($r, $c, $c->allows('integrations.read') ? 'integrations.read' : 'orders.read', function ($m) use ($id, $c) {
                    $row = DB::table('commerce_runs')->where('merchant_id', $m)->where('id', $id)->first(['id', 'connection_id', 'actor_id', 'kind', 'state', 'position', 'pages', 'attempt', 'failure_code', 'created_at', 'updated_at']);
                    abort_unless($row, 404);
                    abort_if(! $c->allows('integrations.read') && ($row->kind !== 'prepare' || $row->actor_id !== $c->actorId()), 403);
                    unset($row->actor_id);

                    return ['run' => $row, 'results' => DB::table('commerce_results')->where('merchant_id', $m)->where('run_id', $id)->limit(25)->get(['order_id', 'state', 'shipment_id', 'error_code'])];
                }),
                'retry' => $work->retry($r, $c, $id),
                'events' => $a->locked($r, $c, 'integrations.read', function ($m) use ($a, $id, $r) {
                    $a->connection($m, $id);
                    $d = $r->validate(['after' => 'sometimes|uuid']);
                    $q = DB::table('commerce_events')->where('merchant_id', $m)->where('connection_id', $id);
                    if (isset($d['after'])) {
                        $q->where('id', '>', $d['after']);
                    }$rows = $q->orderBy('id')->limit(26)->get(['id', 'kind', 'metadata', 'created_at']);
                    $more = $rows->count() > 25;
                    $rows = $rows->take(25)->values();

                    return ['events' => $rows, 'next_after' => $more ? $rows->last()->id : null];
                }),
            };
            if (in_array($action, ['sync', 'prepare'])) {
                $status = 202;
            }

            return ApiResponse::success($data, $status)->header('Cache-Control', 'private, no-store');
        } catch (IntegrationFailure) {
            abort(503);
        }
    }

    public function webhook(Request $r, Webhooks $service): mixed
    {
        try {
            return ApiResponse::success($service->receive($r));
        } catch (IntegrationFailure) {
            abort(403);
        }
    }
}
