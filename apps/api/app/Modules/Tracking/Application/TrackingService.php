<?php

namespace App\Modules\Tracking\Application;

use App\Models\PersonalAccessToken;
use App\Modules\Identity\Application\Audit;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Shipping\Application\Shipments;
use App\Modules\Shipping\Application\ShippingAccess;
use App\Modules\Shipping\Domain\ShipmentInput;
use App\Modules\Tracking\Jobs\ProcessTracking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TrackingService
{
    private function origin(Request $r): array
    {
        $t = $r->user()->currentAccessToken();

        return ['actor_id' => $r->user()->id, 'auth_version' => $r->user()->auth_version, 'origin_token_id' => $t instanceof PersonalAccessToken ? $t->id : null, 'origin_session_id' => $t instanceof PersonalAccessToken ? null : $r->session()->get('auth_session_id')];
    }

    public static function referenceHash(string $ref): string
    {
        return hash_hmac('sha256', $ref, config('app.key'));
    }

    public function bind(string $merchant, string $observation): ?string
    {
        return DB::transaction(function () use ($merchant, $observation) {
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
            $o = DB::table('shipping_observations as o')->join('shipment_submissions as s', fn ($j) => $j->on('s.id', '=', 'o.submission_id')->on('s.merchant_id', '=', 'o.merchant_id'))->join('shipments as shipment', fn ($j) => $j->on('shipment.id', '=', 's.shipment_id')->on('shipment.merchant_id', '=', 's.merchant_id')->on('shipment.carrier_observation_id', '=', 'o.id'))->where('shipment.state', 'accepted')->join('carrier_operations as c', 'c.id', '=', 's.carrier_operation_id')->where('o.merchant_id', $merchant)->where('o.id', $observation)->where('o.outcome', 'accepted')->first(['o.*', 's.shipment_id', 's.carrier_operation_id', 'c.account_id']);
            if (! $o || ! $o->carrier_reference) {
                return null;
            }
            if ($b = DB::table('tracking_bindings')->where('merchant_id', $merchant)->where('shipment_id', $o->shipment_id)->first()) {
                return $b->id;
            }
            // Collisions cannot rebind or reveal another merchant's shipment.
            $hash = self::referenceHash($o->carrier_reference);
            if (DB::table('tracking_bindings')->where('merchant_id', $merchant)->where('account_id', $o->account_id)->where('reference_hash', $hash)->exists()) {
                if (! DB::table('audit_logs')->where('merchant_id', $merchant)->where('subject_id', $o->shipment_id)->where('action', 'tracking.reference_collision')->exists()) {
                    Audit::record('tracking.reference_collision', null, $merchant, $o->shipment_id);
                }

                return null;
            }
            $id = (string) Str::uuid();
            DB::table('tracking_bindings')->insert(['id' => $id, 'merchant_id' => $merchant, 'shipment_id' => $o->shipment_id, 'operation_id' => $o->carrier_operation_id, 'account_id' => $o->account_id, 'observation_id' => $observation, 'reference_hash' => $hash, 'reference_ciphertext' => Crypt::encryptString($o->carrier_reference), 'provenance' => $o->provenance]);

            return $id;
        });
    }

    public function binding(string $merchant, string $shipment): ?object
    {
        return DB::table('tracking_bindings')->where('merchant_id', $merchant)->where('shipment_id', $shipment)->first();
    }

    public function detail(string $merchant, string $shipment, int $page = 1): array
    {
        $s = app(Shipments::class)->row($merchant, $shipment);
        $b = $this->binding($merchant, $shipment);
        $visible = $b && $b->provenance === 'verified';
        $projection = $visible ? DB::table('carrier_tracking_projections')->where('account_id', $b->account_id)->where('merchant_id', $merchant)->where('operation_id', $b->operation_id)->first(['status', 'occurred_at']) : null;
        $events = DB::table('carrier_tracking_events as e')->join('tracking_event_details as d', 'd.event_id', '=', 'e.id')->where('d.merchant_id', $merchant)->where('d.binding_id', $visible ? $b->id : (string) Str::uuid())->whereIn('d.validation', ['verified', 'unknown_code'])->orderByDesc('e.occurred_at')->orderByDesc('e.received_at')->orderByDesc('e.id')->paginate(50, ['e.id', 'e.normalized_status', 'e.occurred_at', 'e.received_at', 'd.source', 'd.validation', 'd.outcome', 'd.provenance', 'd.correlation_id'], 'page', $page);
        $a = $b ? DB::table('carrier_accounts')->where('id', $b->account_id)->first(['carrier']) : null;
        $enabled = $b && app(TrackingProviderRegistry::class)->allowed($b->account_id, 'poll') && $visible;

        return ['shipment_id' => $shipment, 'shipping_state' => $s->state, 'status' => $projection?->status ?? ($s->state === 'accepted' ? 'carrier_accepted' : (in_array($s->state, ['draft', 'ready', 'cancelled', 'blocked', 'queued']) ? 'not_submitted' : 'unknown')), 'carrier' => $a?->carrier, 'last_confirmed_update' => $projection?->occurred_at, 'stale' => $projection ? now()->subHours(24)->gt($projection->occurred_at) : false, 'verified_data' => (bool) $projection, 'reconciliation_pending' => $visible && DB::table('tracking_cases')->where('merchant_id', $merchant)->where('binding_id', $b->id)->exists(), 'tracking_enabled' => (bool) $enabled, 'disabled_reason' => 'CONTRACT_UNVERIFIED', 'events' => $events->items(), 'page' => $events->currentPage(), 'last_page' => $events->lastPage(), 'refreshes' => DB::table('tracking_work')->where('merchant_id', $merchant)->where('binding_id', $b?->id)->orderByDesc('created_at')->limit(10)->get(['id', 'kind', 'state', 'error_code', 'created_at'])];
    }

    public function refresh(Request $r, MerchantContext $c): array
    {
        $d = $r->validate(['shipment_ids' => 'required|array|min:1|max:50', 'shipment_ids.*' => 'required|uuid|distinct', 'idempotency_key' => 'required|string|min:8|max:128', 'merchant_id' => 'prohibited', 'status' => 'prohibited']);
        sort($d['shipment_ids']);

        return DB::transaction(function () use ($r, $c, $d) {
            app(ShippingAccess::class)->lock($r, $c, 'tracking.refresh');
            $digest = ShipmentInput::digest($d['shipment_ids']);
            $key = hash('sha256', $d['idempotency_key']);
            $old = DB::table('tracking_commands')->where('merchant_id', $c->id())->where('actor_id', $r->user()->id)->where('key_hash', $key)->first();
            if ($old) {
                abort_unless(hash_equals($old->digest, $digest), 409);

                return json_decode($old->result, true);
            }
            $results = [];
            foreach ($d['shipment_ids'] as $id) {
                app(Shipments::class)->row($c->id(), $id);
                $b = $this->binding($c->id(), $id);
                if (! $b || ! app(TrackingProviderRegistry::class)->allowed($b->account_id, 'poll')) {
                    $results[] = ['shipment_id' => $id, 'state' => 'disabled', 'reason' => 'CONTRACT_UNVERIFIED'];

                    continue;
                }
                $active = DB::table('tracking_work')->where('merchant_id', $c->id())->where('binding_id', $b->id)->whereIn('kind', ['poll', 'scheduled'])->whereIn('state', ['pending', 'processing'])->first();
                if ($active) {
                    $results[] = ['shipment_id' => $id, 'state' => $active->state, 'work_id' => $active->id];

                    continue;
                }
                $work = (string) Str::uuid();
                DB::table('tracking_work')->insert(['id' => $work, 'merchant_id' => $c->id(), 'binding_id' => $b->id, 'account_id' => $b->account_id, 'kind' => 'poll', 'state' => 'pending', 'business_key' => hash('sha256', $work), 'digest' => $digest] + $this->origin($r));
                DB::afterCommit(fn () => ProcessTracking::dispatch($c->id(), $work));
                $results[] = ['shipment_id' => $id, 'state' => 'pending', 'work_id' => $work];
            }
            DB::table('tracking_commands')->insert(['merchant_id' => $c->id(), 'actor_id' => $r->user()->id, 'key_hash' => $key, 'digest' => $digest, 'result' => json_encode($results)]);

            return $results;
        });
    }
}
