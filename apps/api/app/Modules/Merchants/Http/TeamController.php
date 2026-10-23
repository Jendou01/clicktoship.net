<?php

namespace App\Modules\Merchants\Http;

use App\Modules\Merchants\Application\TeamService;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Merchants\Infrastructure\Membership;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class TeamController
{
    public function index(Request $request, MerchantContext $context): mixed
    {
        $context->require('team.view');
        $page = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $query = Membership::query()->join('users as u', 'u.id', '=', 'merchant_users.user_id')
            ->join('merchant_role_assignments as a', 'a.membership_id', '=', 'merchant_users.id')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->orderBy('merchant_users.created_at')->orderBy('merchant_users.id')
            ->select('merchant_users.id', 'merchant_users.version', 'merchant_users.status', 'u.name', 'u.email', 'r.code as role');
        $rows = $query->paginate($page['per_page'] ?? 25);

        return ApiResponse::success(['memberships' => $rows->items(), 'pagination' => ['page' => $rows->currentPage(), 'pages' => $rows->lastPage(), 'total' => $rows->total()]]);
    }

    public function show(string $membership): mixed
    {
        $member = Membership::findOrFail($membership);
        Gate::authorize('view', $member);

        return ApiResponse::success(['membership' => ['id' => $member->id, 'user_id' => $member->user_id, 'status' => $member->status, 'version' => $member->version]]);
    }

    public function store(Request $request, TeamService $service): mixed
    {
        $data = $request->validate(['email' => 'required|email:rfc|max:254', 'role' => 'required|string|max:80', 'merchant_id' => 'prohibited', 'user_id' => 'prohibited']);
        $member = $service->add($data);

        return ApiResponse::success(['membership' => ['id' => $member->id, 'version' => $member->version]], 201);
    }

    public function update(Request $request, string $membership, TeamService $service): mixed
    {
        Gate::authorize('update', Membership::findOrFail($membership));
        $data = $request->validate([
            'version' => 'required|integer|min:1', 'role' => 'sometimes|required|string|max:80',
            'status' => ['sometimes', 'required', Rule::in(['active', 'suspended', 'removed'])],
            'merchant_id' => 'prohibited', 'user_id' => 'prohibited', 'role_id' => 'prohibited',
        ]);
        $member = $service->update($membership, $data);

        return ApiResponse::success(['membership' => ['id' => $member->id, 'status' => $member->status, 'version' => $member->version]]);
    }

    public function destroy(Request $request, string $membership, TeamService $service): mixed
    {
        Gate::authorize('update', Membership::findOrFail($membership));
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $member = $service->update($membership, ['version' => $data['version'], 'status' => 'removed']);

        return ApiResponse::success(['membership' => ['id' => $member->id, 'status' => $member->status, 'version' => $member->version]]);
    }
}
