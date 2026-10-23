<?php

namespace App\Modules\Merchants\Http;

use App\Models\PersonalAccessToken;
use App\Modules\Merchants\Domain\MerchantContext;
use Closure;
use Illuminate\Http\Request;

final class ResolveMerchant
{
    public function handle(Request $request, Closure $next): mixed
    {
        $context = app(MerchantContext::class);
        $context->clear();
        try {
            $token = $request->user()->currentAccessToken();
            $token = $token instanceof PersonalAccessToken ? $token : null;
            $id = $token ? $token->merchant_id : $request->session()->get('active_merchant_id');
            abort_unless($id, 409);
            abort_if($request->header('X-Merchant-ID') && $request->header('X-Merchant-ID') !== $id, 409);
            $context->establish($request->user(), $id, $token);

            return $next($request);
        } finally {
            $context->clear();
        }
    }
}
