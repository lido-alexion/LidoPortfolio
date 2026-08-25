<?php

namespace App\Services\Risk;

/**
 * V3 §13.2 exit mechanism identifiers (frozen enum — §28.6).
 */
final class ExitAttribution
{
    public const STRATEGY_EXIT = 'strategy_exit';

    public const STOP_LOSS = 'stop_loss';

    public const TRAILING_STOP = 'trailing_stop';

    public const HORIZON_EXPIRY = 'horizon_expiry';

    /** Highest precedence first (§13.2). */
    public const PRECEDENCE = [
        self::STRATEGY_EXIT,
        self::STOP_LOSS,
        self::TRAILING_STOP,
        self::HORIZON_EXPIRY,
    ];
}
