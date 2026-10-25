<?php

namespace App\Modules\Commerce\Application;

use App\Modules\Commerce\Domain\OrderData;
use App\Modules\Shipping\Domain\ShipmentInput;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Orders
{
    public function hash(string $reference): string
    {
        return hash_hmac('sha256', trim($reference), config('app.key'));
    }

    public function ingest(object $connection, array $input): void
    {
        $d = OrderData::read($input);
        $q = DB::table('commerce_orders')->where('merchant_id', $connection->merchant_id)->where('connection_id', $connection->id)->where('external_order_id', $d['id']);
        $o = $q->lockForUpdate()->first();
        $id = $o->id ?? (string) Str::uuid();
        $digest = ShipmentInput::digest($d);
        if ($o && DB::table('commerce_snapshots')->where('order_id', $id)->where('digest', $digest)->exists()) {
            return;
        }
        if (! $o) {
            DB::table('commerce_orders')->insert(['id' => $id, 'merchant_id' => $connection->merchant_id, 'connection_id' => $connection->id, 'external_order_id' => $d['id'], 'reference_hash' => $this->hash($d['reference']), 'source_created_at' => $d['created_at'], 'state' => $d['status']]);
        }
        $snapshot = (string) Str::uuid();
        DB::table('commerce_snapshots')->insert(['id' => $snapshot, 'merchant_id' => $connection->merchant_id, 'order_id' => $id, 'payload' => Crypt::encryptString(json_encode($d)), 'digest' => $digest, 'source_updated_at' => $d['updated_at'] ?? null]);
        $old = $o ? DB::table('commerce_snapshots')->where('id', $o->current_snapshot)->first() : null;
        $newer = ! $old || ($old->source_updated_at && ! empty($d['updated_at']) && CarbonImmutable::parse($d['updated_at'])->gt($old->source_updated_at));
        $linked = DB::table('commerce_draft_links')->where('order_id', $id)->exists();
        $values = ['version' => ($o->version ?? 0) + 1, 'updated_at' => now(), 'review_required' => ! $newer || $linked];
        if ($newer) {
            $values += ['current_snapshot' => $snapshot, 'state' => $d['status'], 'reference_hash' => $this->hash($d['reference'])];
        }
        DB::table('commerce_orders')->where('id', $id)->update($values);
        app(Access::class)->event($connection->merchant_id, $connection->id, $o ? 'order_observed' : 'order_imported', metadata: ['order_id' => $id, 'current' => $newer, 'review_required' => $values['review_required']]);
    }

    public function row(string $merchant, string $id): object
    {
        $o = DB::table('commerce_orders')->where('merchant_id', $merchant)->where('id', $id)->first();
        abort_unless($o, 404);

        return $o;
    }

    public function snapshot(object $o): array
    {
        return json_decode(Crypt::decryptString(DB::table('commerce_snapshots')->where('merchant_id', $o->merchant_id)->where('order_id', $o->id)->where('id', $o->current_snapshot)->value('payload')), true);
    }

    public function detail(string $m, string $id): array
    {
        $o = $this->row($m, $id);

        return ['order' => $o, 'snapshot' => $this->snapshot($o), 'draft_link' => DB::table('commerce_draft_links')->where('merchant_id', $m)->where('order_id', $id)->first(), 'history' => DB::table('commerce_snapshots')->where('merchant_id', $m)->where('order_id', $id)->orderByDesc('created_at')->limit(25)->get(['id', 'source_updated_at', 'created_at']), 'external_status_confirmed_live' => false];
    }

    public function directory(Request $r, string $m): array
    {
        $d = $r->validate(['connection' => 'sometimes|uuid', 'status' => 'sometimes|in:ready,cancelled,fulfilled,unknown', 'reference' => 'sometimes|string|max:100', 'from' => 'sometimes|date_format:Y-m-d', 'to' => 'sometimes|date_format:Y-m-d|after_or_equal:from', 'after' => 'sometimes|uuid']);
        $q = DB::table('commerce_orders as o')->join('commerce_snapshots as s', fn ($j) => $j->on('s.id', '=', 'o.current_snapshot')->on('s.merchant_id', '=', 'o.merchant_id')->on('s.order_id', '=', 'o.id'))->leftJoin('commerce_draft_links as l', fn ($j) => $j->on('l.order_id', '=', 'o.id')->on('l.merchant_id', '=', 'o.merchant_id'))->where('o.merchant_id', $m);
        if (isset($d['connection'])) {
            $this->rowConnection($m, $d['connection']);
            $q->where('o.connection_id', $d['connection']);
        }if (isset($d['status'])) {
            $q->where('o.state', $d['status']);
        }if (isset($d['reference'])) {
            $q->where('o.reference_hash', $this->hash($d['reference']));
        }if (isset($d['from'])) {
            $q->where('o.source_created_at', '>=', $d['from'].'T00:00:00Z');
        }if (isset($d['to'])) {
            $q->where('o.source_created_at', '<', CarbonImmutable::parse($d['to'], 'UTC')->addDay());
        }if (isset($d['after'])) {
            $q->where('o.id', '>', $d['after']);
        }
        $rows = $q->orderBy('o.id')->limit(26)->get(['o.id', 'o.connection_id', 'o.state', 'o.version', 'o.current_snapshot', 'o.review_required', 'o.source_created_at', 's.payload', 'l.shipment_id']);
        $more = $rows->count() > 25;
        $rows = $rows->take(25)->values();
        foreach ($rows as $o) {
            $s = json_decode(Crypt::decryptString($o->payload), true);
            $o->reference = $s['reference'];
            $o->payment = $s['payment'];
            $o->fulfillment = $s['fulfillment'];
            $o->currency = $s['currency'];
            $o->cod_minor = $s['cod_minor'];
            unset($o->payload);
        }

        return ['orders' => $rows, 'next_after' => $more ? $rows->last()->id : null, 'source' => 'salla', 'live_status_verified' => false, 'sort' => 'internal_uuid_ascending'];
    }

    private function rowConnection(string $m, string $id): void
    {
        app(Access::class)->connection($m, $id);
    }
}
