<?php

namespace App\Modules\Carriers\Domain;

use App\Modules\Merchants\Domain\MerchantContext;

/** Provider-neutral boundary. Test implementations are autoload-dev only. */
interface ShipmentGateway
{
    public function assertReady(string $operation): void;

    public function prepare(MerchantContext $context, string $business, array $draft, string $operation = 'CreateShipments'): string;

    public function send(object $operation): ShipmentOutcome;
}
