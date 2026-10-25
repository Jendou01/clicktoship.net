<?php

namespace App\Modules\Commerce\Application;

use App\Modules\Commerce\Domain\IntegrationFailure;
use App\Modules\Commerce\Domain\StoreProvider;
use App\Modules\Commerce\Jobs\ProcessStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Webhooks
{
    public function receive(Request $r): array
    {
        $provider = app(StoreProvider::class);
        abort_unless($provider->available(), 503);
        abort_if((int) $r->header('Content-Length', 0) > 65536, 413);
        $body = $r->getContent();
        abort_if(strlen($body) > 65536, 413);
        $v = $provider->webhook($body, $r->headers->all());
        if (! is_string($v['store_id'] ?? null) || ! is_string($v['receipt_id'] ?? null) || strlen($v['receipt_id']) > 128 || empty($v['authenticity_verified']) || empty($v['replay_checked'])) {
            throw new IntegrationFailure('WEBHOOK_AUTHENTICITY_UNVERIFIED');
        }

        return DB::transaction(function () use ($v, $body) {
            $root = DB::table('commerce_connections')->where('provider', 'salla')->where('external_store_id', $v['store_id'])->first();
            abort_unless($root, 404);
            DB::table('merchants')->where('id', $root->merchant_id)->lockForUpdate()->firstOrFail();
            $c = app(Access::class)->connection($root->merchant_id, $root->id);
            $hash = hash('sha256', $v['receipt_id']);
            $digest = hash('sha256', $body);
            $old = DB::table('commerce_receipts')->where('connection_id', $c->id)->where('event_hash', $hash)->first();
            if ($old) {
                abort_unless(hash_equals($old->payload_hash, $digest), 409);

                return ['accepted' => true, 'duplicate' => true];
            }
            abort_unless($c->state === 'authorized', 409);
            $kind = match ($v['kind'] ?? null) {
                'sync' => 'sync_requested','uninstall' => 'uninstalled',default => 'quarantined'
            };
            DB::table('commerce_receipts')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $c->merchant_id, 'connection_id' => $c->id, 'event_hash' => $hash, 'payload_hash' => $digest, 'kind' => $kind]);
            if ($kind === 'uninstalled') {
                app(Connections::class)->disable($c);
            } elseif ($kind === 'sync_requested' && ! DB::table('commerce_runs')->where('connection_id', $c->id)->where('kind', 'sync')->whereIn('state', ['queued', 'running', 'waiting', 'limited'])->exists()) {
                $id = (string) Str::uuid();
                DB::table('commerce_runs')->insert(['id' => $id, 'merchant_id' => $c->merchant_id, 'connection_id' => $c->id, 'generation' => $c->generation, 'kind' => 'sync', 'actor_id' => $c->actor_id, 'auth_version' => $c->auth_version, 'origin_session_id' => $c->origin_session_id, 'origin_token_id' => $c->origin_token_id, 'checkpoint' => $c->checkpoint]);
                DB::afterCommit(fn () => ProcessStore::dispatch($c->merchant_id, $id));
            }app(Access::class)->event($c->merchant_id, $c->id, 'webhook_'.$kind);

            return ['accepted' => true, 'duplicate' => false, 'quarantined' => $kind === 'quarantined'];
        });
    }
}
