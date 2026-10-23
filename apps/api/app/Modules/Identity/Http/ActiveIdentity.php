<?php

namespace App\Modules\Identity\Http;

use App\Models\PersonalAccessToken;
use App\Modules\Identity\Application\Sessions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ActiveIdentity
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        abort_if($user->suspended_at, 403);
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            abort_unless($token->merchant_id && $token->auth_version === $user->auth_version, 401);
            abort_unless($user->hasVerifiedEmail(), 403);
            $eligible = DB::table('merchant_users as m')->join('merchants as o', 'o.id', '=', 'm.merchant_id')
                ->where('m.user_id', $user->id)->where('m.merchant_id', $token->merchant_id)
                ->where('m.status', 'active')->where('o.status', 'active')->exists();
            abort_unless($eligible, 403);
        } else {
            app(Sessions::class)->validate($request, $user);
        }

        return $next($request);
    }
}
