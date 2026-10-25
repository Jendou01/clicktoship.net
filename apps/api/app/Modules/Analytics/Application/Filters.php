<?php

namespace App\Modules\Analytics\Application;

use App\Modules\MerchantDashboard\Application\Period;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class Filters
{
    public static function read(Request $r, ?string $report = null): array
    {
        $r->validate(['range' => 'sometimes|in:today,yesterday,7d,30d,90d,custom', 'grain' => 'sometimes|in:day,week,month', 'provider' => 'sometimes|in:salla,shopyzee', 'currency' => 'sometimes|regex:/^[A-Z]{3}$/', 'status' => ['sometimes', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_.]*$/'], 'service' => 'sometimes|uuid', 'after' => 'sometimes|uuid', 'merchant_id' => 'prohibited', 'columns' => 'prohibited', 'sql' => 'prohibited', 'limit' => 'prohibited']);
        if ($r->input('range') === 'yesterday') {
            $day = now('UTC')->subDay()->toDateString();
            $p = Period::read(new Request(['range' => 'custom', 'from' => $day, 'to' => $day]));
            $p['range'] = 'yesterday';
            abort_if($r->has('from') || $r->has('to') || $r->has('timezone'), 422);
        } else {
            $p = Period::read($r);
        }
        $data = ['period' => $p, 'grain' => $r->input('grain', 'day')];
        foreach (['provider', 'currency', 'status', 'service', 'after'] as $key) {
            if ($r->filled($key)) {
                if ($report && $key !== 'after' && ! in_array($key, Definitions::get($report)[4], true)) {
                    throw ValidationException::withMessages([$key => 'Filter is not applicable to this report.']);
                }
                $data[$key] = $r->input($key);
            }
        }

        return $data;
    }
}
