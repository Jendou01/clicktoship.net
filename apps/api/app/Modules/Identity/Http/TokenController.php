<?php

namespace App\Modules\Identity\Http;

use App\Models\User;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Application\Sessions;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Merchants\Infrastructure\Merchant;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class TokenController
{
    public function index(Request $request, MerchantContext $context): mixed
    {
        $context->require('tokens.manage');
        $tokens = $request->user()->tokens()->where('merchant_id', $context->id())->orderByDesc('created_at')->limit(100)
            ->get(['id', 'name', 'abilities', 'created_at', 'last_used_at', 'expires_at']);

        return ApiResponse::success(['tokens' => $tokens]);
    }

    public function store(Request $request, MerchantContext $context): mixed
    {
        $data = $request->validate([
            'name' => 'required|string|max:80', 'abilities' => 'required|array|min:1|max:30', 'abilities.*' => 'required|string|distinct|max:80',
            'expires_in_days' => 'sometimes|integer|min:1|max:30', 'current_password' => 'required|string|max:128', 'merchant_id' => 'prohibited',
        ]);
        $token = DB::transaction(function () use ($request, $context, $data) {
            Merchant::whereKey($context->id())->lockForUpdate()->firstOrFail();
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            app(Sessions::class)->validate($request, $user);
            $context->require('tokens.manage');
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => ['The password is invalid.']]);
            }
            if (array_diff($data['abilities'], $context->permissions()) !== []) {
                throw ValidationException::withMessages(['abilities' => ['Only your current merchant permissions may be granted.']]);
            }
            $created = $user->createToken($data['name'], $data['abilities'], now()->addDays($data['expires_in_days'] ?? 30));
            $created->accessToken->forceFill(['merchant_id' => $context->id(), 'auth_version' => $user->auth_version])->save();
            Audit::record('identity.token_created', $user->id, $context->id(), $created->accessToken->id);

            return $created;
        });

        return ApiResponse::success(['token' => $token->plainTextToken, 'id' => $token->accessToken->id, 'expires_at' => $token->accessToken->expires_at], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, string $token, MerchantContext $context): mixed
    {
        $context->require('tokens.manage');
        $record = $request->user()->tokens()->where('merchant_id', $context->id())->whereKey($token)->firstOrFail();
        DB::transaction(function () use ($record, $request, $context): void {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $record->delete();
            Audit::record('identity.token_revoked', $request->user()->id, $context->id(), $record->id);
        });

        return ApiResponse::success(['revoked' => true]);
    }
}
