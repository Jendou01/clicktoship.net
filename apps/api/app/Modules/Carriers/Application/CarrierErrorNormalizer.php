<?php

namespace App\Modules\Carriers\Application;

use App\Modules\Carriers\Domain\CarrierCapabilityRegistry;
use App\Modules\Carriers\Domain\CarrierFailure;

final class CarrierErrorNormalizer
{
    public function http(string $operation, int $status): CarrierFailure
    {
        $mutating = CarrierCapabilityRegistry::mutating($operation);
        $code = match (true) {
            in_array($status, [401, 403], true) => 'AUTHENTICATION_FAILED',$status === 429 => 'RATE_LIMITED',$status >= 500 => 'UPSTREAM_UNAVAILABLE',$status >= 300 && $status < 400 => 'REDIRECT_BLOCKED',default => 'UPSTREAM_REJECTED'
        };

        return new CarrierFailure($code, $mutating, ! $mutating && ($status === 429 || $status >= 500), $status);
    }

    public function network(string $operation): CarrierFailure
    {
        $mutating = CarrierCapabilityRegistry::mutating($operation);

        return new CarrierFailure('NETWORK_FAILURE', $mutating, ! $mutating);
    }
}
