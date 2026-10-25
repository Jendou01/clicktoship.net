<?php

namespace App\Modules\MerchantDashboard\Http;

use App\Modules\MerchantDashboard\Application\Dashboard;
use App\Modules\Merchants\Domain\MerchantContext;
use Illuminate\Http\Request;

final class DashboardController
{
    public function show(Request $request, MerchantContext $context, Dashboard $dashboard): mixed
    {
        return $dashboard->response($request, $context);
    }

    public function activity(Request $request, MerchantContext $context, Dashboard $dashboard): mixed
    {
        return $dashboard->response($request, $context, true);
    }
}
