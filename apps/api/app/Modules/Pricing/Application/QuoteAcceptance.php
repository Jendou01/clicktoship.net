<?php

namespace App\Modules\Pricing\Application;

use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Shipping\Application\Shipments;
use App\Modules\Shipping\Application\ShippingAccess;
use App\Modules\Shipping\Domain\ShipmentInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class QuoteAcceptance
{
    public function accept(Request $r, MerchantContext $c, string $shipment): array
    {
        $d = $r->validate(['quote_id' => 'required|uuid', 'version' => 'required|integer|min:1']);

        return DB::transaction(function () use ($r, $c, $shipment, $d) {
            Catalog::lock(true);
            app(ShippingAccess::class)->lock($r, $c, 'shipments.write');
            $c->require('rates.quote');

            return app(Shipments::class)->replay($r, $c, 'quote:'.$shipment, $d, function () use ($c, $shipment, $d) {
                $s = app(Shipments::class)->row($c->id(), $shipment);
                abort_unless($s->state === 'ready' && $s->version === $d['version'], 409);
                $draft = app(Shipments::class)->revision($s);
                $q = app(Quotes::class)->show($c->id(), $d['quote_id']);
                abort_unless(app(Quotes::class)->valid($c->id(), $d['quote_id'])['valid'], 409);
                abort_unless(hash_equals(ShipmentInput::digest($q['inputs']), ShipmentInput::digest(ShipmentInput::pricing($draft))), 409);
                abort_if(DB::table('pricing_quote_acceptances')->where('merchant_id', $c->id())->where(function ($q) use ($s, $d) {
                    $q->where('quote_id', $d['quote_id'])->orWhere(function ($q) use ($s) {
                        $q->where('shipment_id', $s->id)->where('revision', $s->revision);
                    });
                })->exists(), 409);
                $amount = $q['total_minor'];
                abort_unless((int) $amount > 0, 422);
                DB::table('pricing_quote_acceptances')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $c->id(), 'shipment_id' => $s->id, 'revision' => $s->revision, 'quote_id' => $d['quote_id'], 'input_hash' => ShipmentInput::digest($draft), 'pricing_hash' => ShipmentInput::digest($q['inputs']), 'amount' => $amount, 'currency' => $q['currency'], 'snapshot' => json_encode($q)]);
                app(Shipments::class)->event($c->id(), $shipment, 'quote_accepted', $c->actorId(), ['quote_id' => $d['quote_id']]);

                return app(Shipments::class)->show($c->id(), $shipment);
            });
        });
    }

    public function committed(string $merchant, string $shipment, int $revision): object
    {
        $a = DB::table('pricing_quote_acceptances')->where('merchant_id', $merchant)->where('shipment_id', $shipment)->where('revision', $revision)->first();
        abort_unless($a, 409);
        abort_unless(app(Quotes::class)->valid($merchant, $a->quote_id)['valid'], 409);

        return $a;
    }
}
