<?php

namespace App\Modules\Carriers\Application;

/** Internal partial DTO only: current request evidence does not establish a V2 customs contract. */
final class CustomsCarrierData
{
    public function prepare(array $draft): array
    {
        return ['documented_request_fields' => ['origin' => array_intersect_key($draft['origin'], array_flip(['country', 'city', 'postal', 'state', 'line1'])), 'destination' => array_intersect_key($draft['destination'], array_flip(['country', 'city', 'postal', 'state', 'line1'])), 'packages' => $draft['packages'], 'currency' => $draft['currency'], 'description' => $draft['description'], 'goods_origin_country' => $draft['goods_origin_country'] ?? null, 'declared_minor' => $draft['declared_value_minor']], 'merchant_declaration' => $draft['customs'] ?? null, 'unmapped_fields' => ['hs_code', 'incoterm', 'duty_payer', 'country_fields', 'declaration_documents'], 'mapping_verified' => false, 'external_enabled' => false];
    }
}
