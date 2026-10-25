<?php

namespace App\Modules\Commerce\Application;

use App\Models\PersonalAccessToken;
use App\Modules\Identity\Application\Audit;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Shipping\Application\ShippingAccess;
use App\Modules\Shipping\Domain\ShipmentInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Access
{
    public function locked(Request $r, MerchantContext $c, string $permission, callable $fn): mixed
    {
        return DB::transaction(function () use ($r, $c, $permission, $fn) {
            $r->validate(['merchant_id' => 'prohibited', 'actor_id' => 'prohibited', 'origin_token_id' => 'prohibited', 'origin_session_id' => 'prohibited', 'external_store_id' => 'prohibited']);
            DB::statement("SET LOCAL statement_timeout = '5000ms'");
            app(ShippingAccess::class)->lock($r, $c, $permission);

            return $fn($c->id());
        });
    }

    public function connection(string $merchant, string $id): object
    {
        $r = DB::table('commerce_connections')->where('merchant_id', $merchant)->where('id', $id)->first();
        abort_unless($r, 404);

        return $r;
    }

    public function origin(Request $r, MerchantContext $c): array
    {
        $t = $r->user()->currentAccessToken();

        return ['actor_id' => $c->actorId(), 'auth_version' => $r->user()->fresh()->auth_version, 'origin_token_id' => $t instanceof PersonalAccessToken ? $t->id : null, 'origin_session_id' => $t instanceof PersonalAccessToken ? null : $r->session()->get('auth_session_id')];
    }

    public function event(string $merchant, string $connection, string $kind, ?string $actor = null, array $metadata = []): void
    {
        DB::table('commerce_events')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $merchant, 'connection_id' => $connection, 'kind' => $kind, 'actor_id' => $actor, 'metadata' => json_encode($metadata)]);
        Audit::record('commerce.'.$kind, $actor, $merchant, $connection, $metadata);
    }

    public function command(Request $r, string $merchant, string $actor, string $operation, array $payload, callable $fn): array
    {
        $key = $r->validate(['idempotency_key' => 'required|string|min:8|max:128'])['idempotency_key'];
        $digest = ShipmentInput::digest($payload);
        $q = DB::table('commerce_commands')->where('merchant_id', $merchant)->where('actor_id', $actor)->where('operation', $operation)->where('key_hash', hash('sha256', $key));
        if ($old = $q->first()) {
            abort_unless(hash_equals($old->digest, $digest), 409);

            return json_decode($old->result, true);
        }
        $result = $fn();
        DB::table('commerce_commands')->insert(['merchant_id' => $merchant, 'actor_id' => $actor, 'operation' => $operation, 'key_hash' => hash('sha256', $key), 'digest' => $digest, 'result' => json_encode($result)]);

        return $result;
    }

    public function active(object $connection): void
    {
        abort_unless($connection->state === 'authorized', 409);
    }
}
