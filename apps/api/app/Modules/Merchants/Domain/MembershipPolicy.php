<?php

namespace App\Modules\Merchants\Domain;

use App\Models\User;
use App\Modules\Merchants\Infrastructure\Membership;

final class MembershipPolicy
{
    public function view(User $user, Membership $membership): bool
    {
        $context = app(MerchantContext::class);

        return $context->actorId() === $user->id && $membership->merchant_id === $context->id() && $context->allows('team.view');
    }

    public function update(User $user, Membership $membership): bool
    {
        $context = app(MerchantContext::class);

        return $context->actorId() === $user->id && $membership->merchant_id === $context->id() && $context->allows('team.manage');
    }
}
