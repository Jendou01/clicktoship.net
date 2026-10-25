<?php

namespace App\Modules\MerchantDashboard\Application;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class Period
{
    public static function read(Request $r): array
    {
        $d = $r->validate(['range' => 'sometimes|in:today,7d,30d,90d,custom', 'from' => 'required_if:range,custom|prohibited_unless:range,custom|date_format:Y-m-d', 'to' => 'required_if:range,custom|prohibited_unless:range,custom|date_format:Y-m-d', 'merchant_id' => 'prohibited', 'timezone' => 'prohibited']);
        $range = $d['range'] ?? '30d';
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $from = $range === 'custom' ? CarbonImmutable::parse($d['from'], 'UTC') : $today->subDays(match ($range) {
            'today' => 0, '7d' => 6, '90d' => 89, default => 29
        });
        $to = $range === 'custom' ? CarbonImmutable::parse($d['to'], 'UTC') : $today;
        if ($from->gt($to) || $to->gt($today) || $from->diffInDays($to) >= 366) {
            throw ValidationException::withMessages(['from' => 'Use an ordered UTC range of at most 366 days ending no later than today.']);
        }

        return ['range' => $range, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'start' => $from->toIso8601String(), 'end_exclusive' => $to->addDay()->toIso8601String(), 'timezone' => 'UTC'];
    }
}
