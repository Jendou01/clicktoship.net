<?php

namespace App\Modules\Shipping\Application;

use App\Modules\Identity\Application\Audit;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Shipping\Domain\ShipmentInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Shipments
{
    public function row(string $merchant, string $id): object
    {
        $s = DB::table('shipments')->where('merchant_id', $merchant)->where('id', $id)->first();
        abort_unless($s, 404);

        return $s;
    }

    public function revision(object $s): array
    {
        return json_decode(Crypt::decryptString(DB::table('shipment_revisions')->where('merchant_id', $s->merchant_id)->where('shipment_id', $s->id)->where('revision', $s->revision)->value('payload')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function show(string $merchant, string $id): array
    {
        $s = $this->row($merchant, $id);
        $acceptance = DB::table('pricing_quote_acceptances')->where('merchant_id', $merchant)->where('shipment_id', $id)->where('revision', $s->revision)->first();

        return ['shipment' => $s, 'draft' => $this->revision($s), 'quote' => $acceptance ? json_decode($acceptance->snapshot, true) : null, 'validation' => ShipmentInput::report($this->revision($s)), 'submission_enabled' => false];
    }

    /** Minimal merchant-authorized directory context; no addresses, phone or carrier credentials. */
    public function summary(string $merchant, object $shipment): array
    {
        $payload = DB::table('shipment_revisions')->where('merchant_id', $merchant)->where('shipment_id', $shipment->id)->where('revision', $shipment->revision)->value('payload');
        $draft = json_decode(Crypt::decryptString($payload), true, flags: JSON_THROW_ON_ERROR);

        return ['description' => $draft['description'], 'recipient' => $draft['destination']['name'] ?? null];
    }

    public function event(string $merchant, string $shipment, string $kind, ?string $actor = null, array $metadata = []): void
    {
        DB::table('shipment_events')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $merchant, 'shipment_id' => $shipment, 'kind' => $kind, 'actor_id' => $actor, 'metadata' => json_encode($metadata)]);
        Audit::record('shipping.'.$kind, $actor, $merchant, $shipment);
    }

    public function replay(Request $r, MerchantContext $c, string $operation, array $payload, callable $work): array
    {
        $key = $r->validate(['idempotency_key' => 'required|string|min:8|max:128'])['idempotency_key'];
        $digest = ShipmentInput::digest($payload);
        $q = DB::table('shipping_request_keys')->where('merchant_id', $c->id())->where('actor_id', $c->actorId())->where('operation', $operation)->where('key_hash', hash('sha256', $key));
        if ($old = $q->first()) {
            abort_unless(hash_equals($old->digest, $digest), 409);

            return json_decode($old->result, true);
        }
        $result = $work();
        DB::table('shipping_request_keys')->insert(['merchant_id' => $c->id(), 'actor_id' => $c->actorId(), 'operation' => $operation, 'key_hash' => hash('sha256', $key), 'digest' => $digest, 'result' => json_encode($result)]);

        return json_decode($q->value('result'), true);
    }

    public function write(Request $r, MerchantContext $c, ?string $id = null): array
    {
        $d = $r->validate(['draft' => 'required|array', 'version' => $id ? 'required|integer|min:1' : 'prohibited', 'merchant_id' => 'prohibited']);
        $draft = ShipmentInput::draft($d['draft']);

        return DB::transaction(function () use ($r, $c, $id, $d, $draft) {
            app(ShippingAccess::class)->lock($r, $c, 'shipments.write');

            return $this->replay($r, $c, $id ? 'draft:'.$id : 'draft.create', $d, function () use ($c, $id, $d, $draft) {
                $merchant = $c->id();
                $revision = 1;
                if ($id) {
                    $s = $this->row($merchant, $id);
                    abort_unless(in_array($s->state, ['draft', 'ready']) && $s->version === $d['version'], 409);
                    $revision = $s->revision + 1;
                    DB::table('shipments')->where('id', $id)->update(['revision' => $revision, 'version' => $s->version + 1, 'state' => 'draft', 'updated_at' => now()]);
                } else {
                    $id = (string) Str::uuid();
                    DB::table('shipments')->insert(['id' => $id, 'merchant_id' => $merchant]);
                }
                DB::table('shipment_revisions')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $merchant, 'shipment_id' => $id, 'revision' => $revision, 'payload' => Crypt::encryptString(json_encode($draft)), 'input_hash' => ShipmentInput::digest($draft), 'pricing_hash' => ShipmentInput::digest(ShipmentInput::pricing($draft)), 'actor_id' => $c->actorId()]);
                $this->event($merchant, $id, 'draft_saved', $c->actorId(), ['revision' => $revision]);

                return $this->show($merchant, $id);
            });
        });
    }

    public function validate(Request $r, MerchantContext $c, string $id): array
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);

        return DB::transaction(function () use ($r, $c, $id, $d) {
            app(ShippingAccess::class)->lock($r, $c, 'shipments.write');
            $s = $this->row($c->id(), $id);
            abort_unless(in_array($s->state, ['draft', 'ready']) && $s->version === $d['version'], 409);
            $report = ShipmentInput::report($this->revision($s));
            DB::table('shipment_validations')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $c->id(), 'shipment_id' => $id, 'revision' => $s->revision, 'valid' => $report['valid'], 'report' => json_encode($report)]);
            DB::table('shipments')->where('id', $id)->update(['state' => $report['valid'] ? 'ready' : 'draft', 'version' => $s->version + 1, 'updated_at' => now()]);
            $this->event($c->id(), $id, 'validated', $c->actorId(), ['valid' => $report['valid']]);

            return $this->show($c->id(), $id);
        });
    }

    public function cancel(Request $r, MerchantContext $c, string $id): array
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);

        return DB::transaction(function () use ($r, $c, $id, $d) {
            app(ShippingAccess::class)->lock($r, $c, 'shipments.cancel');

            return $this->replay($r, $c, 'cancel:'.$id, $d, function () use ($c, $id, $d) {
                $s = $this->row($c->id(), $id);
                abort_unless(in_array($s->state, ['draft', 'ready']) && $s->version === $d['version'], 409);
                DB::table('shipments')->where('id', $id)->update(['state' => 'cancelled', 'version' => $s->version + 1, 'updated_at' => now()]);
                $this->event($c->id(), $id, 'cancelled', $c->actorId());

                return $this->show($c->id(), $id);
            });
        });
    }
}
