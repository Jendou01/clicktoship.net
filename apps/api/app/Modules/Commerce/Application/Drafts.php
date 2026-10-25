<?php

namespace App\Modules\Commerce\Application;

use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Pricing\Application\Catalog;
use App\Modules\Shipping\Application\DraftReadiness;
use App\Modules\Shipping\Application\Shipments;
use App\Modules\Shipping\Domain\ShipmentInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class Drafts
{
    public function input(Request $r): array
    {
        return $r->validate(['snapshot_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'review' => 'present|array:origin,destination,packages,service_id,description,goods_origin_country,items,customs', 'acknowledge_changes' => 'sometimes|boolean', 'preview_hash' => 'sometimes|string|size:64', 'merchant_id' => 'prohibited']);
    }

    public function preview(string $merchant, string $id, array $input): array
    {
        Validator::make($input, ['review' => 'present|array:origin,destination,packages,service_id,description,goods_origin_country,items,customs', 'review.destination' => 'sometimes|array', 'review.origin' => 'sometimes|array', 'review.packages' => 'sometimes|array', 'review.items' => 'sometimes|array'])->validate();
        $o = app(Orders::class)->row($merchant, $id);
        $c = app(Access::class)->connection($merchant, $o->connection_id);
        abort_unless($o->current_snapshot === $input['snapshot_id'] && $o->version === $input['version'], 409);
        $source = app(Orders::class)->snapshot($o);
        $blocks = [];
        if ($c->state !== 'authorized') {
            $blocks[] = 'STORE_NOT_AUTHORIZED';
        }if ($o->state !== 'ready' || $source['payment'] !== 'paid' || $source['fulfillment'] !== 'unfulfilled') {
            $blocks[] = 'SOURCE_NOT_ELIGIBLE';
        }if ($o->review_required) {
            $blocks[] = 'SOURCE_REVIEW_REQUIRED';
        }
        if (($source['currency'] ?? null) !== 'SAR') {
            $blocks[] = 'CURRENCY_NOT_SUPPORTED';
        }if (! isset($source['amount_minor'])) {
            $blocks[] = 'AMOUNT_UNKNOWN';
        }if (! isset($source['cod_minor'])) {
            $blocks[] = 'COD_UNKNOWN';
        } elseif ($source['cod_minor'] !== '0') {
            $blocks[] = 'COD_NOT_SUPPORTED';
        }
        $review = $input['review'];
        $draft = ['currency' => $source['currency'] ?? null, 'declared_value_minor' => $source['amount_minor'] ?? null, 'cod_minor' => $source['cod_minor'] ?? null, 'destination' => array_replace($source['recipient'], $review['destination'] ?? []), 'items' => []] + array_diff_key($review, array_flip(['destination']));
        if (isset($review['items'])) {
            $draft['items'] = $review['items'];
        }$errors = [];
        try {
            $draft = ShipmentInput::draft($draft);
            $errors = app(DraftReadiness::class)->report($draft)['missing'];
        } catch (ValidationException $e) {
            $errors = array_keys($e->errors());
        }
        $hash = ShipmentInput::digest(['order' => $id, 'snapshot' => $input['snapshot_id'], 'version' => $input['version'], 'review' => $review, 'acknowledge_changes' => $input['acknowledge_changes'] ?? false]);

        return ['valid' => ! $blocks && ! $errors, 'fields' => $errors, 'blocks' => $blocks, 'preview_hash' => $hash, 'snapshot_id' => $o->current_snapshot, 'version' => $o->version, 'shipment_draft' => $draft, 'source_provenance' => $c->provenance, 'submission_enabled' => false];
    }

    /** Caller holds catalog, merchant and originating authority. Never edits an existing draft. */
    public function persist(object $origin, string $order, array $d): array
    {
        $m = $origin->merchant_id;
        $o = app(Orders::class)->row($m, $order);
        $c = app(Access::class)->connection($m, $o->connection_id);
        abort_unless($c->state === 'authorized' && $c->generation === $origin->generation, 409);
        if ($link = DB::table('commerce_draft_links')->where('merchant_id', $m)->where('order_id', $order)->first()) {
            return ['shipment_id' => $link->shipment_id, 'state' => 'existing', 'source_changed' => $o->current_snapshot !== $link->snapshot_id];
        }
        $p = $this->preview($m, $order, $d);
        abort_unless($p['valid'] && isset($d['preview_hash']) && hash_equals($p['preview_hash'], $d['preview_hash']), 422);
        $result = app(Shipments::class)->importDraft($origin, $p['shipment_draft'], 'commerce:'.$o->connection_id.':'.$order);
        $shipment = $result['shipment']->id ?? $result['shipment']['id'];
        DB::table('commerce_draft_links')->insert(['merchant_id' => $m, 'order_id' => $order, 'snapshot_id' => $o->current_snapshot, 'shipment_id' => $shipment, 'actor_id' => $origin->actor_id, 'review_hash' => $p['preview_hash']]);
        app(Access::class)->event($m, $c->id, 'draft_prepared', $origin->actor_id, ['order_id' => $order, 'shipment_id' => $shipment, 'source_reviewed' => (bool) ($d['acknowledge_changes'] ?? false)]);

        return ['shipment_id' => $shipment, 'state' => 'created', 'source_changed' => false];
    }

    public function create(Request $r, MerchantContext $c, string $order): array
    {
        $d = $this->input($r);

        return DB::transaction(function () use ($r, $c, $order, $d) {
            Catalog::lock(true);

            return app(Access::class)->locked($r, $c, 'orders.prepare', function ($m) use ($r, $c, $order, $d) {
                $c->require('orders.read');
                $c->require('shipments.write');
                $actor = $c->actorId();
                $o = app(Orders::class)->row($m, $order);
                $conn = app(Access::class)->connection($m, $o->connection_id);
                $origin = (object) (['merchant_id' => $m, 'connection_id' => $conn->id, 'generation' => $conn->generation] + app(Access::class)->origin($r, $c));

                return app(Access::class)->command($r, $m, $actor, 'draft:'.$order, $d, fn () => $this->persist($origin, $order, $d));
            });
        });
    }
}
