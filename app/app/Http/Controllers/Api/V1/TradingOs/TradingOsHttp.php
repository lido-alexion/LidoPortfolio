<?php

namespace App\Http\Controllers\Api\V1\TradingOs;

use App\Engines\Support\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Shared HTTP adapter for ValidationException → ApiEnvelope (unchanged wire shape).
 */
final class TradingOsHttp
{
    public static function validationError(ValidationException $e): JsonResponse
    {
        $msg = collect($e->errors())->flatten()->first() ?? 'Validation failed.';

        return ApiEnvelope::error('VALIDATION_ERROR', $msg, 422);
    }
}
