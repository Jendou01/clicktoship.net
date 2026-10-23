<?php

namespace App\Modules\Carriers\Application;

use App\Modules\Carriers\Domain\CarrierFailure;
use Illuminate\Support\Facades\Validator;

final class CarrierServiceMapper
{
    public function validate(array $services): array
    {
        Validator::make(['services' => $services], ['services' => 'array|max:100', 'services.*' => 'array:code,name_en,name_ar,origin_country,destination_country,product_group,product_type,postal_required,state_required,evidence', 'services.*.code' => 'required|regex:/^[a-zA-Z0-9_-]{1,40}$/|distinct', 'services.*.name_en' => 'required|string|max:100', 'services.*.name_ar' => 'required|string|max:100', 'services.*.origin_country' => 'required|regex:/^[A-Z]{2}$/', 'services.*.destination_country' => 'required|regex:/^[A-Z]{2}$/', 'services.*.product_group' => 'required|in:DOM,EXP', 'services.*.product_type' => 'required|regex:/^[A-Z0-9]{1,10}$/', 'services.*.postal_required' => 'required|boolean', 'services.*.state_required' => 'required|boolean', 'services.*.evidence' => 'required|string|min:10|max:1000'])->validate();
        foreach ($services as $s) {
            abort_if(($s['origin_country'] === $s['destination_country']) !== ($s['product_group'] === 'DOM'), 422);
        }

        return $services;
    }

    public function resolve(array $services, string $code, string $origin, string $destination): array
    {
        $matches = array_filter($services, fn ($s) => $s['code'] === $code && $s['origin_country'] === $origin && $s['destination_country'] === $destination);
        if (count($matches) !== 1) {
            throw new CarrierFailure('ROUTE_UNVERIFIED');
        }

        return array_values($matches)[0];
    }
}
