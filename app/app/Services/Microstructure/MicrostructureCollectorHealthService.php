<?php

namespace App\Services\Microstructure;

use App\Models\MicrostructureCollectorState;
use App\Support\TradingCalendar;
use Carbon\Carbon;

class MicrostructureCollectorHealthService
{
    public const ALERT_KEY_STALE = 'microstructure_collector_stale';

    public const ALERT_KEY_ERROR = 'microstructure_collector_error';

    public const ALERT_KEY_DISK_LOW = 'microstructure_collector_disk_low';

    public const ALERT_KEY_FINALIZATION_FAILED = 'microstructure_collector_finalization_failed';

    public const ALERT_KEY_BACKUP_FAILED = 'microstructure_collector_backup_failed';

    public const ALERT_KEY_COVERAGE_LOW = 'microstructure_collector_coverage_low';

    public function __construct(
        protected MicrostructureCollectorControlService $control,
    ) {}

    /**
     * @return list<array{
     *   key: string,
     *   severity: string,
     *   title: string,
     *   message: string,
     *   context: array<string, mixed>
     * }>
     */
    public function evaluateOperationalAlerts(): array
    {
        if (! $this->control->isEnabled()) {
            return [];
        }

        $status = $this->control->operationalStatus();
        $alerts = [];

        $diskFree = $status['disk_free_gb'] ?? null;
        $diskWarning = (float) ($status['disk_free_warning_gb'] ?? 5);
        if ($diskFree !== null && $diskFree < $diskWarning) {
            $alerts[] = $this->alert(
                self::ALERT_KEY_DISK_LOW,
                'warning',
                'Microstructure collector storage low',
                sprintf(
                    'Free space under the microstructure data root is %.2f GB (warning threshold %.2f GB).',
                    $diskFree,
                    $diskWarning,
                ),
                [
                    'disk_free_gb' => $diskFree,
                    'threshold_gb' => $diskWarning,
                ],
            );
        }

        $latestError = trim((string) ($status['latest_error'] ?? ''));
        if ($latestError !== '') {
            $alerts[] = $this->alert(
                self::ALERT_KEY_ERROR,
                'critical',
                'Microstructure collector error',
                $latestError,
                [
                    'collector_state' => $status['collector_state'] ?? null,
                    'last_packet_at' => $status['last_packet_at'] ?? null,
                ],
            );
        }

        $finalization = is_array($status['finalization'] ?? null) ? $status['finalization'] : [];
        if (($finalization['status'] ?? null) === 'finalization_failed') {
            $alerts[] = $this->alert(
                self::ALERT_KEY_FINALIZATION_FAILED,
                'critical',
                'Microstructure finalization failed',
                (string) ($finalization['last_error'] ?? 'The latest trading-day partition could not be finalized.'),
                ['trading_day' => $finalization['trading_day'] ?? null, 'attempts' => $finalization['finalization_attempts'] ?? null],
            );
        }
        if (($finalization['backup_status'] ?? null) === 'backup_failed') {
            $alerts[] = $this->alert(
                self::ALERT_KEY_BACKUP_FAILED,
                'critical',
                'Microstructure backup failed',
                (string) ($finalization['backup_error'] ?? 'The finalized partition backup failed.'),
                ['trading_day' => $finalization['trading_day'] ?? null],
            );
        }

        $coverage = is_array($status['coverage_summary'] ?? null) ? $status['coverage_summary'] : [];
        $coveragePercent = is_numeric($coverage['coverage_percent'] ?? null) ? (float) $coverage['coverage_percent'] : null;
        $coverageThreshold = (float) config('microstructure_collector.coverage_warning_percent', 90);
        if (($status['session_phase'] ?? null) === 'post_market'
            && $coveragePercent !== null
            && $coveragePercent < $coverageThreshold
            && (int) ($coverage['expected_instrument_minutes'] ?? 0) > 0) {
            $alerts[] = $this->alert(
                self::ALERT_KEY_COVERAGE_LOW,
                'warning',
                'Microstructure coverage is low',
                sprintf('The finalized trading-day collector coverage is %.2f%%, below the %.2f%% warning threshold.', $coveragePercent, $coverageThreshold),
                [
                    'trading_day' => $coverage['trading_day'] ?? null,
                    'coverage_percent' => $coveragePercent,
                    'threshold_percent' => $coverageThreshold,
                    'quality_counts' => $coverage['quality_counts'] ?? [],
                ],
            );
        }

        $state = MicrostructureCollectorState::current();
        if ($state->manual_hold) {
            return $alerts;
        }

        if (! $this->isIntradayCollectionWindow()) {
            return $alerts;
        }

        $staleMinutes = max(2, (int) config('microstructure_collector.heartbeat_stale_minutes', 5));
        $lastSignalAt = $this->resolveLastSignalAt($status);
        if ($lastSignalAt === null || $lastSignalAt->lt(now()->subMinutes($staleMinutes))) {
            $alerts[] = $this->alert(
                self::ALERT_KEY_STALE,
                'critical',
                'Microstructure collector heartbeat stale',
                $lastSignalAt === null
                    ? 'No collector heartbeat has been recorded during the current market session.'
                    : sprintf(
                        'Last collector activity was %s (%s). Expected updates within %d minutes during market hours.',
                        $lastSignalAt->timezone($this->timezone())->diffForHumans(),
                        $lastSignalAt->timezone($this->timezone())->toDateTimeString(),
                        $staleMinutes,
                    ),
                [
                    'last_signal_at' => $lastSignalAt?->toIso8601String(),
                    'threshold_minutes' => $staleMinutes,
                    'websocket_connected' => $status['websocket_connected'] ?? null,
                    'collector_state' => $status['collector_state'] ?? null,
                ],
            );
        }

        return $alerts;
    }

    protected function isIntradayCollectionWindow(): bool
    {
        $tz = $this->timezone();
        $now = now()->timezone($tz);

        if (! TradingCalendar::isEquitySessionDate($now)) {
            return false;
        }

        $open = $now->copy()->setTime(9, 15, 0);
        $close = $now->copy()->setTime(15, 35, 0);

        return $now->betweenIncluded($open, $close);
    }

    protected function timezone(): string
    {
        return (string) config('microstructure_collector.market_timezone', 'Asia/Kolkata');
    }

    /**
     * @param  array<string, mixed>  $status
     */
    protected function resolveLastSignalAt(array $status): ?Carbon
    {
        // Use collector-reported packet time only — not Laravel read time (`heartbeat_received_at`).
        $candidates = [
            $status['last_packet_at'] ?? null,
        ];

        $parsed = [];
        foreach ($candidates as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            try {
                $parsed[] = Carbon::parse($value);
            } catch (\Throwable) {
                continue;
            }
        }

        if ($parsed === []) {
            return null;
        }

        return collect($parsed)->sortDesc()->first();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *   key: string,
     *   severity: string,
     *   title: string,
     *   message: string,
     *   context: array<string, mixed>
     * }
     */
    protected function alert(string $key, string $severity, string $title, string $message, array $context): array
    {
        return [
            'key' => $key,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'context' => $context,
        ];
    }
}
