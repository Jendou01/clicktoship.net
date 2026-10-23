<?php

namespace App\Modules\Carriers\Infrastructure\Aramex;

use App\Modules\Carriers\Domain\CarrierFailure;
use Illuminate\Support\Str;

final class CarrierResponseNormalizer
{
    /** Input is a parsed legacy structure, NOT an assertion that V2 JSON uses the same envelope. */
    public function normalize(string $operation, string $protocol, array $response): array
    {
        if ($protocol !== 'legacy-soap-manual') {
            throw new CarrierFailure('RESPONSE_CONTRACT_UNVERIFIED', true);
        }
        if (! isset($response['HasErrors']) || ! is_bool($response['HasErrors']) || ! isset($response['Notifications']) || ! is_array($response['Notifications'])) {
            throw new CarrierFailure('MALFORMED_RESPONSE', true);
        }
        if ($response['HasErrors']) {
            throw new CarrierFailure('CARRIER_REJECTED');
        }
        if ($operation === 'CreateShipments') {
            $items = $response['Shipments'] ?? null;
            if (! is_array($items) || count($items) !== 1) {
                throw new CarrierFailure('MALFORMED_RESPONSE', true);
            }
            $s = $items[0];
            if (! isset($s['HasErrors']) || ! is_bool($s['HasErrors']) || ! isset($s['Notifications']) || ! is_array($s['Notifications'])) {
                throw new CarrierFailure('MALFORMED_RESPONSE', true);
            }
            if ($s['HasErrors']) {
                throw new CarrierFailure('CARRIER_REJECTED');
            }
            if (! isset($s['ID']) || ! is_string($s['ID']) || ! preg_match('/^[a-zA-Z0-9-]{1,50}$/', $s['ID'])) {
                throw new CarrierFailure('MALFORMED_RESPONSE', true);
            }

            return ['carrier_reference' => $s['ID']]; // No label URL / personal diagnostics.
        }
        if ($operation === 'CreatePickup' && isset($response['ProcessedPickup']['GUID']) && is_string($response['ProcessedPickup']['GUID']) && Str::isUuid($response['ProcessedPickup']['GUID'])) {
            return ['carrier_reference' => $response['ProcessedPickup']['GUID']];
        }
        throw new CarrierFailure('RESPONSE_CONTRACT_UNVERIFIED', true);
    }
}
