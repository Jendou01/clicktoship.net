<?php

namespace App\Modules\Pricing\Application;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Modules\Identity\Application\Sessions;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Pricing\Domain\Calculator;
use App\Modules\Pricing\Domain\CarrierRates;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class Quotes
{
    public function input(Request $r): array
    {
        $d = $r->validate(['service_id' => 'required|uuid', 'currency' => 'required|in:SAR', 'origin' => 'required|array:country,city', 'destination' => 'required|array:country,city', 'origin.country' => 'required|regex:/^[A-Z]{2}$/', 'destination.country' => 'required|regex:/^[A-Z]{2}$/', 'origin.city' => 'required|string|max:100', 'destination.city' => 'required|string|max:100', 'packages' => 'required|array|min:1|max:100', 'packages.*' => 'required|array:weight_grams,length_mm,width_mm,height_mm', 'packages.*.weight_grams' => 'required|integer|min:1|max:1000000000', 'packages.*.length_mm' => 'required|integer|min:1|max:200000', 'packages.*.width_mm' => 'required|integer|min:1|max:200000', 'packages.*.height_mm' => 'required|integer|min:1|max:200000', 'cod_minor' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,11})$/'], 'declared_value_minor' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,11})$/'], 'idempotency_key' => 'required|string|min:8|max:128', 'merchant_id' => 'prohibited', 'price' => 'prohibited']);
        if (array_diff(array_keys($r->all()), array_keys($d))) {
            throw ValidationException::withMessages(['input' => 'Unsupported quote fields.']);
        }
        foreach (['origin', 'destination'] as $side) {
            $d[$side]['city'] = mb_strtolower(trim($d[$side]['city']));
            abort_if($d[$side]['city'] === '', 422);
        }
        // HTTP JSON numeric strings normalize to integers before hashing/calculation.
        foreach ($d['packages'] as &$p) {
            foreach ($p as &$v) {
                $v = (int) $v;
            }
        }

        return $d;
    }

    public function create(Request $r, MerchantContext $context, ?string $parent = null): array
    {
        $context->require('rates.quote');
        $d = $this->input($r);
        $key = $d['idempotency_key'];
        unset($d['idempotency_key']);
        $canonical = function ($value) use (&$canonical) {
            if (! is_array($value)) {
                return $value;
            }if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($canonical, $value);
        };
        $digest = hash('sha256', json_encode($canonical(['input' => $d, 'parent' => $parent]), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($r, $context, $parent, $d, $key, $digest) {
            Catalog::lock(true);
            $merchant = $context->id();
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->first();
            $user = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $token = $r->user()->currentAccessToken();
            if (! $token instanceof PersonalAccessToken) {
                app(Sessions::class)->validate($r, $user);
            }
            $context->establish($user, $merchant, $token instanceof PersonalAccessToken ? $token : null);
            $context->require('rates.quote');
            $old = DB::table('pricing_quote_requests')->where('merchant_id', $merchant)->where('actor_id', $user->id)->where('key_hash', hash('sha256', $key))->first();
            if ($old) {
                abort_unless(hash_equals($old->digest, $digest), 409);

                return $this->show($merchant, $old->quote_id);
            }
            $previous = $parent ? $this->row($merchant, $parent) : null;
            abort_unless(DB::table('financial_currencies')->where('code', $d['currency'])->where('enabled', true)->exists(), 422);
            $isCarrierCost = fn ($v) => $v['kind'] === 'tariff' || ($v['kind'] === 'fee' && $v['config']['side'] === 'carrier');
            $local = array_values(array_filter(app(Catalog::class)->active(), fn ($v) => ! $isCarrierCost($v)));
            $costs = app(CarrierRates::class)->configurations();
            // Carrier adapters can supply costs, never merchant rules, tax policy or service capabilities.
            abort_if(count(array_filter($costs, $isCarrierCost)) !== count($costs), 503);
            try {
                $result = app(Calculator::class)->calculate($d, $merchant, array_merge($local, $costs));
            } catch (DomainException) {
                abort(422);
            }
            $id = (string) Str::uuid();
            $revision = ($previous->revision ?? 0) + 1;
            $public = $result['public'] + ['inputs' => $d, 'id' => $id, 'revision' => $revision, 'parent_id' => $parent, 'created_at' => now()->toIso8601String(), 'pricing_version' => hash_hmac('sha256', json_encode($result['private']['selected_versions']), config('app.key'))];
            DB::table('shipping_quotes')->insert(['id' => $id, 'merchant_id' => $merchant, 'actor_id' => $user->id, 'parent_id' => $parent, 'revision' => $revision, 'currency' => $d['currency'], 'public_snapshot' => json_encode($public), 'private_snapshot' => Crypt::encryptString(json_encode($result['private'])), 'expires_at' => $public['expires_at'], 'created_at' => now()]);
            DB::table('pricing_quote_requests')->insert(['merchant_id' => $merchant, 'actor_id' => $user->id, 'key_hash' => hash('sha256', $key), 'digest' => $digest, 'quote_id' => $id]);

            return $this->show($merchant, $id);
        });
    }

    public function row(string $merchant, string $id): object
    {
        $row = DB::table('shipping_quotes')->where('merchant_id', $merchant)->where('id', $id)->first();
        abort_unless($row, 404);

        return $row;
    }

    public function show(string $merchant, string $id): array
    {
        return json_decode($this->row($merchant, $id)->public_snapshot, true);
    }

    public function valid(string $merchant, string $id): array
    {
        $row = $this->row($merchant, $id);
        $public = json_decode($row->public_snapshot, true);
        $service = DB::table('pricing_definitions')->where('id', $public['service_id'])->where('enabled', true)->exists();

        return ['id' => $id, 'valid' => now()->lt($row->expires_at) && $service, 'shipping_enabled' => false];
    }
}
