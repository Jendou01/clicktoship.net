<?php

namespace App\Modules\Merchants\Http;

use App\Models\PersonalAccessToken;
use App\Modules\Identity\Application\Audit;
use App\Modules\Merchants\Application\MerchantService;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Merchants\Domain\ShippingEligibility;
use App\Modules\Merchants\Infrastructure\Merchant;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MerchantController
{
    public function index(Request $request): mixed
    {
        $query = DB::table('merchant_users as m')->join('merchants as o', 'o.id', '=', 'm.merchant_id')
            ->join('merchant_role_assignments as a', 'a.membership_id', '=', 'm.id')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('m.user_id', $request->user()->id)->where('m.status', 'active')->where('o.status', 'active');
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $query->where('o.id', $token->merchant_id);
        }
        $rows = $query->orderBy('o.created_at')->limit(100)->get(['o.id', 'o.name', 'm.id as membership_id', 'r.code as role']);

        return ApiResponse::success(['merchants' => $rows]);
    }

    public function store(Request $request, MerchantService $service): mixed
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'merchant_id' => 'prohibited', 'role' => 'prohibited', 'status' => 'prohibited']);
        $merchant = $service->create($request->user(), $data['name']);
        $request->session()->put('active_merchant_id', $merchant->id);

        return ApiResponse::success(['merchant' => ['id' => $merchant->id, 'name' => $merchant->name]], 201);
    }

    public function select(Request $request): mixed
    {
        $data = $request->validate(['merchant_id' => 'required|uuid']);
        $context = app(MerchantContext::class);
        try {
            $context->establish($request->user(), $data['merchant_id']);
            $request->session()->put('active_merchant_id', $data['merchant_id']);
            Audit::record('merchant.selected', $request->user()->id, $data['merchant_id'], $context->membershipId());

            return ApiResponse::success(['active_merchant_id' => $data['merchant_id']]);
        } finally {
            $context->clear();
        }
    }

    public function current(MerchantContext $context): mixed
    {
        $context->require('merchant.view');
        $merchant = Merchant::findOrFail($context->id());

        return ApiResponse::success(['merchant' => ['id' => $merchant->id, 'name' => $merchant->name, 'account_status' => $merchant->status, 'verification_status' => $merchant->verification_status, 'version' => $merchant->version], 'shipping_eligibility' => ShippingEligibility::evaluate($merchant->status, $merchant->verification_status), 'permissions' => $context->permissions()]);
    }

    public function roles(MerchantContext $context): mixed
    {
        $context->require('roles.view');
        $roles = DB::table('roles')->where('scope', 'merchant')->orderBy('name')->get();
        $rows = $roles->map(fn ($role) => [
            'code' => $role->code, 'name' => $role->name,
            'permissions' => DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $role->id)->orderBy('p.code')->pluck('p.code'),
        ]);

        return ApiResponse::success(['roles' => $rows]);
    }

    public function permissions(MerchantContext $context): mixed
    {
        $context->require('roles.view');

        return ApiResponse::success(['permissions' => $context->permissions()]);
    }
}
