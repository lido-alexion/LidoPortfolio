<?php

namespace App\Engines\Support;

/**
 * Standard success/error envelope for Trading OS /api/v1 responses (REST API Spec §4).
 */
final class ApiEnvelope
{
    public static function success(mixed $data = [], array $meta = [], int $status = 200)
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => (object) $meta,
        ], $status);
    }

    public static function error(string $code, string $message, int $status = 400, array $meta = [])
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'meta' => (object) $meta,
        ], $status);
    }
}
