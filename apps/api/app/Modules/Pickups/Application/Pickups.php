<?php

namespace App\Modules\Pickups\Application;

use App\Modules\Carriers\Application\ShipmentCarrierOperations;
use App\Modules\Carriers\Domain\CarrierFailure;
use App\Modules\Carriers\Domain\ShipmentGateway;
use App\Modules\Carriers\Domain\ShipmentOutcome;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Operations\Application\PlatformSecurity;
use App\Modules\Shipping\Application\Shipments;
use App\Modules\Shipping\Application\ShippingAccess;
use App\Modules\Shipping\Domain\ShipmentInput;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class Pickups
{
    public function row(string $merchant, string $id): object
    {
        $p = DB::table('pickups')->where('merchant_id', $merchant)->where('id', $id)->first();
        abort_unless($p, 404);

        return $p;
    }

    public function show(string $merchant, string $id): array
    {
        $p = $this->row($merchant, $id);

        return ['id' => $p->id, 'version' => $p->version, 'state' => $p->state, 'window_start' => $p->window_start, 'window_end' => $p->window_end, 'timezone' => $p->timezone, 'shipment_ids' => DB::table('pickup_shipments')->where('merchant_id', $merchant)->where('pickup_id', $id)->pluck('shipment_id'), 'events' => DB::table('pickup_events')->where('merchant_id', $merchant)->where('pickup_id', $id)->orderBy('created_at')->get(), 'external_enabled' => false];
    }

    private function event(string $merchant, string $id, string $kind): void
    {
        DB::table('pickup_events')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $merchant, 'pickup_id' => $id, 'kind' => $kind]);
    }

    public function create(Request $r, MerchantContext $c): array
    {
        $d = $r->validate(['shipment_ids' => 'required|array|min:1|max:100', 'shipment_ids.*' => 'required|uuid|distinct', 'window_start' => 'required|date_format:Y-m-d\TH:i:sP|after:now', 'window_end' => 'required|date_format:Y-m-d\TH:i:sP|after:window_start', 'timezone' => 'required|timezone', 'merchant_id' => 'prohibited']);

        return DB::transaction(function () use ($r, $c, $d) {
            app(ShippingAccess::class)->lock($r, $c, 'pickups.manage');

            return app(Shipments::class)->replay($r, $c, 'pickup.create', $d, function () use ($c, $d) {
                $origin = null;
                foreach ($d['shipment_ids'] as $sid) {
                    $s = app(Shipments::class)->row($c->id(), $sid);
                    abort_unless(in_array($s->state, ['ready', 'accepted']), 409);
                    $draft = app(Shipments::class)->revision($s);
                    $hash = ShipmentInput::digest($draft['origin']);
                    abort_if($origin && $origin !== $hash, 422);
                    $origin = $hash;
                }
                abort_if(Carbon::parse($d['window_start'])->diffInHours(Carbon::parse($d['window_end'])) > 24, 422);
                $id = (string) Str::uuid();
                DB::table('pickups')->insert(['id' => $id, 'merchant_id' => $c->id(), 'window_start' => $d['window_start'], 'window_end' => $d['window_end'], 'timezone' => $d['timezone'], 'origin_hash' => $origin, 'payload' => Crypt::encryptString(json_encode($d))]);
                foreach ($d['shipment_ids'] as $sid) {
                    DB::table('pickup_shipments')->insert(['merchant_id' => $c->id(), 'pickup_id' => $id, 'shipment_id' => $sid]);
                }
                $this->event($c->id(), $id, 'draft_created');

                return $this->show($c->id(), $id);
            });
        });
    }

    public function command(Request $r, MerchantContext $c, string $id, string $action): array
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);

        return DB::transaction(function () use ($r, $c, $id, $action, $d) {
            app(ShippingAccess::class)->lock($r, $c, 'pickups.manage');

            return app(Shipments::class)->replay($r, $c, 'pickup:'.$action.':'.$id, $d, function () use ($r, $c, $id, $action, $d) {
                $p = $this->row($c->id(), $id);
                abort_unless($p->version === $d['version'], 409);
                if ($action === 'cancel' && $p->state === 'draft') {
                    DB::table('pickups')->where('id', $id)->update(['state' => 'cancelled', 'version' => $p->version + 1]);
                    $this->event($c->id(), $id, 'cancelled_locally');

                    return $this->show($c->id(), $id);
                }
                abort_unless($action === 'submit' ? $p->state === 'draft' : $p->state === 'confirmed', 409);
                $operation = $action === 'submit' ? 'CreatePickup' : 'CancelPickup';
                app(ShipmentGateway::class)->assertReady($operation);
                abort_unless(app()->environment('testing') && in_array(DB::connection()->getDatabaseName(), ['clicktoship_test', 'clicktoship_e2e']), 503);
                $drafts = [];
                $account = null;
                foreach (DB::table('pickup_shipments')->where('merchant_id', $c->id())->where('pickup_id', $id)->pluck('shipment_id') as $sid) {
                    $s = app(Shipments::class)->row($c->id(), $sid);
                    abort_unless($s->state === 'accepted' && $s->financial_state === 'captured', 409);
                    $sub = DB::table('shipment_submissions')->where('merchant_id', $c->id())->where('shipment_id', $sid)->first();
                    $op = DB::table('carrier_operations')->where('id', $sub->carrier_operation_id)->first();
                    abort_if($account && $account !== $op->account_id, 422);
                    $account = $op->account_id;
                    $drafts[] = $sid;
                    if ($action === 'submit') {
                        abort_if(DB::table('pickup_shipments as ps')->join('pickups as p', 'p.id', '=', 'ps.pickup_id')->where('ps.merchant_id', $c->id())->where('ps.shipment_id', $sid)->where('p.id', '<>', $id)->whereIn('p.state', ['sending', 'confirmed', 'unknown', 'cancel_pending'])->exists(), 409);
                    }
                }
                $oid = app(ShipmentGateway::class)->prepare($c, 'pickup:'.$id.':'.$action, ['shipment_ids' => $drafts, 'window_start' => $p->window_start, 'window_end' => $p->window_end, 'account_id' => $account], $operation);
                abort_unless(DB::table('carrier_operations')->where('id', $oid)->where('merchant_id', $c->id())->where('account_id', $account)->exists(), 409);
                $token = $r->user()->currentAccessToken();
                DB::table('pickup_commands')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $c->id(), 'pickup_id' => $id, 'operation_id' => $oid, 'kind' => $action, 'actor_id' => $r->user()->id, 'auth_version' => $r->user()->auth_version, 'origin_token_id' => $c->tokenId(), 'origin_session_id' => $c->tokenId() ? null : $r->session()->get('auth_session_id')]);
                DB::table('pickups')->where('id', $id)->update(['state' => $action === 'submit' ? 'sending' : 'cancel_pending', 'version' => $p->version + 1, 'carrier_operation_id' => $oid]);
                $this->event($c->id(), $id, $action.'_queued');

                return $this->show($c->id(), $id);
            });
        });
    }

    public function process(string $merchant, string $command): void
    {
        $op = DB::transaction(function () use ($merchant, $command) {
            PlatformSecurity::lock();
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
            $cmd = DB::table('pickup_commands')->where('merchant_id', $merchant)->where('id', $command)->first();
            abort_unless($cmd, 404);
            $o = app(ShipmentCarrierOperations::class)->row($merchant, $cmd->operation_id);
            if ($o->state !== 'pending') {
                return null;
            }
            app(ShippingAccess::class)->worker($cmd, 'pickups.manage');
            app(ShipmentGateway::class)->assertReady($cmd->kind === 'submit' ? 'CreatePickup' : 'CancelPickup');

            return app(ShipmentCarrierOperations::class)->begin($merchant, $cmd->operation_id);
        });
        if (! $op) {
            return;
        }
        try {
            $outcome = app(ShipmentGateway::class)->send($op);
        } catch (\Throwable) {
            $outcome = new ShipmentOutcome('unknown', hash('sha256', 'pickup_unknown:'.$command));
        }
        $this->finish($merchant, $command, $outcome);
    }

    public function finish(string $merchant, string $command, ShipmentOutcome $outcome): void
    {
        DB::transaction(function () use ($merchant, $command, $outcome) {
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
            $cmd = DB::table('pickup_commands')->where('merchant_id', $merchant)->where('id', $command)->first();
            abort_unless($cmd, 404);
            $op = app(ShipmentCarrierOperations::class)->row($merchant, $cmd->operation_id);
            if ($op->state !== 'sending') {
                return;
            }
            app(ShipmentCarrierOperations::class)->finish($merchant, $cmd->operation_id, $outcome);
            $state = $outcome->outcome === 'unknown' ? 'unknown' : ($cmd->kind === 'cancel' ? ($outcome->outcome === 'accepted' ? 'cancelled' : 'confirmed') : ($outcome->outcome === 'accepted' ? 'confirmed' : 'rejected'));
            DB::table('pickups')->where('id', $cmd->pickup_id)->update(['state' => $state]);
            $this->event($merchant, $cmd->pickup_id, $cmd->kind.'_'.$outcome->outcome);
        });
    }

    private function blockUnsent(string $merchant, string $command): void
    {
        DB::transaction(function () use ($merchant, $command) {
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
            $c = DB::table('pickup_commands')->where('merchant_id', $merchant)->where('id', $command)->first();
            if (! $c) {
                return;
            } $op = app(ShipmentCarrierOperations::class)->row($merchant, $c->operation_id);
            if ($op->state !== 'pending') {
                return;
            }
            app(ShipmentCarrierOperations::class)->block($merchant, $c->operation_id);
            DB::table('pickups')->where('id', $c->pickup_id)->update(['state' => $c->kind === 'submit' ? 'rejected' : 'confirmed']);
            $this->event($merchant, $c->pickup_id, 'blocked_before_dispatch');
        });
    }

    public function recover(): void
    {
        DB::table('pickup_commands')->orderBy('created_at')->chunk(100, function ($commands) {
            foreach ($commands as $c) {
                $op = DB::table('carrier_operations')->where('id', $c->operation_id)->first();
                if ($op->state === 'sending' && now()->subMinutes(5)->gt($op->updated_at)) {
                    $this->finish($c->merchant_id, $c->id, new ShipmentOutcome('unknown', hash('sha256', 'pickup_worker_lost:'.$c->id)));
                } elseif ($op->state === 'pending') {
                    try {
                        $this->process($c->merchant_id, $c->id);
                    } catch (HttpException|CarrierFailure) {
                        $this->blockUnsent($c->merchant_id, $c->id);
                    }
                }
            }
        });
    }
}
