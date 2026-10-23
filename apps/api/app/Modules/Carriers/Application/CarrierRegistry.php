<?php

namespace App\Modules\Carriers\Application;

use App\Modules\Carriers\Domain\CarrierAdapterInterface;
use App\Modules\Carriers\Domain\CarrierFailure;
use App\Modules\Carriers\Infrastructure\Aramex\AramexAdapter;

final class CarrierRegistry
{
    public function get(string $carrier): CarrierAdapterInterface
    {
        if ($carrier !== 'aramex') {
            throw new CarrierFailure('UNSUPPORTED_CARRIER');
        }

        return app(AramexAdapter::class);
    }
}
