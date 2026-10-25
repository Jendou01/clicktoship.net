<?php

namespace Tests\Support;

use App\Modules\Commerce\Domain\IntegrationFailure;
use App\Modules\Commerce\Domain\ShopyzeeProvider;

/** Internal fixtures only. No Nazmart/Shopyzee wire protocol is asserted. */
final class SyntheticShopyzeeProvider implements ShopyzeeProvider
{
    public SyntheticStoreProvider $fixture;

    public string $platform = 'instance-A';

    public string $store = 'tenant-A';

    public function __construct()
    {
        $this->fixture = new SyntheticStoreProvider;
    }

    public function available(): bool
    {
        return true;
    }

    public function requiredScopes(): array
    {
        return $this->fixture->requiredScopes();
    }

    public function authorizationUrl(string $state, string $redirect): string
    {
        throw new IntegrationFailure('AUTHORIZATION_CONTRACT_UNVERIFIED');
    }

    public function exchange(string $code, string $redirect): array
    {
        throw new IntegrationFailure('AUTHORIZATION_CONTRACT_UNVERIFIED');
    }

    public function refresh(array $credential): array
    {
        return ['provider' => 'shopyzee', 'platform_id' => $this->platform, 'store_id' => $this->store] + $this->fixture->refresh($credential);
    }

    public function orders(array $credential, ?string $checkpoint, ?string $cursor): array
    {
        return $this->fixture->orders($credential, $checkpoint, $cursor);
    }

    public function webhook(string $body, array $headers): array
    {
        throw new IntegrationFailure('WEBHOOK_CONTRACT_UNVERIFIED');
    }
}
