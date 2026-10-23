<?php

namespace App\Modules\Carriers\Infrastructure;

use App\Modules\Carriers\Domain\CarrierCapabilityRegistry;

final class DisabledAramexTransport extends HttpCarrierTransport
{
    protected function authorize(string $operation): void
    {
        app(CarrierCapabilityRegistry::class)->assertExternal($operation, 'sandbox');
    }
}
