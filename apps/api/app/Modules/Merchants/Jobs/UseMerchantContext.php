<?php

namespace App\Modules\Merchants\Jobs;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Modules\Merchants\Domain\MerchantContext;

final class UseMerchantContext
{
    public function __construct(public string $merchantId, public string $actorId, public string $permission, public ?string $tokenId = null) {}

    public function handle(object $job, callable $next): mixed
    {
        $context = app(MerchantContext::class);
        $context->clear();
        try {
            $token = $this->tokenId ? PersonalAccessToken::find($this->tokenId) : null;
            abort_if($this->tokenId && ! $token, 403);
            $context->establish(User::findOrFail($this->actorId), $this->merchantId, $token);
            $context->require($this->permission);

            return $next($job);
        } finally {
            $context->clear();
        }
    }
}
