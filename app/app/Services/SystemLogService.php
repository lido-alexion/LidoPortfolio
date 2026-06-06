<?php

namespace App\Services;

/**
 * Backward-compatible facade over file-based portfolio logging (no DB writes).
 */
class SystemLogService
{
    public function __construct(protected PortfolioLoggerService $logger) {}

    public function log(string $category, string $message, array $context = [], string $level = 'error'): void
    {
        $channel = match (strtolower($category)) {
            'api_failure', 'invalid_symbol', 'historical_gap' => PortfolioLoggerService::CHANNEL_PROVIDER,
            'scheduler' => PortfolioLoggerService::CHANNEL_SCHEDULER,
            default => PortfolioLoggerService::CHANNEL_APP,
        };

        $this->logger->log($channel, $category, $level, $message, $context);
    }
}
