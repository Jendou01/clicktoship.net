<?php

namespace App\Modules\Merchants\Domain;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class MerchantContext
{
    private ?string $merchant = null;

    private ?string $membership = null;

    private ?string $actor = null;

    private ?PersonalAccessToken $token = null;

    public function id(): ?string
    {
        return $this->merchant;
    }

    public function tokenId(): ?string
    {
        return $this->token?->id;
    }

    public function actorId(): ?string
    {
        return $this->actor;
    }

    public function membershipId(): ?string
    {
        return $this->membership;
    }

    public function establish(User $user, string $merchantId, ?PersonalAccessToken $token = null): void
    {
        $this->clear();
        abort_if($user->suspended_at || ! $user->hasVerifiedEmail(), 403);
        $member = DB::table('merchant_users')->join('merchants', 'merchants.id', '=', 'merchant_users.merchant_id')
            ->where('merchant_users.merchant_id', $merchantId)->where('user_id', $user->id)
            ->where('merchant_users.status', 'active')->where('merchants.status', 'active')->select('merchant_users.id')->first();
        abort_unless($member, 404);
        abort_if($token && ($token->merchant_id !== $merchantId || $token->tokenable_id !== $user->id || $token->auth_version !== $user->auth_version || ($token->expires_at && $token->expires_at->isPast()) || ! $token->exists || ! PersonalAccessToken::whereKey($token->id)->exists()), 403);
        $this->merchant = $merchantId;
        $this->membership = $member->id;
        $this->actor = $user->id;
        $this->token = $token;
    }

    public function permissions(): array
    {
        if (! $this->merchant || ! $this->membership) {
            return [];
        }
        $grants = DB::table('merchant_role_assignments as a')
            ->join('merchant_users as m', 'm.id', '=', 'a.membership_id')
            ->join('users as u', 'u.id', '=', 'm.user_id')->join('merchants as o', 'o.id', '=', 'm.merchant_id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'a.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('a.membership_id', $this->membership)->where('a.merchant_id', $this->merchant)
            ->where('m.status', 'active')->where('o.status', 'active')->whereNull('u.suspended_at')
            ->where('p.scope', 'merchant')->pluck('p.code')->all();

        return $this->token ? array_values(array_filter($grants, fn ($grant) => $this->token->can($grant))) : $grants;
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function require(string $permission): void
    {
        abort_unless($this->allows($permission), 403);
    }

    public function clear(): void
    {
        $this->merchant = $this->membership = $this->actor = null;
        $this->token = null;
    }
}
