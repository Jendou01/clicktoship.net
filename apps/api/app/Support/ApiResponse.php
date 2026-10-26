<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class ApiResponse
{
    public static function requestId(): string
    {
        if (! request()->attributes->has('request_id')) {
            request()->attributes->set('request_id', (string) Str::uuid());
        }

        return request()->attributes->get('request_id');
    }

    public static function success(array $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => ['request_id' => self::requestId()],
            'error' => null,
        ], $status)->header('X-Request-ID', self::requestId())->header('Cache-Control', 'private, no-store');
    }

    public static function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return response()->json([
            'data' => null,
            'meta' => ['request_id' => self::requestId()],
            'error' => ['code' => $code, 'message' => $message, 'details' => (object) $details],
        ], $status)->header('X-Request-ID', self::requestId())->header('Cache-Control', 'private, no-store');
    }
}
