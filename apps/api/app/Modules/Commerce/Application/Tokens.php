<?php

namespace App\Modules\Commerce\Application;

use App\Modules\Commerce\Domain\IntegrationFailure;
use App\Modules\Commerce\Domain\StoreProvider;
use App\Modules\Shipping\Application\ShippingAccess;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class Tokens
{
    public function validated(array $v): array
    {
        $d = Validator::make($v, ['store_id' => 'required|string|regex:/^[A-Za-z0-9_-]{1,128}$/', 'access_token' => 'required|string|max:16384', 'refresh_token' => 'required|string|max:16384', 'expires_at' => 'required|date|after:now', 'scopes' => 'required|array|min:1|max:50', 'scopes.*' => 'required|string|max:100|distinct', 'provenance' => 'required|in:verified,synthetic'])->validate();
        if ($d['provenance'] === 'synthetic') {
            abort_unless(app()->environment('testing') && in_array(DB::connection()->getDatabaseName(), ['clicktoship_test', 'clicktoship_e2e'], true), 403);
        }
        if (array_diff(app(StoreProvider::class)->requiredScopes(), $d['scopes'])) {
            throw new IntegrationFailure('SCOPES_CHANGED');
        }

        return $d;
    }

    public function store(string $merchant, string $connection, array $d): void
    {
        $old = DB::table('commerce_credentials')->where('connection_id', $connection)->lockForUpdate()->first();
        DB::table('commerce_credentials')->updateOrInsert(['connection_id' => $connection], ['merchant_id' => $merchant, 'ciphertext' => Crypt::encryptString(json_encode(['access_token' => $d['access_token'], 'refresh_token' => $d['refresh_token']])), 'version' => ($old->version ?? 0) + 1, 'expires_at' => $d['expires_at'], 'scopes' => json_encode($d['scopes']), 'refresh_nonce' => null, 'lease_until' => null]);
    }

    private function locked(object $origin, callable $fn): mixed
    {
        return DB::transaction(function () use ($origin, $fn) {
            DB::table('merchants')->where('id', $origin->merchant_id)->lockForUpdate()->firstOrFail();
            app(ShippingAccess::class)->worker($origin, 'integrations.sync');
            $c = app(Access::class)->connection($origin->merchant_id, $origin->connection_id);
            abort_unless($c->state === 'authorized' && $c->generation === $origin->generation, 403);
            $t = DB::table('commerce_credentials')->where('merchant_id', $c->merchant_id)->where('connection_id', $c->id)->lockForUpdate()->first();
            abort_unless($t && $t->ciphertext, 403);

            return $fn($c, $t);
        });
    }

    public function credential(object $origin): array
    {
        $lease = (string) Str::uuid();
        $current = $this->locked($origin, function ($c, $t) use ($lease) {
            $plain = json_decode(Crypt::decryptString($t->ciphertext), true);
            if (now()->addSeconds(30)->lt($t->expires_at)) {
                return ['ready' => $plain];
            }if ($t->refresh_nonce && $t->lease_until && now()->lt($t->lease_until)) {
                throw new IntegrationFailure('REFRESH_BUSY', true, 5);
            }if ($t->refresh_nonce) {
                DB::table('commerce_credentials')->where('connection_id', $c->id)->update(['ciphertext' => null, 'version' => DB::raw('version+1'), 'refresh_nonce' => null, 'lease_until' => null]);
                DB::table('commerce_connections')->where('id', $c->id)->update(['state' => 'reauthorization_required', 'version' => DB::raw('version+1'), 'error_code' => 'REFRESH_OUTCOME_UNKNOWN']);

                return ['unknown' => true];
            }DB::table('commerce_credentials')->where('connection_id', $c->id)->update(['refresh_nonce' => $lease, 'lease_until' => now()->addSeconds(30)]);

            return ['data' => $plain, 'version' => $t->version, 'scopes' => json_decode($t->scopes, true), 'store' => $c->external_store_id];
        });
        if (isset($current['ready'])) {
            return $current['ready'];
        }if (isset($current['unknown'])) {
            throw new IntegrationFailure('REFRESH_REAUTHORIZATION_REQUIRED');
        }
        try {
            $d = $this->validated(app(StoreProvider::class)->refresh($current['data']));
            $scopes = $d['scopes'];
            $expected = $current['scopes'];
            sort($scopes);
            sort($expected);
            if ($d['store_id'] !== $current['store'] || $scopes !== $expected) {
                throw new IntegrationFailure('SCOPES_OR_IDENTITY_CHANGED');
            }

            return $this->locked($origin, function ($c, $t) use ($lease, $current, $d, $origin) {
                abort_unless($t->refresh_nonce === $lease && $t->version === $current['version'], 409);
                $this->store($c->merchant_id, $c->id, $d);
                app(Access::class)->event($c->merchant_id, $c->id, 'credentials_rotated', $origin->actor_id ?? null);

                return ['access_token' => $d['access_token'], 'refresh_token' => $d['refresh_token']];
            });
        } catch (\Throwable $e) {
            DB::transaction(function () use ($origin, $lease) {
                DB::table('merchants')->where('id', $origin->merchant_id)->lockForUpdate()->first();
                $c = app(Access::class)->connection($origin->merchant_id, $origin->connection_id);
                $q = DB::table('commerce_credentials')->where('connection_id', $c->id)->where('refresh_nonce', $lease);
                if ($c->generation === $origin->generation && $q->exists()) {
                    $q->update(['ciphertext' => null, 'refresh_nonce' => null, 'lease_until' => null, 'version' => DB::raw('version+1')]);
                    DB::table('commerce_connections')->where('id', $c->id)->update(['state' => 'reauthorization_required', 'version' => DB::raw('version+1'), 'error_code' => 'REFRESH_REAUTHORIZATION_REQUIRED']);
                    app(Access::class)->event($c->merchant_id, $c->id, 'reauthorization_required');
                }
            });
            throw new IntegrationFailure('REFRESH_REAUTHORIZATION_REQUIRED');
        }
    }
}
