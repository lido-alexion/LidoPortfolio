<?php

namespace App\Support;

class RequestContext
{
    protected static ?string $requestId = null;

    public static function setRequestId(string $requestId): void
    {
        static::$requestId = $requestId;
    }

    public static function getRequestId(): ?string
    {
        return static::$requestId;
    }

    public static function clear(): void
    {
        static::$requestId = null;
    }
}
