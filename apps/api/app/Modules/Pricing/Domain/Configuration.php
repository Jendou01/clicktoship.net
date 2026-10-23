<?php

namespace App\Modules\Pricing\Domain;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Version payloads are closed schemas. No invented contract, service or tax defaults. */
final class Configuration
{
    public const KINDS = ['service', 'zone', 'tier', 'assignment', 'tariff', 'rule', 'fee', 'tax'];

    public const SELECTORS = ['service_id', 'origin_country', 'destination_country', 'origin_zone', 'destination_zone', 'lane', 'weight_from', 'weight_to', 'packages_from', 'packages_to'];

    public static function validate(string $kind, array $c, ?string $merchant): array
    {
        $money = ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,12})$/', function ($a, $v, $fail) {
            if ((int) $v > 1000000000000) {
                $fail('Amount exceeds supported range.');
            }
        }];
        $rules = ['name_en' => 'required|string|max:100', 'name_ar' => 'required|string|max:100', 'evidence' => 'required|string|min:10|max:1000'];
        $selectors = ['service_id' => 'required|uuid', 'origin_country' => 'nullable|regex:/^[A-Z]{2}$/', 'destination_country' => 'nullable|regex:/^[A-Z]{2}$/', 'origin_zone' => 'nullable|uuid', 'destination_zone' => 'nullable|uuid', 'lane' => 'nullable|in:domestic,international', 'weight_from' => 'required|integer|min:0|max:1000000000', 'weight_to' => 'required|integer|gt:weight_from|max:1000000001', 'packages_from' => 'required|integer|min:1|max:100', 'packages_to' => 'required|integer|gte:packages_from|max:100'];
        $amounts = ['base_minor' => $money, 'fixed_minor' => $money, 'percent_bps' => 'required|integer|min:0|max:100000', 'included_grams' => 'required|integer|min:0|max:1000000000', 'step_grams' => 'required|integer|min:1|max:1000000000', 'step_minor' => $money];
        $rules += match ($kind) {
            'service' => ['carrier' => 'required|string|regex:/^[a-z0-9_-]{1,50}$/', 'volumetric_divisor' => 'required|integer|min:1|max:100000', 'weight_step_grams' => 'required|integer|min:1|max:1000000', 'weight_mode' => 'required|in:aggregate,per_package', 'ttl_seconds' => 'required|integer|min:30|max:3600', 'rounding' => 'required|in:half_up,up', 'capabilities' => 'required|array:cod,remote,declared_value', 'capabilities.cod' => 'required|boolean', 'capabilities.remote' => 'required|boolean', 'capabilities.declared_value' => 'required|boolean'],
            'zone' => ['country' => 'required|regex:/^[A-Z]{2}$/', 'cities' => 'required|array|min:1|max:500', 'cities.*' => 'required|string|lowercase|max:100|distinct', 'remote' => 'required|boolean'],
            'tier' => [],
            'assignment' => ['tier_id' => 'required|uuid'],
            'tax' => ['country' => 'required|regex:/^[A-Z]{2}$/', 'jurisdiction' => 'required|in:origin,destination', 'mode' => 'required|in:exempt,exclusive', 'percent_bps' => 'required|integer|min:0|max:10000'],
            'tariff' => $selectors + $amounts,
            'rule' => $selectors + $amounts + ['scope' => 'required|in:global,carrier,tier,merchant', 'tier_id' => 'nullable|uuid', 'mode' => 'required|in:fixed,markup', 'discount_minor' => $money, 'discount_bps' => 'required|integer|min:0|max:10000', 'min_margin_minor' => $money, 'min_margin_bps' => 'required|integer|min:0|max:10000', 'tax_id' => 'required|uuid'],
            'fee' => $selectors + ['code' => 'required|in:cod,remote,service,declared_value', 'side' => 'required|in:carrier,merchant', 'basis' => 'required|in:base,sell,cod,declared_value', 'fixed_minor' => $money, 'percent_bps' => 'required|integer|min:0|max:100000', 'per_package' => 'required|boolean'],
            default => throw ValidationException::withMessages(['kind' => 'Unsupported definition kind.']),
        };
        Validator::make($c, $rules)->validate();
        if (array_diff(array_keys($c), array_keys($rules))) {
            throw ValidationException::withMessages(['configuration' => 'Unknown configuration fields.']);
        }
        abort_if(($merchant !== null) !== ($kind === 'assignment' || ($kind === 'rule' && $c['scope'] === 'merchant')), 422);
        if ($kind === 'rule') {
            abort_if(($c['scope'] === 'tier') !== ! empty($c['tier_id']), 422);
        }
        if ($kind === 'tax') {
            abort_if($c['mode'] === 'exempt' && $c['percent_bps'] !== 0, 422);
        }
        if ($kind === 'tariff') {
            abort_unless(! empty($c['origin_country']) && ! empty($c['destination_country']), 422);
        }
        if ($kind === 'fee') {
            abort_if($c['side'] === 'carrier' && $c['basis'] === 'sell', 422);
        }
        foreach (['service_id' => 'service', 'origin_zone' => 'zone', 'destination_zone' => 'zone', 'tier_id' => 'tier', 'tax_id' => 'tax'] as $field => $type) {
            if (! empty($c[$field])) {
                abort_unless(DB::table('pricing_definitions')->where('id', $c[$field])->where('kind', $type)->whereNull('merchant_id')->exists(), 422);
            }
        }

        // Normalize accepted integer-like values once; snapshots/calculator never use floats.
        foreach ($rules as $key => $rule) {
            if (is_string($rule) && str_contains($rule, '|integer') && isset($c[$key])) {
                $c[$key] = (int) $c[$key];
            }
        }

        return $c;
    }

    public static function rank(array $c): int
    {
        return count(array_filter(array_intersect_key($c, array_flip(['origin_country', 'destination_country', 'origin_zone', 'destination_zone', 'lane'])), fn ($v) => $v !== null)) + ($c['weight_from'] > 0 || $c['weight_to'] < 1000000001 ? 1 : 0) + ($c['packages_from'] > 1 || $c['packages_to'] < 100 ? 1 : 0);
    }

    public static function overlaps(array $a, array $b): bool
    {
        foreach (['service_id', 'origin_country', 'destination_country', 'origin_zone', 'destination_zone', 'lane'] as $key) {
            if (! empty($a[$key]) && ! empty($b[$key]) && $a[$key] !== $b[$key]) {
                return false;
            }
        }

        return max($a['weight_from'], $b['weight_from']) < min($a['weight_to'], $b['weight_to']) && max($a['packages_from'], $b['packages_from']) <= min($a['packages_to'], $b['packages_to']);
    }
}
