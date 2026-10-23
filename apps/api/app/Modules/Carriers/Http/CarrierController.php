<?php

namespace App\Modules\Carriers\Http;

use App\Modules\Carriers\Application\CarrierAdministration;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

final class CarrierController
{
    public function index(Request $r, CarrierAdministration $admin): mixed
    {
        return ApiResponse::success($admin->index($r));
    }

    public function store(Request $r, CarrierAdministration $admin): mixed
    {
        return ApiResponse::success($admin->write($r), 201);
    }

    public function update(Request $r, CarrierAdministration $admin, string $account): mixed
    {
        return ApiResponse::success($admin->write($r, $account));
    }

    public function change(Request $r, CarrierAdministration $admin, string $account, string $action): mixed
    {
        return ApiResponse::success($admin->change($r, $account, $action));
    }

    public function history(Request $r, CarrierAdministration $admin, string $account): mixed
    {
        return ApiResponse::success($admin->history($r, $account));
    }

    public function webhook(): mixed
    {
        return ApiResponse::error('WEBHOOK_DISABLED', 'Carrier webhook authentication has not been verified.', 503);
    }
}
