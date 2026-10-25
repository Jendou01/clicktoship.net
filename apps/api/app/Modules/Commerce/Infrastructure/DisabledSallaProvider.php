<?php

namespace App\Modules\Commerce\Infrastructure;

use App\Modules\Commerce\Domain\IntegrationFailure;
use App\Modules\Commerce\Domain\StoreProvider;

final class DisabledSallaProvider implements StoreProvider
{
    public function available(): bool
    {
        return false;
    }

    public function requiredScopes(): array
    {
        return [];
    }

    public function authorizationUrl(string $state, string $redirect): string
    {
        throw new IntegrationFailure('SALLA_CONTRACT_UNVERIFIED');
    }

    public function exchange(string $code, string $redirect): array
    {
        throw new IntegrationFailure('SALLA_CONTRACT_UNVERIFIED');
    }

    public function refresh(array $credential): array
    {
        throw new IntegrationFailure('SALLA_CONTRACT_UNVERIFIED');
    }

    public function orders(array $credential, ?string $checkpoint, ?string $cursor): array
    {
        throw new IntegrationFailure('SALLA_CONTRACT_UNVERIFIED');
    }

    public function webhook(string $body, array $headers): array
    {
        throw new IntegrationFailure('WEBHOOK_AUTHENTICITY_UNVERIFIED');
    }
}
