<?php

namespace App\Services;

/**
 * Suppresses per-symbol Telegram alerts during batch jobs (daily sync, cron).
 * Manual single-stock backfills leave this off so the user is notified.
 */
class PriceSyncNotificationContext
{
    protected static int $suppressDepth = 0;

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutTelegram(callable $callback): mixed
    {
        self::$suppressDepth++;

        try {
            return $callback();
        } finally {
            self::$suppressDepth--;
        }
    }

    public static function shouldNotifyTelegramOnFailure(bool $requested): bool
    {
        return $requested && self::$suppressDepth === 0;
    }
}
