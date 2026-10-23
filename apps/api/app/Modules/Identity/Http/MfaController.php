<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\Mfa;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

final class MfaController
{
    public function status(Request $r, Mfa $service): mixed
    {
        return ApiResponse::success($service->status($r));
    }

    public function enroll(Request $r, Mfa $service): mixed
    {
        return ApiResponse::success($service->enroll($r), 201);
    }

    public function confirm(Request $r, Mfa $service): mixed
    {
        return ApiResponse::success($service->confirm($r));
    }

    public function challenge(Request $r, Mfa $service): mixed
    {
        return ApiResponse::success($service->challenge($r));
    }

    public function recovery(Request $r, Mfa $service): mixed
    {
        return ApiResponse::success($service->recovery($r));
    }

    public function disable(Request $r, Mfa $service): mixed
    {
        return ApiResponse::success($service->disable($r));
    }
}
