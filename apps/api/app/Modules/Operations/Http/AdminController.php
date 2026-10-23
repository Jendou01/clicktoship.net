<?php

namespace App\Modules\Operations\Http;

use App\Modules\Identity\Application\Audit;
use App\Modules\Operations\Application\Administration;
use App\Modules\Operations\Application\PlatformSecurity;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AdminController
{
    public function grants(Request $r, Administration $s): mixed
    {
        return ApiResponse::success($r->isMethod('post') ? $s->requestGrant($r) : $s->grants($r), $r->isMethod('post') ? 201 : 200);
    }

    public function approve(Request $r, Administration $s, string $grant): mixed
    {
        return ApiResponse::success($s->approve($r, $grant));
    }

    public function revoke(Request $r, Administration $s, string $grant): mixed
    {
        return ApiResponse::success($s->revoke($r, $grant));
    }

    public function directory(Request $r): mixed
    {
        PlatformSecurity::require($r, 'platform.directory');
        $r->validate(['page' => 'sometimes|integer|min:1']);
        $p = DB::table('merchants')->orderBy('id')->paginate(25, ['id', 'name', 'status', 'verification_status', 'version']);

        return ApiResponse::success(['merchants' => $p->items(), 'pagination' => ['page' => $p->currentPage(), 'pages' => $p->lastPage()]]);
    }

    public function merchant(Request $r, Administration $s, string $merchant): mixed
    {
        return ApiResponse::success($s->merchant($r, $merchant));
    }

    public function status(Request $r, Administration $s, string $merchant): mixed
    {
        return ApiResponse::success($s->merchantStatus($r, $merchant));
    }

    public function verification(Request $r, Administration $s, string $merchant): mixed
    {
        return ApiResponse::success($s->verification($r, $merchant));
    }

    public function decide(Request $r, Administration $s, string $merchant, string $submission): mixed
    {
        return ApiResponse::success($s->decide($r, $merchant, $submission));
    }

    public function users(Request $r): mixed
    {
        PlatformSecurity::require($r, 'platform.users.manage');
        $r->validate(['page' => 'sometimes|integer|min:1']);
        $p = DB::table('users')->orderBy('id')->paginate(25, ['id', 'locale', 'suspended_at', 'admin_version', 'email_verified_at']);

        return ApiResponse::success(['users' => $p->items(), 'pagination' => ['page' => $p->currentPage(), 'pages' => $p->lastPage()]]);
    }

    public function user(Request $r, string $user): mixed
    {
        PlatformSecurity::require($r, 'platform.users.manage');
        $row = DB::table('users')->where('id', $user)->first(['id', 'locale', 'suspended_at', 'admin_version', 'email_verified_at']);
        abort_unless($row, 404);
        $roles = DB::table('platform_role_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')->where('a.user_id', $user)->pluck('r.code');

        return ApiResponse::success(['user' => $row, 'roles' => $roles, 'mfa_enabled' => DB::table('user_mfa_credentials')->where('user_id', $user)->whereNotNull('confirmed_at')->exists()]);
    }

    public function userStatus(Request $r, Administration $s, string $user): mixed
    {
        return ApiResponse::success($s->userStatus($r, $user));
    }

    public function role(Request $r, Administration $s, string $user): mixed
    {
        return ApiResponse::success($s->role($r, $user));
    }

    public function audit(Request $r): mixed
    {
        PlatformSecurity::require($r, 'platform.audit.view');
        $r->validate(['page' => 'sometimes|integer|min:1']);
        $p = DB::table('audit_logs')->whereNull('merchant_id')->orderByDesc('created_at')->orderByDesc('id')->paginate(25, ['id', 'action', 'actor_id', 'subject_id', 'created_at', 'metadata']);

        return ApiResponse::success(['events' => array_map(Audit::present(...), $p->items()), 'pagination' => ['page' => $p->currentPage(), 'pages' => $p->lastPage()]]);
    }

    public function merchantAudit(Request $r, Administration $s, string $merchant): mixed
    {
        $s->authorizeTarget($r, $merchant, 'platform.merchant.view');
        $r->validate(['page' => 'sometimes|integer|min:1']);
        $p = DB::table('audit_logs')->where('merchant_id', $merchant)->orderByDesc('created_at')->orderByDesc('id')->paginate(25, ['id', 'action', 'actor_id', 'subject_id', 'created_at', 'metadata']);

        return ApiResponse::success(['events' => array_map(Audit::present(...), $p->items()), 'pagination' => ['page' => $p->currentPage(), 'pages' => $p->lastPage()]]);
    }
}
