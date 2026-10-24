<?php

namespace App\Modules\Pricing\Application;

use Illuminate\Support\Facades\DB;

final class AvailableServices
{
    public function contains(string $id): bool
    {
        return DB::table('pricing_definitions as d')->join('pricing_versions as v', function ($j) {
            $j->on('v.definition_id', '=', 'd.id')->on('v.version', '=', 'd.active_version');
        })
            ->where('d.id', $id)->where('d.kind', 'service')->whereNull('d.merchant_id')->where('d.enabled', true)->where('v.effective_from', '<=', now())->where('v.effective_to', '>', now())->exists();
    }
}
