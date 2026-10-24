<?php

namespace App\Modules\Carriers\Infrastructure;

use App\Modules\Carriers\Domain\CarrierCapabilityRegistry;
use App\Modules\Carriers\Domain\ShipmentGateway;
use App\Modules\Carriers\Domain\ShipmentOutcome;
use App\Modules\Merchants\Domain\MerchantContext;

final class EvidenceGatedShipmentGateway implements ShipmentGateway
{
    public function assertReady(string $operation): void
    {
        app(CarrierCapabilityRegistry::class)->assertExternal($operation, 'sandbox');
    }

    public function prepare(MerchantContext $context, string $business, array $draft, string $operation = 'CreateShipments'): string
    {
        $this->assertReady($operation);
        // Unreachable until verified account/service mapping and current contracts are approved.
        throw new \LogicException('Verified carrier mapping required');
    }

    public function send(object $operation): ShipmentOutcome
    {
        $this->assertReady($operation->operation);
        throw new \LogicException('Verified carrier response contract required');
    }
}
