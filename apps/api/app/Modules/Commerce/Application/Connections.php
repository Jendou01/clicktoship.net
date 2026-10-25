<?php

namespace App\Modules\Commerce\Application;

use App\Models\PersonalAccessToken;
use App\Modules\Commerce\Domain\StoreProvider;
use App\Modules\Merchants\Domain\MerchantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Connections
{
    public function capabilities(): array
    {
        return ['provider' => 'salla', 'available' => false, 'official_contract_verified' => false, 'oauth_mode' => null, 'required_scopes' => [], 'reason' => 'SALLA_CONTRACT_UNVERIFIED', 'external_webhooks_enabled' => false, 'carrier_execution_enabled' => false, 'payments_enabled' => false];
    }

    public function list(string $merchant): array
    {
        $rows = DB::table('commerce_connections as c')->leftJoin('commerce_credentials as t', fn ($j) => $j->on('t.connection_id', '=', 'c.id')->on('t.merchant_id', '=', 'c.merchant_id'))->where('c.merchant_id', $merchant)->orderByDesc('c.created_at')->limit(100)->get(['c.id', 'c.provider', 'c.external_store_id', 'c.state', 'c.provenance', 'c.version', 'c.last_synced_at', 'c.error_code', 'c.created_at', 't.expires_at', 't.version as credential_version']);

        return ['connections' => $rows, 'capabilities' => $this->capabilities()];
    }

    private function redirect(): string
    {
        $url = config('commerce.redirect_uri');
        abort_unless(is_string($url) && in_array($url, config('commerce.approved_redirect_uris'), true), 503);
        $p = parse_url($url);
        abort_unless(($p['scheme'] ?? '') === 'https' && ! isset($p['user']) && ! isset($p['pass']) && ! isset($p['fragment']) && ! isset($p['query']) && ! filter_var($p['host'] ?? '', FILTER_VALIDATE_IP), 503);

        return $url;
    }

    public function begin(Request $r, MerchantContext $c): array
    {
        return app(Access::class)->locked($r, $c, 'integrations.manage', function ($m) use ($r, $c) {
            abort_unless($r->hasSession() && ! $r->user()->currentAccessToken() instanceof PersonalAccessToken, 403);
            $provider = app(StoreProvider::class);
            abort_unless($provider->available(), 503);
            $redirect = $this->redirect();
            $state = bin2hex(random_bytes(32));
            $url = $provider->authorizationUrl($state, $redirect);
            $host = parse_url($url);
            abort_unless(($host['scheme'] ?? '') === 'https' && ! isset($host['user']) && ! isset($host['pass']) && in_array($host['host'] ?? '', config('commerce.verified_authorization_hosts'), true), 503);
            DB::table('commerce_authorizations')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $m, 'actor_id' => $c->actorId(), 'session_id' => $r->session()->get('auth_session_id'), 'auth_version' => $r->user()->fresh()->auth_version, 'state_hash' => hash('sha256', $state), 'redirect_uri' => $redirect, 'expires_at' => now()->addMinutes(10)]);

            return ['authorization_url' => $url, 'expires_in' => 600];
        });
    }

    public function complete(Request $r, MerchantContext $c): array
    {
        $d = $r->validate(['state' => 'required|string|size:64', 'code' => 'required|string|max:2048', 'merchant_id' => 'prohibited']);
        abort_unless(app(StoreProvider::class)->available(), 503);
        $intent = app(Access::class)->locked($r, $c, 'integrations.manage', function ($m) use ($r, $d) {
            abort_unless($r->hasSession(), 403);
            $i = DB::table('commerce_authorizations')->where('state_hash', hash('sha256', $d['state']))->where('merchant_id', $m)->where('actor_id', $r->user()->id)->where('session_id', $r->session()->get('auth_session_id'))->whereNull('used_at')->whereNull('cancelled_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            abort_unless($i && $i->auth_version === $r->user()->fresh()->auth_version && $i->redirect_uri === $this->redirect(), 403);
            DB::table('commerce_authorizations')->where('id', $i->id)->update(['used_at' => now()]);

            return $i;
        });
        $data = app(Tokens::class)->validated(app(StoreProvider::class)->exchange($d['code'], $intent->redirect_uri));

        return DB::transaction(function () use ($r, $c, $data, $intent) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['commerce:salla:'.$data['store_id']]);

            return app(Access::class)->locked($r, $c, 'integrations.manage', function ($m) use ($r, $c, $data, $intent) {
                abort_unless(DB::table('commerce_authorizations')->where('id', $intent->id)->whereNull('cancelled_at')->whereNotNull('used_at')->where('expires_at', '>', now())->exists(), 403);
                $old = DB::table('commerce_connections')->where('provider', 'salla')->where('external_store_id', $data['store_id'])->lockForUpdate()->first();
                abort_if($old && $old->merchant_id !== $m, 409);
                $id = $old->id ?? (string) Str::uuid();
                $values = ['state' => 'authorized', 'provenance' => $data['provenance'], 'generation' => ($old->generation ?? 0) + 1, 'version' => ($old->version ?? 0) + 1, 'error_code' => null, 'updated_at' => now()] + app(Access::class)->origin($r, $c);
                if ($old) {
                    DB::table('commerce_connections')->where('id', $id)->update($values);
                    DB::table('commerce_runs')->where('connection_id', $id)->whereIn('state', ['queued', 'running', 'waiting', 'limited'])->update(['state' => 'blocked', 'failure_code' => 'AUTHORIZATION_REPLACED', 'lease_token' => null, 'lease_until' => null]);
                } else {
                    DB::table('commerce_connections')->insert(['id' => $id, 'merchant_id' => $m, 'provider' => 'salla', 'external_store_id' => $data['store_id']] + $values);
                }
                app(Tokens::class)->store($m, $id, $data);
                app(Access::class)->event($m, $id, 'authorization_verified', $c->actorId(), ['provenance' => $data['provenance']]);

                return ['connection_id' => $id, 'provenance' => $data['provenance'], 'external_execution_enabled' => false];
            });
        });
    }

    public function disconnect(Request $r, MerchantContext $c, string $id): array
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);

        return app(Access::class)->locked($r, $c, 'integrations.manage', function ($m) use ($r, $c, $id, $d) {
            return app(Access::class)->command($r, $m, $c->actorId(), 'disconnect:'.$id, $d, function () use ($m, $id, $d, $c) {
                $s = app(Access::class)->connection($m, $id);
                abort_unless($s->version === $d['version'], 409);
                $this->disable($s, $c->actorId());

                return ['disconnected' => true, 'external_revocation_verified' => false];
            });
        });
    }

    public function disable(object $s, ?string $actor = null): void
    {
        DB::table('commerce_authorizations')->where('merchant_id', $s->merchant_id)->whereNull('cancelled_at')->where('expires_at', '>', now())->update(['cancelled_at' => now()]);
        DB::table('commerce_connections')->where('id', $s->id)->update(['state' => 'disconnected', 'generation' => $s->generation + 1, 'version' => $s->version + 1, 'updated_at' => now()]);
        DB::table('commerce_credentials')->where('connection_id', $s->id)->update(['ciphertext' => null, 'version' => DB::raw('version+1'), 'refresh_nonce' => null, 'lease_until' => null]);
        DB::table('commerce_runs')->where('connection_id', $s->id)->whereIn('state', ['queued', 'running', 'waiting', 'limited'])->update(['state' => 'blocked', 'failure_code' => 'DISCONNECTED', 'lease_token' => null, 'lease_until' => null]);
        app(Access::class)->event($s->merchant_id, $s->id, 'disconnected', $actor);
    }
}
