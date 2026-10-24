<?php

namespace App\Modules\Shipping\Application;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Modules\Identity\Application\Sessions;
use App\Modules\Merchants\Domain\MerchantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ShippingAccess
{
    public function lock(Request $r, MerchantContext $c, string $permission): void
    {
        DB::table('merchants')->where('id', $c->id())->lockForUpdate()->firstOrFail();
        $user = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
        $token = $r->user()->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            app(Sessions::class)->validate($r, $user);
        }
        $c->establish($user, $c->id(), $token instanceof PersonalAccessToken ? $token : null);
        $c->require($permission);
    }

    public function worker(object $s, string $permission = 'shipments.submit'): void
    {
        $u = User::whereKey($s->actor_id)->lockForUpdate()->firstOrFail();
        abort_unless($u->auth_version === $s->auth_version, 403);
        $token = $s->origin_token_id ? PersonalAccessToken::find($s->origin_token_id) : null;
        abort_if($s->origin_token_id && ! $token, 403);
        if (! $token) {
            abort_unless(DB::table('auth_sessions')->where('id', $s->origin_session_id)->where('user_id', $u->id)->where('auth_version', $u->auth_version)->whereNull('revoked_at')->where('expires_at', '>', now())->where('last_seen_at', '>', now()->subMinutes(config('session.lifetime')))->lockForUpdate()->exists(), 403);
        }
        $c = app(MerchantContext::class);
        try {
            $c->establish($u, $s->merchant_id, $token);
            $c->require($permission);
        } finally {
            $c->clear();
        }
    }
}
