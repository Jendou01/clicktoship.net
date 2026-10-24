<?php

namespace App\Modules\Customs\Application;

use App\Models\PersonalAccessToken;
use App\Modules\Customs\Domain\CustomsInput;
use App\Modules\Identity\Application\Audit;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Shipping\Application\Shipments;
use App\Modules\Shipping\Application\ShippingAccess;
use App\Modules\Shipping\Domain\ShipmentInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Declarations
{
    public function origin(Request $r): array
    {
        $t = $r->user()->currentAccessToken();

        return ['actor_id' => $r->user()->id, 'auth_version' => $r->user()->auth_version, 'origin_token_id' => $t instanceof PersonalAccessToken ? $t->id : null, 'origin_session_id' => $t instanceof PersonalAccessToken ? null : $r->session()->get('auth_session_id')];
    }

    public function event(string $merchant, string $shipment, string $kind, ?string $actor = null): void
    {
        DB::table('customs_events')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $merchant, 'shipment_id' => $shipment, 'actor_id' => $actor, 'kind' => $kind]);
        Audit::record('customs.'.$kind, $actor, $merchant, $shipment);
    }

    public function command(Request $r, MerchantContext $c, string $permission, string $op, array $payload, callable $write): array
    {
        $key = $r->validate(['idempotency_key' => 'required|string|min:8|max:128'])['idempotency_key'];

        return DB::transaction(function () use ($r, $c, $permission, $op, $payload, $write, $key) {
            app(ShippingAccess::class)->lock($r, $c, $permission);
            $q = DB::table('customs_commands')->where('merchant_id', $c->id())->where('actor_id', $r->user()->id)->where('operation', $op)->where('key_hash', hash('sha256', $key));
            $hash = ShipmentInput::digest($payload);
            if ($old = $q->first()) {
                abort_unless(hash_equals($old->digest, $hash), 409);

                return json_decode(Crypt::decryptString($old->result_ciphertext), true);
            }$result = $write();
            DB::table('customs_commands')->insert(['merchant_id' => $c->id(), 'actor_id' => $r->user()->id, 'operation' => $op, 'key_hash' => hash('sha256', $key), 'digest' => $hash, 'result_ciphertext' => Crypt::encryptString(json_encode($result))]);

            return $result;
        });
    }

    public function save(Request $r, MerchantContext $c, string $shipment): array
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'declaration_version' => 'required|integer|min:0', 'declaration' => 'required|array', 'merchant_id' => 'prohibited', 'approved' => 'prohibited']);

        return $this->command($r, $c, 'customs.write', 'declaration:'.$shipment, $d, function () use ($r, $c, $shipment, $d) {
            $c->require('shipments.write');
            $s = app(Shipments::class)->row($c->id(), $shipment);
            $old = DB::table('customs_declarations')->where('merchant_id', $c->id())->where('shipment_id', $shipment)->first();
            abort_unless(($old->version ?? 0) === $d['declaration_version'], 409);
            $draft = app(Shipments::class)->revision($s);
            $customs = CustomsInput::normalize($d['declaration'], $draft, false);
            $draft['customs'] = $customs;
            $draft['declared_value_minor'] = $customs['declared_total_minor'];
            $draft['items'] = CustomsInput::legacy($customs);
            $result = app(Shipments::class)->customsDraft($c->id(), $r->user()->id, $draft, $shipment, $d['version']);
            $s = $result['shipment'];
            $version = ($old->version ?? 0) + 1;
            $id = $old?->id ?? (string) Str::uuid();
            $this->snapshot($c->id(), $id, $shipment, $s->revision, $version, $customs, $r->user()->id, (bool) $old);
            $this->event($c->id(), $shipment, 'declaration_saved', $r->user()->id);

            return $this->show($c->id(), $shipment);
        });
    }

    public function snapshot(string $merchant, string $id, string $shipment, int $revision, int $version, array $customs, string $actor, bool $exists = false): void
    {
        if ($exists) {
            DB::table('customs_declarations')->where('id', $id)->update(['version' => $version]);
        } else {
            DB::table('customs_declarations')->insert(['id' => $id, 'merchant_id' => $merchant, 'shipment_id' => $shipment, 'version' => $version]);
        }
        DB::table('customs_versions')->insert(['merchant_id' => $merchant, 'declaration_id' => $id, 'version' => $version, 'shipment_id' => $shipment, 'revision' => $revision, 'payload_ciphertext' => Crypt::encryptString(json_encode($customs)), 'input_hash' => ShipmentInput::digest($customs), 'declared_minor' => $customs['declared_total_minor'], 'currency' => $customs['currency'], 'exponent' => $customs['exponent'], 'actor_id' => $actor]);
        foreach ($customs['items'] as $i => $item) {
            DB::table('customs_items')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $merchant, 'declaration_id' => $id, 'version' => $version, 'position' => $i + 1, 'quantity' => $item['quantity'], 'unit_minor' => $item['unit_value_minor'], 'total_minor' => $item['total_value_minor'], 'currency' => $customs['currency'], 'exponent' => $customs['exponent'], 'payload_ciphertext' => Crypt::encryptString(json_encode($item))]);
        }
    }

    public function sync(Request $r, MerchantContext $c, string $shipment): array
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);

        return $this->command($r, $c, 'customs.write', 'sync:'.$shipment, $d, function () use ($r, $c, $shipment, $d) {
            $s = app(Shipments::class)->row($c->id(), $shipment);
            abort_unless($s->version === $d['version'], 409);
            $draft = app(Shipments::class)->revision($s);
            abort_unless(isset($draft['customs']), 422);
            $old = DB::table('customs_declarations')->where('merchant_id', $c->id())->where('shipment_id', $shipment)->first();
            if ($old && DB::table('customs_versions')->where('merchant_id', $c->id())->where('declaration_id', $old->id)->where('version', $old->version)->where('revision', $s->revision)->exists()) {
                return $this->show($c->id(), $shipment);
            }$this->snapshot($c->id(), $old?->id ?? (string) Str::uuid(), $shipment, $s->revision, ($old->version ?? 0) + 1, $draft['customs'], $r->user()->id, (bool) $old);
            $this->event($c->id(), $shipment, 'declaration_bound', $r->user()->id);

            return $this->show($c->id(), $shipment);
        });
    }

    public function show(string $merchant, string $shipment, ?int $requestedVersion = null): array
    {
        $s = app(Shipments::class)->row($merchant, $shipment);
        $draft = app(Shipments::class)->revision($s);
        $d = DB::table('customs_declarations')->where('merchant_id', $merchant)->where('shipment_id', $shipment)->first();
        $historical = null;
        if ($d) {
            $prior = DB::table('customs_versions')->where('merchant_id', $merchant)->where('declaration_id', $d->id)->where('version', $requestedVersion ?? $d->version)->first();
            abort_unless($prior, 404);
            $historical = json_decode(Crypt::decryptString($prior->payload_ciphertext), true);
        }
        $versions = $d ? DB::table('customs_versions')->where('merchant_id', $merchant)->where('declaration_id', $d->id)->orderByDesc('version')->limit(25)->get(['version', 'revision', 'declared_minor', 'currency', 'exponent', 'created_at']) : [];
        $docs = DB::table('customs_documents as d')->leftJoin('customs_document_results as r', 'r.document_id', '=', 'd.id')->leftJoin('customs_document_work as w', 'w.document_id', '=', 'd.id')->leftJoin('customs_document_revocations as x', 'x.document_id', '=', 'd.id')->where('d.merchant_id', $merchant)->where('d.shipment_id', $shipment)->orderByDesc('d.created_at')->limit(50)->get(['d.id', 'd.kind', 'd.version', 'd.revision', 'd.number', 'd.locale', 'd.created_at', 'r.state', 'w.state as work_state', 'w.error_code', 'x.created_at as revoked_at']);

        return ['shipment' => $s, 'draft' => $draft, 'declaration' => $d, 'declaration_version' => $d->version ?? 0, 'customs' => $draft['customs'] ?? null, 'historical_declaration' => $historical, 'versions' => $versions, 'documents' => $docs, 'readiness' => app(Readiness::class)->report($draft), 'live_enabled' => false, 'documents_download_enabled' => app()->environment('testing')];
    }
}
