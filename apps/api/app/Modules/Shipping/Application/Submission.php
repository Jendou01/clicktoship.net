<?php

namespace App\Modules\Shipping\Application;

use App\Models\PersonalAccessToken;
use App\Modules\Carriers\Application\ShipmentCarrierOperations;
use App\Modules\Carriers\Domain\CarrierFailure;
use App\Modules\Carriers\Domain\ShipmentGateway;
use App\Modules\Carriers\Domain\ShipmentOutcome;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Operations\Application\PlatformSecurity;
use App\Modules\Pricing\Application\Catalog;
use App\Modules\Pricing\Application\QuoteAcceptance;
use App\Modules\Shipping\Domain\ShipmentInput;
use App\Modules\WalletBilling\Application\Ledger;
use App\Modules\WalletBilling\Application\ShipmentSettlement;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class Submission
{
    public function create(Request $r, MerchantContext $c, string $shipment): array
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);

        return DB::transaction(function () use ($r, $c, $shipment, $d) {
            Catalog::lock(true);
            app(ShippingAccess::class)->lock($r, $c, 'shipments.submit');
            app(Shipments::class)->row($c->id(), $shipment);

            return app(Shipments::class)->replay($r, $c, 'submit:'.$shipment, $d, function () use ($r, $c, $shipment, $d) {
                $s = app(Shipments::class)->row($c->id(), $shipment);
                abort_unless($s->state === 'ready' && $s->version === $d['version'], 409);
                $a = app(QuoteAcceptance::class)->committed($c->id(), $shipment, $s->revision);
                $draft = app(Shipments::class)->revision($s);
                abort_unless(hash_equals($a->input_hash, ShipmentInput::digest($draft)), 409);
                // No runtime flag can cross the normal external gate. No hold before this check.
                app(ShipmentGateway::class)->assertReady('CreateShipments');
                abort_unless(app()->environment('testing') && in_array(DB::connection()->getDatabaseName(), ['clicktoship_test', 'clicktoship_e2e']), 503);
                $id = (string) Str::uuid();
                $wallet = app(Ledger::class)->provision($c->id(), $a->currency);
                $op = app(ShipmentGateway::class)->prepare($c, 'shipment:'.$id, $draft);
                $hold = app(Ledger::class)->command($c->id(), $wallet, 'reserve', (string) $a->amount, 'shipment:'.$id.':reserve', $id, 'shipping.reserve', expires: now()->addHour()->toIso8601String());
                $token = $r->user()->currentAccessToken();
                DB::table('shipment_submissions')->insert(['id' => $id, 'merchant_id' => $c->id(), 'shipment_id' => $shipment, 'revision' => $s->revision, 'acceptance_id' => $a->id, 'wallet_id' => $wallet, 'hold_id' => $hold['hold_id'], 'amount' => $a->amount, 'currency' => $a->currency, 'carrier_operation_id' => $op, 'actor_id' => $r->user()->id, 'auth_version' => $r->user()->auth_version, 'origin_token_id' => $token instanceof PersonalAccessToken ? $token->id : null, 'origin_session_id' => $token instanceof PersonalAccessToken ? null : $r->session()->get('auth_session_id')]);
                DB::table('shipments')->where('id', $shipment)->update(['state' => 'queued', 'financial_state' => 'reserved', 'version' => $s->version + 1, 'updated_at' => now()]);
                DB::table('shipping_outbox')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $c->id(), 'submission_id' => $id]);
                app(Shipments::class)->event($c->id(), $shipment, 'reserved', $c->actorId(), ['submission_id' => $id]);

                return ['submission_id' => $id, 'shipment_id' => $shipment, 'state' => 'queued'];
            });
        });
    }

    public function row(string $merchant, string $id): object
    {
        $s = DB::table('shipment_submissions')->where('merchant_id', $merchant)->where('id', $id)->first();
        abort_unless($s, 404);

        return $s;
    }

    public function process(string $merchant, string $id): void
    {
        $prepared = DB::transaction(function () use ($merchant, $id) {
            PlatformSecurity::lock();
            Catalog::lock(true);
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
            $s = $this->row($merchant, $id);
            $shipment = app(Shipments::class)->row($merchant, $s->shipment_id);
            if ($shipment->state !== 'queued') {
                return null;
            }
            try {
                app(ShippingAccess::class)->worker($s);
                app(QuoteAcceptance::class)->committed($merchant, $s->shipment_id, $s->revision);
                app(ShipmentGateway::class)->assertReady('CreateShipments');
                $h = DB::table('balance_holds')->where('id', $s->hold_id)->first();
                abort_unless($h && now()->lt($h->expires_at), 409);
                $op = app(ShipmentCarrierOperations::class)->begin($merchant, $s->carrier_operation_id);
                app(Ledger::class)->command($merchant, $s->wallet_id, 'protect', '0', 'shipment:'.$id.':protect', $id.':protect', 'shipping.dispatch', $s->hold_id);
                app(QuoteAcceptance::class)->committed($merchant, $s->shipment_id, $s->revision);
                DB::table('shipments')->where('id', $s->shipment_id)->update(['state' => 'sending', 'financial_state' => 'protected', 'updated_at' => now()]);
                app(Shipments::class)->event($merchant, $s->shipment_id, 'dispatch_started');

                return $op;
            } catch (HttpException|CarrierFailure $e) {
                // Transaction rolls back first. A fresh transaction performs proven-unsent cleanup.
                throw $e;
            }
        });
        if (! $prepared) {
            $this->settle($merchant, $id);

            return;
        }
        try {
            $outcome = app(ShipmentGateway::class)->send($prepared);
        } catch (\Throwable) {
            $outcome = new ShipmentOutcome('unknown', hash('sha256', 'transport_unknown:'.$id));
        }
        $this->observe($merchant, $id, $outcome);
        $this->settle($merchant, $id);
    }

    public function blockUnsent(string $merchant, string $id): void
    {
        DB::transaction(function () use ($merchant, $id) {
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
            $s = $this->row($merchant, $id);
            $shipment = app(Shipments::class)->row($merchant, $s->shipment_id);
            if ($shipment->state !== 'queued') {
                return;
            }
            app(ShipmentCarrierOperations::class)->block($merchant, $s->carrier_operation_id);
            DB::table('shipments')->where('id', $s->shipment_id)->update(['state' => 'blocked', 'financial_state' => 'release_pending', 'updated_at' => now()]);
            app(Shipments::class)->event($merchant, $s->shipment_id, 'blocked_before_dispatch');
        });
        $this->settle($merchant, $id);
    }

    public function observe(string $merchant, string $id, ShipmentOutcome $outcome): string
    {
        return DB::transaction(function () use ($merchant, $id, $outcome) {
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
            $s = $this->row($merchant, $id);
            $existing = DB::table('shipping_observations')->where('submission_id', $id)->where('evidence_hash', $outcome->evidenceHash)->first();
            if ($existing) {
                abort_unless($existing->outcome === $outcome->outcome && $existing->carrier_reference === $outcome->reference, 409);

                return $existing->id;
            }
            $oid = (string) Str::uuid();
            DB::table('shipping_observations')->insert(['id' => $oid, 'merchant_id' => $merchant, 'submission_id' => $id, 'outcome' => $outcome->outcome, 'evidence_hash' => $outcome->evidenceHash, 'carrier_reference' => $outcome->reference, 'provenance' => 'synthetic_test_only']);
            $shipment = app(Shipments::class)->row($merchant, $s->shipment_id);
            if ($shipment->state === 'sending') {
                app(ShipmentCarrierOperations::class)->finish($merchant, $s->carrier_operation_id, $outcome);
                DB::table('shipments')->where('id', $s->shipment_id)->update(['carrier_observation_id' => $oid, 'state' => $outcome->outcome, 'financial_state' => match ($outcome->outcome) {
                    'accepted' => 'capture_pending','rejected' => 'release_pending',default => 'review'
                }, 'updated_at' => now()]);
                app(Shipments::class)->event($merchant, $s->shipment_id, 'carrier_'.$outcome->outcome);
            } else {
                DB::table('shipments')->where('id', $s->shipment_id)->update(['exception_code' => 'CONFLICTING_CARRIER_EVIDENCE']);
                app(Shipments::class)->event($merchant, $s->shipment_id, 'supplementary_evidence', null, ['observation_id' => $oid]);
            }

            return $oid;
        });
    }

    public function settle(string $merchant, string $id): void
    {
        try {
            DB::transaction(function () use ($merchant, $id) {
                DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
                $s = $this->row($merchant, $id);
                $shipment = app(Shipments::class)->row($merchant, $s->shipment_id);
                if ($shipment->state === 'unknown') {
                    $h = DB::table('balance_holds')->where('id', $s->hold_id)->first();
                    if (! $h->unknown) {
                        app(Ledger::class)->command($merchant, $s->wallet_id, 'unknown', '0', 'shipment:'.$id.':unknown', $id.':unknown', 'shipping.settlement', $s->hold_id);
                    }

                    return;
                }
                if (! in_array($shipment->financial_state, ['capture_pending', 'release_pending'])) {
                    return;
                }
                if ($shipment->state === 'blocked') {
                    app(Ledger::class)->command($merchant, $s->wallet_id, 'release', (string) $s->amount, 'shipment:'.$id.':unsent', $id.':unsent', 'shipping.settlement', $s->hold_id);
                    $state = 'released';
                } else {
                    $o = DB::table('shipping_observations')->where('submission_id', $id)->where('id', $shipment->carrier_observation_id)->first();
                    abort_unless($o && $o->outcome === $shipment->state, 409);
                    app(ShipmentSettlement::class)->finalize($s, $o);
                    $state = $o->outcome === 'accepted' ? 'captured' : 'released';
                }
                DB::table('shipments')->where('id', $s->shipment_id)->update(['financial_state' => $state, 'updated_at' => now()]);
                app(Shipments::class)->event($merchant, $s->shipment_id, $state);
            });
        } catch (QueryException|HttpException $e) {
            // Keep the pending financial state. Recovery retries only local finalization.
            Log::warning('SHIPMENT_FINANCIAL_FINALIZATION_PENDING', ['submission_id' => $id]);
        }
    }
}
