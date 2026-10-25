<?php

namespace App\Modules\Operations\Application\Overview;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Modules\Identity\Application\Sessions;
use App\Modules\Operations\Application\PlatformSecurity;
use App\Support\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ReadAuthority
{
    public function respond(Request $r, string $permission, callable $read, bool $exclusive = false): mixed
    {
        abort_if($r->user()->currentAccessToken() instanceof PersonalAccessToken, 403);
        try {
            $data = DB::transaction(function () use ($r, $permission, $read, $exclusive) {
                DB::statement("SET LOCAL statement_timeout = '5000ms'");
                // Shared readers coexist; existing security changes take the exclusive key.
                DB::select($exclusive ? 'SELECT pg_advisory_xact_lock(3042026)' : 'SELECT pg_advisory_xact_lock_shared(3042026)');
                $u = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
                abort_if($u->suspended_at || ! $u->hasVerifiedEmail(), 403);
                DB::table('auth_sessions')->where('id', $r->session()->get('auth_session_id'))->where('user_id', $u->id)->lockForUpdate()->firstOrFail();
                app(Sessions::class)->validate($r, $u);
                PlatformSecurity::require($r, $permission);
                $permissions = PlatformSecurity::permissions($u->id);

                return $read($permissions);
            });

        } catch (QueryException) {
            abort(503);
        }

        if ($data instanceof Response) {
            return $data;
        }

        return ApiResponse::success($data)->header('Cache-Control', 'private, no-store')->header('Vary', 'Cookie, Authorization');
    }
}
