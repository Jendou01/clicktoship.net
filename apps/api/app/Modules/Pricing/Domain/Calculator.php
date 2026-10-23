<?php

namespace App\Modules\Pricing\Domain;

use App\Modules\WalletBilling\Domain\Money;
use DomainException;
use Illuminate\Support\Carbon;

/** Pure integer calculation; all monetary results checked against the existing Money bound. */
final class Calculator
{
    private function require(bool $value): void
    {
        if (! $value) {
            throw new DomainException('RATE_UNAVAILABLE');
        }
    }

    private function one(array $rows): array
    {
        $this->require(count($rows) === 1);

        return array_values($rows)[0];
    }

    public static function ceil(int $n, int $d): int
    {
        return intdiv($n, $d) + ($n % $d === 0 ? 0 : 1);
    }

    private function percent(int $n, int $bps, string $round): int
    {
        $product = $n * $bps;

        return $round === 'up' ? self::ceil($product, 10000) : intdiv($product + 5000, 10000);
    }

    private function money(int $n, string $currency): int
    {
        $this->require($n >= 0 && $n <= 1000000000000);

        return (new Money((string) $n, $currency))->minor;
    }

    private function steps(int $weight, array $c): int
    {
        $steps = self::ceil(max(0, $weight - $c['included_grams']), $c['step_grams']);
        $unit = (int) $c['step_minor'];
        $this->require($unit === 0 || $steps <= intdiv(1000000000000, $unit));

        return $steps * $unit;
    }

    public function calculate(array $input, string $merchant, array $all): array
    {
        $selected = [];
        $pick = function (array $row) use (&$selected) {
            $selected[$row['id']] = $row;

            return $row['config'];
        };
        $service = $this->one(array_filter($all, fn ($r) => $r['kind'] === 'service' && $r['id'] === $input['service_id']));
        $s = $pick($service);
        $currency = $input['currency'];
        $round = $s['rounding'];
        $zones = [];
        foreach (['origin', 'destination'] as $side) {
            $matches = array_filter($all, fn ($r) => $r['kind'] === 'zone' && $r['config']['country'] === $input[$side]['country'] && in_array($input[$side]['city'], $r['config']['cities'], true));
            $this->require(count($matches) === 1);
            $zones[$side] = $matches ? $this->one($matches) : null;
            if ($zones[$side]) {
                $pick($zones[$side]);
            }
        }
        $billable = 0;
        $actual = 0;
        $volumetric = 0;
        foreach ($input['packages'] as $p) {
            $v = self::ceil($p['length_mm'] * $p['width_mm'] * $p['height_mm'], $s['volumetric_divisor']);
            $w = max($p['weight_grams'], $v);
            $actual += $p['weight_grams'];
            $volumetric += $v;
            $billable += $s['weight_mode'] === 'per_package' ? self::ceil($w, $s['weight_step_grams']) * $s['weight_step_grams'] : $w;
        }
        if ($s['weight_mode'] === 'aggregate') {
            $billable = self::ceil($billable, $s['weight_step_grams']) * $s['weight_step_grams'];
        }
        $this->require($billable < 1000000001);
        $count = count($input['packages']);
        $lane = $input['origin']['country'] === $input['destination']['country'] ? 'domestic' : 'international';
        $match = function ($r) use ($input, $zones, $lane, $billable, $count) {
            $c = $r['config'];
            if ($c['service_id'] !== $input['service_id'] || $billable < $c['weight_from'] || $billable >= $c['weight_to'] || $count < $c['packages_from'] || $count > $c['packages_to']) {
                return false;
            }
            foreach (['origin', 'destination'] as $side) {
                if (! empty($c[$side.'_country']) && $c[$side.'_country'] !== $input[$side]['country']) {
                    return false;
                }
                if (! empty($c[$side.'_zone']) && $c[$side.'_zone'] !== ($zones[$side]['id'] ?? null)) {
                    return false;
                }
            }

            return empty($c['lane']) || $c['lane'] === $lane;
        };
        $tariff = $this->one(array_filter($all, fn ($r) => $r['kind'] === 'tariff' && $match($r)));
        $t = $pick($tariff);
        $this->require($tariff['currency'] === $currency);
        $cost = $this->money((int) $t['base_minor'] + (int) $t['fixed_minor'] + $this->percent((int) $t['base_minor'], $t['percent_bps'], $round) + $this->steps($billable, $t), $currency);
        $assignments = array_filter($all, fn ($r) => $r['kind'] === 'assignment' && $r['merchant_id'] === $merchant);
        $this->require(count($assignments) <= 1);
        $tier = null;
        if ($assignments) {
            $assignment = $this->one($assignments);
            $tier = $pick($assignment)['tier_id'];
            $pick($this->one(array_filter($all, fn ($r) => $r['kind'] === 'tier' && $r['id'] === $tier)));
        }
        $rules = array_filter($all, function ($r) use ($merchant, $tier, $match) {
            if ($r['kind'] !== 'rule' || ! $match($r)) {
                return false;
            }

            return match ($r['config']['scope']) {
                'merchant' => $r['merchant_id'] === $merchant,'tier' => $r['config']['tier_id'] === $tier,default => $r['merchant_id'] === null
            };
        });
        $rank = fn ($r) => ['global' => 100, 'carrier' => 200, 'tier' => 300, 'merchant' => 400][$r['config']['scope']] + Configuration::rank($r['config']);
        usort($rules, fn ($a, $b) => $rank($b) <=> $rank($a));
        $this->require(count($rules) > 0 && (count($rules) === 1 || $rank($rules[0]) !== $rank($rules[1])));
        $rule = $rules[0];
        $r = $pick($rule);
        $this->require($rule['currency'] === $currency);
        $tax = $this->one(array_filter($all, fn ($x) => $x['kind'] === 'tax' && $x['id'] === $r['tax_id']));
        $taxPolicy = $pick($tax);
        $this->require($taxPolicy['country'] === $input[$taxPolicy['jurisdiction']]['country']);
        $requested = ['cod' => (int) $input['cod_minor'] > 0, 'declared_value' => (int) $input['declared_value_minor'] > 0, 'remote' => $zones['destination']['config']['remote'] ?? false];
        foreach ($requested as $cap => $enabled) {
            $this->require(! $enabled || $s['capabilities'][$cap]);
        }
        $fees = array_values(array_filter($all, fn ($x) => $x['kind'] === 'fee' && $match($x) && ($x['config']['code'] === 'service' || ($requested[$x['config']['code']] ?? false))));
        foreach (['cod', 'remote', 'declared_value'] as $cap) {
            if ($requested[$cap]) {
                $this->require((bool) array_filter($fees, fn ($f) => $f['config']['code'] === $cap));
            }
        }
        $seen = [];
        $merchantFees = [];
        $baseCost = $cost;
        $feeAmount = function ($f, int $sell) use ($input, $baseCost, $count, $round) {
            $c = $f['config'];
            $basis = match ($c['basis']) {
                'base' => $baseCost,'sell' => $sell,'cod' => (int) $input['cod_minor'],'declared_value' => (int) $input['declared_value_minor']
            };

            return ((int) $c['fixed_minor'] + $this->percent($basis, $c['percent_bps'], $round)) * ($c['per_package'] ? $count : 1);
        };
        foreach ($fees as $f) {
            $key = $f['config']['side'].':'.$f['config']['code'];
            $this->require(! isset($seen[$key]) && $f['currency'] === $currency);
            $seen[$key] = true;
            $pick($f);
            if ($f['config']['side'] === 'carrier') {
                $cost = $this->money($cost + $feeAmount($f, 0), $currency);
            }
        }
        $base = $r['mode'] === 'markup' ? $cost : (int) $r['base_minor'];
        $selling = $this->money($base + (int) $r['fixed_minor'] + $this->percent($base, $r['percent_bps'], $round) + $this->steps($billable, $r), $currency);
        $discount = $this->money((int) $r['discount_minor'] + $this->percent($selling, $r['discount_bps'], $round), $currency);
        $net = $this->money($selling - $discount, $currency);
        foreach ($fees as $f) {
            if ($f['config']['side'] === 'merchant') {
                $merchantFees[] = ['code' => $f['config']['code'], 'amount_minor' => (string) $this->money($feeAmount($f, $net), $currency)];
            }
        }
        $pretax = $this->money($net + array_sum(array_map(fn ($f) => (int) $f['amount_minor'], $merchantFees)), $currency);
        $margin = $pretax - $cost;
        $this->require($margin >= (int) $r['min_margin_minor'] && $margin * 10000 >= $pretax * $r['min_margin_bps']);
        $taxAmount = $this->money($this->percent($pretax, $taxPolicy['percent_bps'], $round), $currency);
        $total = $this->money($pretax + $taxAmount, $currency);
        $expires = now()->addSeconds($s['ttl_seconds']);
        foreach ($selected as $x) {
            if ($expires->gt($x['effective_to'])) {
                $expires = Carbon::parse($x['effective_to']);
            }
        }

        return ['public' => ['service_id' => $service['id'], 'service_name' => ['en' => $s['name_en'], 'ar' => $s['name_ar']], 'carrier' => $s['carrier'], 'service_availability' => 'configured_estimate', 'carrier_availability' => 'not_live_verified', 'shipping_enabled' => false, 'currency' => $currency, 'selling_minor' => (string) $selling, 'discount_minor' => (string) $discount, 'fees' => $merchantFees, 'tax' => ['mode' => $taxPolicy['mode'], 'amount_minor' => (string) $taxAmount], 'total_minor' => (string) $total, 'billable_weight_grams' => $billable, 'actual_weight_grams' => $actual, 'volumetric_weight_grams' => $volumetric, 'expires_at' => $expires->toIso8601String()],
            'private' => ['inputs' => $input, 'selected_versions' => array_values($selected), 'carrier_cost_minor' => (string) $cost, 'estimated_margin_minor' => (string) $margin, 'rounding' => $round, 'weight_mode' => $s['weight_mode'], 'volumetric_divisor' => $s['volumetric_divisor']]];
    }
}
