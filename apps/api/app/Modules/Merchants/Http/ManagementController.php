<?php

namespace App\Modules\Merchants\Http;

use App\Modules\Identity\Application\Audit;
use App\Modules\Merchants\Application\Invitations;
use App\Modules\Merchants\Application\Management;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Merchants\Domain\ShippingEligibility;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ManagementController
{
    public function profile(Request $r, Management $s): mixed
    {
        return ApiResponse::success($s->profile($r, $r->isMethod('patch')));
    }

    public function settings(Request $r, Management $s): mixed
    {
        return ApiResponse::success($s->settings($r, $r->isMethod('patch')));
    }

    public function addresses(Request $r, Management $s, ?string $address = null): mixed
    {
        return ApiResponse::success($s->addresses($r, $address, match ($r->method()) {
            'POST' => 'create','PATCH' => 'update','DELETE' => 'delete',default => $address ? 'show' : 'index'
        }), $r->isMethod('post') ? 201 : 200);
    }

    public function verification(Request $r, Management $s): mixed
    {
        return ApiResponse::success($s->verification($r->isMethod('post')), $r->isMethod('post') ? 201 : 200);
    }

    public function invitations(Request $r, Invitations $s): mixed
    {
        return ApiResponse::success($r->isMethod('post') ? $s->store($r) : $s->index(), $r->isMethod('post') ? 201 : 200);
    }

    public function resend(Request $r, Invitations $s, string $invitation): mixed
    {
        return ApiResponse::success($s->change($r, $invitation, true));
    }

    public function revoke(Request $r, Invitations $s, string $invitation): mixed
    {
        return ApiResponse::success($s->change($r, $invitation, false));
    }

    public function accept(Request $r, Invitations $s): mixed
    {
        return ApiResponse::success($s->accept($r));
    }

    public function eligibility(MerchantContext $c): mixed
    {
        $c->require('merchant.view');

        return ApiResponse::success(['account_status' => DB::table('merchants')->where('id', $c->id())->value('status'), 'verification_status' => DB::table('merchants')->where('id', $c->id())->value('verification_status'), 'shipping_eligibility' => ShippingEligibility::evaluate(DB::table('merchants')->where('id', $c->id())->value('status'), DB::table('merchants')->where('id', $c->id())->value('verification_status'))]);
    }

    public function audit(Request $r, MerchantContext $c): mixed
    {
        $c->require('audit.view');
        $r->validate(['page' => 'sometimes|integer|min:1']);
        $p = DB::table('audit_logs')->where('merchant_id', $c->id())->orderByDesc('created_at')->orderByDesc('id')->paginate(25, ['id', 'action', 'subject_id', 'created_at', 'metadata']);

        return ApiResponse::success(['events' => array_map(Audit::present(...), $p->items()), 'pagination' => ['page' => $p->currentPage(), 'pages' => $p->lastPage()]]);
    }
}
