<?php

namespace App\Modules\Commerce\Domain;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

final class OrderData
{
    /** Internal normalized data only; no invented Salla field mapping. Unknown values stay unknown. */
    public static function read(array $input): array
    {
        $rules = ['id' => 'required|string|regex:/^[A-Za-z0-9_-]{1,128}$/', 'reference' => 'required|string|max:100', 'created_at' => 'required|date', 'updated_at' => 'nullable|date', 'status' => 'required|in:ready,cancelled,fulfilled,unknown', 'payment' => 'required|in:paid,unpaid,refunded,unknown', 'fulfillment' => 'required|in:unfulfilled,fulfilled,partial,unknown', 'currency' => 'nullable|regex:/^[A-Z]{3}$/', 'amount_minor' => ['nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,11})$/'], 'cod_minor' => ['nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,11})$/'], 'recipient' => 'required|array:name,phone,country,city,line1,postal,state,email', 'items' => 'present|array|max:100', 'items.*' => 'array:description,sku,quantity,value_minor,weight_grams,country,hs_code', 'items.*.description' => 'required|string|max:200', 'items.*.sku' => 'nullable|string|max:100', 'items.*.quantity' => 'required|integer|min:1|max:100000', 'items.*.value_minor' => ['nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,11})$/'], 'items.*.weight_grams' => 'nullable|integer|min:1|max:1000000000', 'items.*.country' => 'nullable|regex:/^[A-Z]{2}$/', 'items.*.hs_code' => 'nullable|regex:/^[0-9]{6,12}$/'];
        foreach (['name' => 100, 'phone' => 20, 'city' => 100, 'line1' => 200, 'postal' => 20, 'state' => 100, 'email' => 254] as $k => $max) {
            $rules['recipient.'.$k] = "nullable|string|max:$max";
        }
        $rules['recipient.country'] = 'nullable|regex:/^[A-Z]{2}$/';
        $data = Validator::make($input, $rules)->validate();
        if (array_diff(array_keys($input), array_keys($data))) {
            throw new IntegrationFailure('INVALID_NORMALIZED_ORDER');
        }
        foreach (['created_at', 'updated_at'] as $k) {
            if (! empty($data[$k])) {
                $data[$k] = CarbonImmutable::parse($data[$k])->utc()->toIso8601String();
            }
        }

        return $data + ['updated_at' => null, 'currency' => null, 'amount_minor' => null, 'cod_minor' => null];
    }
}
