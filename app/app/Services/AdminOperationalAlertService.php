<?php

namespace App\Services;

use App\Models\OperationalAlert;
use App\Models\SyncRun;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdminOperationalAlertService
{
    public const KEY_PROVIDER_RATE_LIMIT = 'provider_rate_limit';

    public const KEY_DAILY_SYNC_OVERDUE = 'daily_sync_overdue';

    public const KEY_DAILY_SYNC_FAILED = 'daily_sync_failed';

    public const KEY_UNIVERSE_SYNC_OVERDUE = 'universe_sync_overdue';

    public const KEY_UNIVERSE_SYNC_FAILED = 'universe_sync_failed';

    public const KEY_STOCK_MASTER_OVERDUE = 'stock_master_overdue';

    public const KEY_STOCK_MASTER_FAILED = 'stock_master_failed';

    public const KEY_SCHEDULER_INACTIVE = 'scheduler_inactive';

    public const KEY_DECISION_PIPELINE_FAILED = 'decision_pipeline_failed';

    public const KEY_BROKER_RECONCILE_FAILED = 'broker_reconcile_failed';

    public const KEY_AUTOMATIC_SUBMIT_FAILED = 'automatic_submit_failed';

    public const SETTING_UNATTENDED_FAILURES = 'unattended_ops_failures';

    /** @var list<string> */
    public const UNATTENDED_FAILURE_KEYS = [
        self::KEY_DECISION_PIPELINE_FAILED,
        self::KEY_BROKER_RECONCILE_FAILED,
        self::KEY_AUTOMATIC_SUBMIT_FAILED,
    ];

    public function __construct(
        protected SyncLogService $syncLog,
        protected SettingsService $settings,
        protected UniversePriceSyncService $universeSync,
        protected TelegramNotificationService $telegram,
        protected PortfolioLoggerService $logger,
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
    public function evaluateConditions(): array
    {
        if (! Schema::hasTable('portfolio_operational_alerts')) {
            return [];
        }

        $alerts = [];
        $timezone = $this->timezone();
        $now = now()->timezone($timezone);

        if ($this->universeSync->isEnabled()) {
            $status = $this->universeSync->status();
            $lastRun = $status['last_run'] ?? null;
            $recentIssues = $status['rate_limits']['recent_issues'] ?? [];

            if ($this->universeSync->isLikelyRateLimitedPublic($lastRun, $recentIssues)) {
                $hits = (int) ($lastRun['rate_limit_hits'] ?? 0);
                $alerts[] = $this->alert(
                    self::KEY_PROVIDER_RATE_LIMIT,
                    'warning',
                    'Provider rate limits detected',
                    'Universe price sync is hitting provider rate limits. Increase UNIVERSE_PRICE_SYNC_DELAY_MS, use smaller batches, or wait before retrying.',
                    [
                        'last_run_rate_limit_hits' => $hits,
                        'last_run_failure_rate_percent' => $lastRun['failure_rate_percent'] ?? null,
                    ],
                );
            }

            $universeRun = $this->syncLog->latestFinishedRun(SyncLogService::JOB_UNIVERSE_PRICE_SYNC);
            if ($universeRun && $universeRun->status === 'failed') {
                $alerts[] = $this->alert(
                    self::KEY_UNIVERSE_SYNC_FAILED,
                    'critical',
                    'Universe sync batch failed',
                    $this->formatRunFailureMessage('Universe price sync', $universeRun),
                    $this->runContext($universeRun),
                );
            } elseif ($lastRun && ($lastRun['failed'] ?? 0) > 0 && ($lastRun['succeeded'] ?? 0) === 0) {
                $alerts[] = $this->alert(
                    self::KEY_UNIVERSE_SYNC_FAILED,
                    'critical',
                    'Universe sync batch failed',
                    sprintf(
                        'Latest universe batch processed %d stock(s) with %d failure(s) and no successes.',
                        (int) ($lastRun['processed'] ?? 0),
                        (int) ($lastRun['failed'] ?? 0),
                    ),
                    [
                        'completed_at' => $lastRun['completed_at'] ?? null,
                        'rate_limit_hits' => $lastRun['rate_limit_hits'] ?? 0,
                    ],
                );
            }

            $inMaintenanceWindow = $this->isUniverseMaintenanceWindow($now);
            $overdueMinutes = $inMaintenanceWindow
                ? (int) config('portfolio.operational_alerts.universe_sync_stale_minutes_maintenance', 45)
                : ((int) config('portfolio.operational_alerts.universe_sync_stale_hours', 26) * 60);

            $maintenanceWindowStart = $inMaintenanceWindow
                ? $now->copy()->setTime($this->universeSync->maintenanceStartHour(), 0, 0)
                : null;
            $lastUniverseAt = $this->latestUniverseActivityAt($universeRun, $lastRun);
            $lastUniverseInWindow = $maintenanceWindowStart !== null
                ? $this->latestUniverseActivityAt($universeRun, $lastRun, $maintenanceWindowStart)
                : null;
            $stalenessReferenceAt = $inMaintenanceWindow
                ? ($lastUniverseInWindow ?? $maintenanceWindowStart)
                : $lastUniverseAt;

            if (
                ! $this->shouldSuppressUniverseOverdueOnClosedSession($now)
                && ($stalenessReferenceAt === null || $stalenessReferenceAt->lt($now->copy()->subMinutes($overdueMinutes)))
            ) {
                $alerts[] = $this->alert(
                    self::KEY_UNIVERSE_SYNC_OVERDUE,
                    'warning',
                    'Universe sync overdue',
                    $this->formatUniverseOverdueMessage(
                        $timezone,
                        $now,
                        $lastUniverseAt,
                        $lastUniverseInWindow,
                        $maintenanceWindowStart,
                        $inMaintenanceWindow,
                    ),
                    [
                        'last_activity_at' => $lastUniverseAt?->toIso8601String(),
                        'last_activity_in_window_at' => $lastUniverseInWindow?->toIso8601String(),
                        'maintenance_window_start_at' => $maintenanceWindowStart?->toIso8601String(),
                        'threshold_minutes' => $overdueMinutes,
                        'maintenance_window' => $inMaintenanceWindow,
                    ],
                );
            }
        }

        $dailyRun = $this->syncLog->latestRun(SyncLogService::JOB_DAILY_MARKET_DATA);
        if ($dailyRun && in_array($dailyRun->status, ['failed', 'partial'], true)) {
            $failures = (int) ($dailyRun->failures ?? 0);
            if ($dailyRun->status === 'failed' || $failures > 0) {
                $alerts[] = $this->alert(
                    self::KEY_DAILY_SYNC_FAILED,
                    $dailyRun->status === 'failed' ? 'critical' : 'warning',
                    'Daily market sync failed',
                    $this->formatRunFailureMessage('Daily market sync', $dailyRun),
                    $this->runContext($dailyRun),
                );
            }
        }

        $dailySuccessAt = $this->syncLog->latestSuccessfulFinishedAt(SyncLogService::JOB_DAILY_MARKET_DATA);
        $dailyStaleHours = (int) config('portfolio.operational_alerts.daily_sync_stale_hours', 36);
        if (
            ! $this->shouldSuppressDailyOverdueOnClosedSession($now)
            && ($dailySuccessAt === null || $dailySuccessAt->lt($now->copy()->subHours($dailyStaleHours)))
        ) {
            $alerts[] = $this->alert(
                self::KEY_DAILY_SYNC_OVERDUE,
                'warning',
                'Daily market sync overdue',
                $dailySuccessAt === null
                    ? 'No successful daily market sync has been recorded.'
                    : sprintf(
                        'Last successful daily sync finished %s (%s).',
                        $dailySuccessAt->timezone($timezone)->diffForHumans(),
                        $dailySuccessAt->timezone($timezone)->toDateTimeString(),
                    ),
                [
                    'last_success_at' => $dailySuccessAt?->toIso8601String(),
                    'threshold_hours' => $dailyStaleHours,
                ],
            );
        }

        $stockMasterRun = $this->syncLog->latestRun(SyncLogService::JOB_STOCK_MASTER);
        if ($stockMasterRun && $stockMasterRun->status === 'failed') {
            $alerts[] = $this->alert(
                self::KEY_STOCK_MASTER_FAILED,
                'critical',
                'Stock master sync failed',
                $this->formatRunFailureMessage('Weekly stock master sync', $stockMasterRun),
                $this->runContext($stockMasterRun),
            );
        }

        $stockMasterSuccessAt = $this->syncLog->latestSuccessfulFinishedAt(SyncLogService::JOB_STOCK_MASTER);
        $stockMasterStaleDays = (int) config('portfolio.operational_alerts.stock_master_stale_days', 8);
        if ($stockMasterSuccessAt === null || $stockMasterSuccessAt->lt($now->copy()->subDays($stockMasterStaleDays))) {
            $alerts[] = $this->alert(
                self::KEY_STOCK_MASTER_OVERDUE,
                'warning',
                'Stock master sync overdue',
                $stockMasterSuccessAt === null
                    ? 'No successful stock master sync has been recorded.'
                    : sprintf(
                        'Last successful stock master sync finished %s (%s). Weekly job may have been missed.',
                        $stockMasterSuccessAt->timezone($timezone)->diffForHumans(),
                        $stockMasterSuccessAt->timezone($timezone)->toDateTimeString(),
                    ),
                [
                    'last_success_at' => $stockMasterSuccessAt?->toIso8601String(),
                    'threshold_days' => $stockMasterStaleDays,
                ],
            );
        }

        $schedulerDeadHours = (int) config('portfolio.operational_alerts.scheduler_dead_hours', 48);
        $lastActivity = $this->syncLog->latestActivityAt();
        if ($lastActivity === null || $lastActivity->lt($now->copy()->subHours($schedulerDeadHours))) {
            $alerts[] = $this->alert(
                self::KEY_SCHEDULER_INACTIVE,
                'critical',
                'Scheduler appears inactive',
                $lastActivity === null
                    ? 'No sync job activity found. Verify cPanel cron runs `php artisan schedule:run` every minute.'
                    : sprintf(
                        'No sync job has run since %s (%s). cPanel cron may be down.',
                        $lastActivity->timezone($timezone)->diffForHumans(),
                        $lastActivity->timezone($timezone)->toDateTimeString(),
                    ),
                [
                    'last_activity_at' => $lastActivity?->toIso8601String(),
                    'threshold_hours' => $schedulerDeadHours,
                ],
            );
        }

        foreach ($this->unattendedFailures() as $key => $row) {
            if (! in_array($key, self::UNATTENDED_FAILURE_KEYS, true) || ! is_array($row)) {
                continue;
            }
            $alerts[] = $this->alert(
                $key,
                (string) ($row['severity'] ?? 'critical'),
                (string) ($row['title'] ?? 'Unattended operation failed'),
                (string) ($row['message'] ?? 'An unattended operation failed.'),
                is_array($row['context'] ?? null) ? $row['context'] : [],
            );
        }

        return $alerts;
    }

    /**
     * @return array{
     *   active: list<array<string, mixed>>,
     *   notified: list<string>,
     *   resolved: list<string>
     * }
     */
    public function syncAndNotify(bool $allowClearPing = false): array
    {
        $result = $this->syncActiveAlerts(true);

        if ($allowClearPing && $this->isClearPingEnabled() && $result['active'] === []) {
            if ($this->notifyAllClear()) {
                $result['notified'][] = '_all_clear';
            }
        }

        return $result;
    }

    public function isClearPingEnabled(): bool
    {
        return $this->settings->isTelegramPingWhenClearEnabled();
    }

    protected function notifyAllClear(): bool
    {
        $message = "✅ Lido Portfolio: No active operational alerts.\n\n"
            .'Confirmation ping (admin ops “ping when clear” is enabled). Turn it off in Settings → Global when done testing.';

        $sent = $this->telegram->sendAdminOperationalAlert($message);
        if ($sent['sent']) {
            $this->logger->scheduler('info', 'Operational alerts all-clear Telegram sent', [
                'category' => 'OperationalAlert',
                'recipients' => $sent['recipients'],
            ]);
        }

        return $sent['sent'];
    }

    /**
     * @return array{
     *   active: list<array<string, mixed>>,
     *   notified: list<string>,
     *   resolved: list<string>
     * }
     */
    public function syncActiveAlerts(bool $notify = false): array
    {
        $evaluated = collect($this->evaluateConditions())->keyBy('key');
        $activeKeys = $evaluated->keys()->all();
        $notified = [];
        $resolved = [];

        OperationalAlert::query()
            ->whereNull('resolved_at')
            ->whereNotIn('alert_key', $activeKeys)
            ->get()
            ->each(function (OperationalAlert $row) use (&$resolved) {
                $row->resolved_at = now();
                $row->save();
                $resolved[] = $row->alert_key;
            });

        if ($this->supportsManualClearColumn()) {
            OperationalAlert::query()
                ->whereNotIn('alert_key', $activeKeys)
                ->whereNotNull('manually_cleared_at')
                ->update(['manually_cleared_at' => null]);
        }

        $cooldownHours = (int) config('portfolio.operational_alerts.telegram_cooldown_hours', 6);
        $cooldownCutoff = now()->subHours($cooldownHours);

        foreach ($evaluated as $key => $definition) {
            $row = OperationalAlert::query()->find($key);
            if ($this->supportsManualClearColumn() && $row?->manually_cleared_at !== null) {
                continue;
            }

            $isNew = $row === null;
            $wasResolved = $row !== null && $row->resolved_at !== null;

            if ($isNew) {
                $row = new OperationalAlert(['alert_key' => $key]);
                $row->first_triggered_at = now();
            } elseif ($wasResolved) {
                $row->resolved_at = null;
                $row->acknowledged_at = null;
            }

            $row->severity = $definition['severity'];
            $row->title = $definition['title'];
            $row->message = $definition['message'];
            $row->context = $definition['context'];
            $row->last_triggered_at = now();
            $row->save();

            $shouldNotify = $notify && ($isNew
                || $wasResolved
                || $row->last_telegram_at === null
                || $row->last_telegram_at->lt($cooldownCutoff));

            if ($shouldNotify) {
                $sent = $this->telegram->sendAdminOperationalAlert($this->formatTelegramMessage($definition));
                if ($sent['sent']) {
                    $row->last_telegram_at = now();
                    $row->save();
                    $notified[] = $key;
                }
            }
        }

        if ($notified !== [] || $resolved !== []) {
            $this->logger->scheduler('info', 'Operational alerts synced', [
                'category' => 'OperationalAlert',
                'notified' => $notified,
                'resolved' => $resolved,
                'active' => $activeKeys,
            ]);
        }

        return [
            'active' => $this->getActiveAlertsForApi(),
            'notified' => $notified,
            'resolved' => $resolved,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getActiveAlertsForApi(bool $includeAcknowledged = true): array
    {
        if (! Schema::hasTable('portfolio_operational_alerts')) {
            return [];
        }

        $query = OperationalAlert::query()
            ->whereNull('resolved_at')
            ->orderByDesc('last_triggered_at');

        if (! $includeAcknowledged) {
            $query->whereNull('acknowledged_at');
        }

        return $query->get()
            ->map(fn (OperationalAlert $row) => $this->formatAlertRow($row))
            ->values()
            ->all();
    }

    public function acknowledge(string $alertKey): bool
    {
        if (! Schema::hasTable('portfolio_operational_alerts')) {
            return false;
        }

        $row = OperationalAlert::query()
            ->where('alert_key', $alertKey)
            ->whereNull('resolved_at')
            ->first();

        if ($row === null) {
            return false;
        }

        $row->acknowledged_at = now();
        $row->save();

        return true;
    }

    public function acknowledgeAll(): int
    {
        if (! Schema::hasTable('portfolio_operational_alerts')) {
            return 0;
        }

        return OperationalAlert::query()
            ->whereNull('resolved_at')
            ->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => now()]);
    }

    public function clearManually(string $alertKey): bool
    {
        if (! Schema::hasTable('portfolio_operational_alerts')) {
            return false;
        }

        $row = OperationalAlert::query()
            ->where('alert_key', $alertKey)
            ->whereNull('resolved_at')
            ->first();

        if ($row === null) {
            return false;
        }

        $now = now();
        $row->resolved_at = $now;
        if ($this->supportsManualClearColumn()) {
            $row->manually_cleared_at = $now;
        }
        if ($row->acknowledged_at === null) {
            $row->acknowledged_at = $now;
        }
        $row->save();

        return true;
    }

    public function clearDismissedManually(): int
    {
        if (! Schema::hasTable('portfolio_operational_alerts')) {
            return 0;
        }

        $now = now();
        $payload = ['resolved_at' => $now];
        if ($this->supportsManualClearColumn()) {
            $payload['manually_cleared_at'] = $now;
        }

        return OperationalAlert::query()
            ->whereNull('resolved_at')
            ->whereNotNull('acknowledged_at')
            ->update($payload);
    }

    protected function supportsManualClearColumn(): bool
    {
        return Schema::hasTable('portfolio_operational_alerts')
            && Schema::hasColumn('portfolio_operational_alerts', 'manually_cleared_at');
    }

    public function adminTelegramRecipientCount(): int
    {
        return $this->telegram->countAdminTelegramRecipients();
    }

    /**
     * Persist a durable unattended-ops failure so hourly evaluation and
     * immediate syncAndNotify() keep in-app + Telegram visibility.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordUnattendedFailure(string $key, string $title, string $message, array $context = []): void
    {
        if (! in_array($key, self::UNATTENDED_FAILURE_KEYS, true)) {
            return;
        }

        $failures = $this->unattendedFailures();
        $failures[$key] = [
            'severity' => 'critical',
            'title' => $title,
            'message' => $this->sanitizeOpsMessage($message),
            'context' => $this->sanitizeOpsContext($context),
            'recorded_at' => now()->toIso8601String(),
        ];
        $this->storeUnattendedFailures($failures);
    }

    public function clearUnattendedFailure(string $key): bool
    {
        $failures = $this->unattendedFailures();
        if (! isset($failures[$key])) {
            return false;
        }

        unset($failures[$key]);
        $this->storeUnattendedFailures($failures);

        return true;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function unattendedFailures(): array
    {
        $raw = $this->settings->get(self::SETTING_UNATTENDED_FAILURES, '');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $key => $row) {
            if (is_string($key) && is_array($row) && in_array($key, self::UNATTENDED_FAILURE_KEYS, true)) {
                $out[$key] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $failures
     */
    protected function storeUnattendedFailures(array $failures): void
    {
        \App\Models\Setting::setValue(
            self::SETTING_UNATTENDED_FAILURES,
            $failures === [] ? null : json_encode($failures),
        );
    }

    protected function sanitizeOpsMessage(string $message): string
    {
        $message = preg_replace(
            '/(?i)(token|secret|password|totp|otp|authorization|api[_-]?key|access_token|request.?body)[^\s]*/',
            '[redacted]',
            $message,
        ) ?? $message;
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return Str::limit(trim($message), 400);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, scalar|null>
     */
    protected function sanitizeOpsContext(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            if (! is_string($key) || preg_match('/token|secret|password|totp|otp|authorization|credential|request.?body/i', $key)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function formatTelegramMessage(array $definition): string
    {
        $severity = strtoupper((string) ($definition['severity'] ?? 'warning'));

        $review = match ($definition['key'] ?? '') {
            self::KEY_DECISION_PIPELINE_FAILED,
            self::KEY_BROKER_RECONCILE_FAILED,
            self::KEY_AUTOMATIC_SUBMIT_FAILED => 'Review: Settings → Admin operational alerts',
            default => 'Review: Settings → Universe price sync',
        };

        return implode("\n", [
            'Lido Portfolio — ops alert',
            "[{$severity}] {$definition['title']}",
            (string) $definition['message'],
            $review,
        ]);
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
    protected function alert(string $key, string $severity, string $title, string $message, array $context = []): array
    {
        return [
            'key' => $key,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'context' => $context,
        ];
    }

    protected function timezone(): string
    {
        return $this->settings->get('cron_timezone', 'Asia/Kolkata') ?? 'Asia/Kolkata';
    }

    protected function isUniverseMaintenanceWindow(Carbon $now): bool
    {
        return $this->universeSync->isWithinMaintenanceHours($now)
            && $this->universeSync->allowsMaintenanceOnCalendarDay($now);
    }

    /**
     * Weekend/holiday universe overdue is a false alarm when maintenance is
     * intentionally skipped because the prior session's last batch succeeded.
     */
    protected function shouldSuppressUniverseOverdueOnClosedSession(Carbon $now): bool
    {
        return ! $this->universeSync->allowsMaintenanceOnCalendarDay($now);
    }

    /**
     * Daily holdings sync does not run on closed sessions. Skip overdue when
     * the prior equity session's daily run succeeded (typically Friday).
     */
    protected function shouldSuppressDailyOverdueOnClosedSession(Carbon $now): bool
    {
        if (TradingCalendar::isScheduledMarketDataDay($now, $this->timezone())) {
            return false;
        }

        return $this->priorEquitySessionDailySyncSucceeded($now);
    }

    protected function priorEquitySessionDailySyncSucceeded(Carbon $now): bool
    {
        if (! Schema::hasTable('portfolio_sync_runs')) {
            return false;
        }

        $priorSession = TradingCalendar::normalizeToSessionDate($now->copy()->subDay());
        $windowStart = $priorSession->copy()->startOfDay()->utc();
        $windowEnd = $priorSession->copy()->endOfDay()->utc();

        $lastRun = SyncRun::query()
            ->where('job_name', SyncLogService::JOB_DAILY_MARKET_DATA)
            ->whereNotNull('finished_at')
            ->where('finished_at', '>=', $windowStart)
            ->where('finished_at', '<=', $windowEnd)
            ->orderByDesc('finished_at')
            ->first();

        return $lastRun !== null && $lastRun->status === 'success';
    }

    /**
     * @param  array<string, mixed>|null  $lastRun
     */
    protected function latestUniverseActivityAt(
        ?SyncRun $universeRun,
        ?array $lastRun,
        ?Carbon $since = null,
    ): ?Carbon {
        $candidates = [];

        // Prefer finished_at — orphan "running" rows must not look like healthy activity.
        if ($universeRun?->finished_at) {
            $candidates[] = $universeRun->finished_at;
        }

        $completedAt = $lastRun['completed_at'] ?? null;
        if (is_string($completedAt) && $completedAt !== '') {
            $candidates[] = Carbon::parse($completedAt);
        }

        if ($since !== null) {
            $candidates = array_values(array_filter(
                $candidates,
                fn (Carbon $candidate) => $candidate->gte($since),
            ));
        }

        if ($candidates === []) {
            return null;
        }

        return collect($candidates)->sortDesc()->first();
    }

    protected function formatUniverseOverdueMessage(
        string $timezone,
        Carbon $now,
        ?Carbon $lastUniverseAt,
        ?Carbon $lastUniverseInWindow,
        ?Carbon $maintenanceWindowStart,
        bool $inMaintenanceWindow,
    ): string {
        if ($lastUniverseAt === null) {
            return 'No universe price sync run has been recorded yet.';
        }

        if ($inMaintenanceWindow && $maintenanceWindowStart !== null) {
            if ($lastUniverseInWindow !== null) {
                return sprintf(
                    'Last universe sync in today\'s maintenance window was %s (%s).',
                    $lastUniverseInWindow->timezone($timezone)->diffForHumans($now),
                    $lastUniverseInWindow->timezone($timezone)->toDateTimeString(),
                );
            }

            return sprintf(
                'No universe price sync run since today\'s maintenance window opened at %s (last activity overall was %s, %s).',
                $maintenanceWindowStart->timezone($timezone)->format('H:i'),
                $lastUniverseAt->timezone($timezone)->diffForHumans($now),
                $lastUniverseAt->timezone($timezone)->toDateTimeString(),
            );
        }

        return sprintf(
            'Last universe sync activity was %s (%s).',
            $lastUniverseAt->timezone($timezone)->diffForHumans($now),
            $lastUniverseAt->timezone($timezone)->toDateTimeString(),
        );
    }

    protected function formatRunFailureMessage(string $label, SyncRun $run): string
    {
        $summary = trim((string) ($run->summary ?? ''));
        if ($summary !== '') {
            return "{$label}: {$summary}";
        }

        return sprintf(
            '%s finished with status %s at %s.',
            $label,
            $run->status,
            $run->finished_at?->toDateTimeString() ?? 'unknown time',
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function runContext(SyncRun $run): array
    {
        return [
            'run_id' => $run->id,
            'status' => $run->status,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'failures' => $run->failures,
            'summary' => $run->summary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatAlertRow(OperationalAlert $row): array
    {
        return [
            'key' => $row->alert_key,
            'severity' => $row->severity,
            'title' => $row->title,
            'message' => $row->message,
            'context' => $row->context ?? [],
            'first_triggered_at' => $row->first_triggered_at?->toIso8601String(),
            'last_triggered_at' => $row->last_triggered_at?->toIso8601String(),
            'last_telegram_at' => $row->last_telegram_at?->toIso8601String(),
            'acknowledged_at' => $row->acknowledged_at?->toIso8601String(),
            'acknowledged' => $row->acknowledged_at !== null,
        ];
    }
}
