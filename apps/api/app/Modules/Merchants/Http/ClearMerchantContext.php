<?php

namespace App\Modules\Merchants\Http;

use App\Modules\Merchants\Domain\MerchantContext;
use Closure;
use Illuminate\Http\Request;

final class ClearMerchantContext
{
    public function handle(Request $request, Closure $next): mixed
    {
        $context = app(MerchantContext::class);
        $context->clear();
        try {
            return $next($request);
        } finally {
            $context->clear();
        }
    }
}
