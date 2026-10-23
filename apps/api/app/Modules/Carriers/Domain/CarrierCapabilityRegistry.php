<?php

namespace App\Modules\Carriers\Domain;

final class CarrierCapabilityRegistry
{
    public const PATHS = [
        'CalculateRate' => 'RateCalculator/Service_1_0.svc/json/CalculateRate',
        'CreateShipments' => 'Shipping/Service_1_0.svc/json/CreateShipments',
        'CreatePickup' => 'Shipping/Service_1_0.svc/json/CreatePickup',
        'CancelPickup' => 'Shipping/Service_1_0.svc/json/CancelPickup',
        'PrintLabel' => 'Shipping/Service_1_0.svc/json/PrintLabel',
        'TrackShipments' => 'Tracking/Service_1_0.svc/json/TrackShipments',
        'TrackPickup' => 'Tracking/Service_1_0.svc/json/TrackPickup',
        'FetchCities' => 'Location/Service_1_0.svc/json/FetchCities',
    ];

    public const UNVERIFIED = ['FetchCountries', 'CountryDetails', 'ValidateAddress', 'ValidatePostalCode', 'SuggestCities', 'OfficeLocations', 'Webhook', 'LookupByClientReference'];

    public function all(): array
    {
        return array_map(fn ($op) => ['operation' => $op, 'request_documented' => isset(self::PATHS[$op]), 'response_contract_verified' => false, 'sandbox_verified' => false, 'account_enabled' => false, 'external_enabled' => false, 'state' => isset(self::PATHS[$op]) ? 'request_documented_response_unverified' : 'unsupported_unverified', 'evidence' => 'docs/integrations/PHASE_6_EVIDENCE.md'], [...array_keys(self::PATHS), ...self::UNVERIFIED]);
    }

    public function assertExternal(string $operation, string $environment): never
    {
        throw new CarrierFailure($environment === 'production' ? 'PRODUCTION_DISABLED' : (isset(self::PATHS[$operation]) ? 'CONTRACT_UNVERIFIED' : 'UNSUPPORTED_CAPABILITY'));
    }

    public static function mutating(string $operation): bool
    {
        return in_array($operation, ['CreateShipments', 'CreatePickup', 'CancelPickup'], true);
    }
}
