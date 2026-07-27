<?php

namespace App\Services\Notification;

use App\Models\PortfolioProfile;
use App\Models\TradingRecommendation;

/**
 * Owns notification message text formatting (recommendation + alert channels).
 * Dispatch (queueing, idempotency, Telegram send, status updates) stays with the
 * calling engine/service (TD-005: separate composition from dispatch).
 */
class NotificationMessageComposer
{
    public function recommendationMessage(TradingRecommendation $rec): string
    {
        $symbol = $rec->security?->symbol ?? '#'.$rec->security_id;
        $lines = [
            'Lido Trading OS recommendation',
            sprintf('%s %s (priority %d)', $rec->recommendation_type, $symbol, $rec->priority),
            sprintf('Confidence: %.0f%% | Risk: %s', ((float) $rec->confidence) * 100, $rec->risk_level),
        ];
        if ($rec->suggested_position_size) {
            $lines[] = sprintf('Suggested size: ₹%s', number_format((float) $rec->suggested_position_size, 0));
        }
        $passed = $rec->evidence['passed_rules'] ?? [];
        if ($passed) {
            $lines[] = 'Passed: '.implode(', ', array_slice($passed, 0, 5));
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $alerts
     */
    public function alertsMessage(array $alerts): string
    {
        $lines = ['Portfolio alerts ('.count($alerts).')'];

        foreach ($alerts as $alert) {
            $symbol = $alert['stock']['symbol'] ?? $alert['stock']['name'] ?? 'Unknown';
            $message = trim((string) ($alert['message'] ?? ''));
            $lines[] = $message !== '' ? "• {$symbol}: {$message}" : "• {$symbol}";
        }

        return implode("\n", $lines);
    }

    public function clearPingMessage(PortfolioProfile $profile, ?string $atTime = null): string
    {
        $name = trim((string) $profile->name) !== '' ? $profile->name : 'Portfolio';
        $timeLabel = $atTime ? " (scheduled check at {$atTime})" : '';

        return "✅ Lido Portfolio — {$name}: No active alerts{$timeLabel}.\n\n"
            .'Scheduled notification check — cron is working. Disable “Ping Telegram when clear” in Settings → Global when done testing.';
    }
}
