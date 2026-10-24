<?php

namespace App\Modules\Shipping\Domain;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ShipmentInput
{
    public static function digest(array $value): string
    {
        $sort = function ($v) use (&$sort) {
            if (! is_array($v)) {
                return $v;
            } if (! array_is_list($v)) {
                ksort($v);
            }

            return array_map($sort, $v);
        };

        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR));
    }

    public static function draft(array $input): array
    {
        $rules = ['service_id' => 'nullable|uuid', 'currency' => 'required|in:SAR', 'description' => 'required|string|max:500', 'origin' => 'required|array:country,city,line1,postal,state,name,phone,email', 'destination' => 'required|array:country,city,line1,postal,state,name,phone,email', 'return_address' => 'nullable|array:country,city,line1,postal,state,name,phone,email', 'packages' => 'required|array|min:1|max:100', 'packages.*' => 'required|array:weight_grams,length_mm,width_mm,height_mm', 'declared_value_minor' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,11})$/'], 'cod_minor' => 'required|in:0', 'goods_origin_country' => 'nullable|regex:/^[A-Z]{2}$/', 'items' => 'present|array|max:100', 'items.*' => 'required|array:description,quantity,value_minor,country,hs_code', 'items.*.description' => 'required|string|max:200', 'items.*.quantity' => 'required|integer|min:1|max:100000', 'items.*.value_minor' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,11})$/'], 'items.*.country' => 'required|regex:/^[A-Z]{2}$/', 'items.*.hs_code' => 'nullable|regex:/^[0-9]{6,12}$/'];
        foreach (['origin', 'destination', 'return_address'] as $side) {
            foreach (['country' => 'required|regex:/^[A-Z]{2}$/', 'city' => 'required|string|max:100', 'line1' => 'nullable|string|max:200', 'postal' => 'nullable|string|max:20', 'state' => 'nullable|string|max:100', 'name' => 'nullable|string|max:100', 'phone' => 'nullable|regex:/^\+[1-9][0-9]{6,14}$/', 'email' => 'nullable|email|max:254'] as $key => $rule) {
                $rules["$side.$key"] = $side === 'return_address' ? str_replace('required|', 'required_with:return_address|', $rule) : $rule;
            }
        }
        foreach (['weight_grams' => 1000000000, 'length_mm' => 200000, 'width_mm' => 200000, 'height_mm' => 200000] as $k => $max) {
            $rules["packages.*.$k"] = "required|integer|min:1|max:$max";
        }
        $data = Validator::make($input, $rules)->validate();
        if (array_diff(array_keys($input), array_keys($data))) {
            throw ValidationException::withMessages(['draft' => 'Unsupported fields.']);
        }
        foreach (array_filter(['origin', 'destination', 'return_address'], fn ($side) => ! empty($data[$side])) as $side) {
            $data[$side]['city'] = mb_strtolower(trim($data[$side]['city']));
            abort_if($data[$side]['city'] === '', 422);
        }
        foreach ($data['packages'] as &$package) {
            foreach ($package as &$v) {
                $v = (int) $v;
            }
        }

        return $data;
    }

    public static function pricing(array $draft): array
    {
        return ['service_id' => $draft['service_id'] ?? null, 'currency' => $draft['currency'], 'origin' => array_intersect_key($draft['origin'], array_flip(['country', 'city'])), 'destination' => array_intersect_key($draft['destination'], array_flip(['country', 'city'])), 'packages' => $draft['packages'], 'cod_minor' => $draft['cod_minor'], 'declared_value_minor' => $draft['declared_value_minor']];
    }

    public static function report(array $draft): array
    {
        $missing = [];
        if (empty($draft['service_id'])) {
            $missing[] = 'service_id';
        }
        foreach (array_filter(['origin', 'destination', 'return_address'], fn ($side) => ! empty($draft[$side])) as $side) {
            foreach (['name', 'phone', 'line1'] as $key) {
                if (empty($draft[$side][$key])) {
                    $missing[] = "$side.$key";
                }
            }
            if (in_array($draft[$side]['country'], ['SA', 'US']) && ! preg_match($draft[$side]['country'] === 'SA' ? '/^[0-9]{5}$/' : '/^[0-9]{5}(-[0-9]{4})?$/', $draft[$side]['postal'] ?? '')) {
                $missing[] = "$side.postal";
            }
            if ($draft[$side]['country'] === 'US' && empty($draft[$side]['state'])) {
                $missing[] = "$side.state";
            }
        }
        if ($draft['origin']['country'] !== $draft['destination']['country']) {
            if (empty($draft['goods_origin_country'])) {
                $missing[] = 'goods_origin_country';
            }
            if (empty($draft['items'])) {
                $missing[] = 'items';
            }
        }

        return ['valid' => ! $missing, 'missing' => $missing, 'carrier_ready' => false, 'shipping_enabled' => false, 'reason' => 'CARRIER_EVIDENCE_AND_FINANCIAL_ACTIVATION_REQUIRED'];
    }
}
