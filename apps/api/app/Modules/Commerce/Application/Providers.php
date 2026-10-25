<?php

namespace App\Modules\Commerce\Application;

use App\Modules\Commerce\Domain\IntegrationFailure;
use App\Modules\Commerce\Domain\ShopyzeeProvider;
use App\Modules\Commerce\Domain\StoreProvider;

final class Providers
{
    public function adapter(string $provider): StoreProvider
    {
        return match ($provider) {
            'salla' => app(StoreProvider::class),
            'shopyzee' => app(ShopyzeeProvider::class),
            default => throw new IntegrationFailure('PROVIDER_UNSUPPORTED'),
        };
    }

    public function catalog(): array
    {
        return array_map(fn ($p) => ['provider' => $p, 'available' => false, 'official_contract_verified' => false, 'authorization_method' => null, 'oauth_mode' => null, 'required_scopes' => [], 'reason' => strtoupper($p).'_CONTRACT_UNVERIFIED', 'external_webhooks_enabled' => false, 'fulfillment_writeback_enabled' => false, 'carrier_execution_enabled' => false, 'payments_enabled' => false], ['salla', 'shopyzee']);
    }

    /** Normalized internal proof, never inferred from domains or client input. */
    public function matches(object $c, array $proof): bool
    {
        return ($proof['store_id'] ?? null) === $c->external_store_id
            && ($proof['provider'] ?? ($c->provider === 'salla' ? 'salla' : null)) === $c->provider
            && ($proof['platform_id'] ?? ($c->provider === 'salla' ? 'salla' : null)) === $c->external_platform_id;
    }
}
