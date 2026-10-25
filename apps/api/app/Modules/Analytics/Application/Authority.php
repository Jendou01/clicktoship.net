<?php

namespace App\Modules\Analytics\Application;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Operations\Application\PlatformSecurity;
use App\Modules\Shipping\Application\ShippingAccess;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;

final class Authority
{
    public function merchant(Request $r, callable $fn): mixed
    {
        return DB::transaction(function () use ($r, $fn) {
            DB::statement("SET LOCAL statement_timeout='5000ms'");
            $c = app(MerchantContext::class);
            app(ShippingAccess::class)->lock($r, $c, 'analytics.read');
            $token = $r->user()->currentAccessToken();
            if ($token instanceof PersonalAccessToken) {
                $fresh = PersonalAccessToken::whereKey($token->id)->lockForUpdate()->first();
                abort_unless($fresh, 403);
                $c->establish($r->user()->fresh(), $c->id(), $fresh);
                $c->require('analytics.read');
            }

            return $fn($c->id(), $c->permissions());
        });
    }

    public function require(array $p, string $report, string $scope): void
    {
        $needed = $scope === 'merchant' ? Definitions::get($report)[0] : 'platform.metrics.'.$report;
        abort_unless(in_array($needed, $p, true), 403);
    }

    public function origin(Request $r): array
    {
        $t = $r->user()->currentAccessToken();

        return ['actor_id' => $r->user()->id, 'auth_version' => $r->user()->fresh()->auth_version, 'origin_token_id' => $t instanceof PersonalAccessToken ? $t->id : null, 'origin_session_id' => $t instanceof PersonalAccessToken ? null : $r->session()->get('auth_session_id')];
    }

    public function worker(object $e, callable $fn, bool $snapshot = false): mixed
    {
        return DB::transaction(function () use ($e, $fn, $snapshot) {
            if ($snapshot && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            DB::statement("SET LOCAL statement_timeout='5000ms'");
            if ($e->scope === 'merchant') {
                DB::table('merchants')->where('id', $e->merchant_id)->lockForUpdate()->firstOrFail();
                app(ShippingAccess::class)->worker($e, 'analytics.read');
                app(ShippingAccess::class)->worker($e, 'analytics.export');
                app(ShippingAccess::class)->worker($e, Definitions::get($e->report)[0]);
            } else {
                DB::select('SELECT pg_advisory_xact_lock_shared(3042026)');
            }
            $u = User::whereKey($e->actor_id)->lockForUpdate()->firstOrFail();
            abort_unless(! $u->suspended_at && $u->hasVerifiedEmail() && $u->auth_version === $e->auth_version, 403);
            $p = [];
            if ($e->scope === 'platform') {
                $session = DB::table('auth_sessions')->where('id', $e->origin_session_id)->where('user_id', $u->id)->where('auth_version', $u->auth_version)->whereNull('revoked_at')->where('expires_at', '>', now())->where('last_seen_at', '>', now()->subMinutes(config('session.lifetime')))->lockForUpdate()->first();
                abort_unless($session, 403);
                $p = PlatformSecurity::permissions($u->id);
                foreach (['platform.analytics.read', 'platform.analytics.export', 'platform.overview', 'platform.metrics.'.$e->report] as $permission) {
                    abort_unless(in_array($permission, $p, true), 403);
                }
            }
            if ($e->scope === 'platform' || Definitions::financial($e->report)) {
                abort_unless($e->origin_session_id, 403);
                $request = Request::create('/');
                $request->setUserResolver(fn () => $u);
                $store = new Store('report-worker', new ArraySessionHandler(120));
                $store->put('auth_session_id', $e->origin_session_id);
                $request->setLaravelSession($store);
                PlatformSecurity::fresh($request);
            }

            return $fn($p);
        });
    }
}
