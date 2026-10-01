<?php

namespace App\Services;

use App\Models\ForwardCollectionWork;
use App\Models\ForwardCollectionControl;
use App\Models\V8\MlUniverseSnapshotBoundary;
use App\Services\ML\MlHistoricalUniverseMembershipService;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Support\Str;

/** Plans/claims forward work; owner engines remain responsible for domain writes. */
class ForwardDataPlanner
{
    public const DATASET_NSE_MEMBERSHIP = 'official_nse_membership';

    public const STATE_WAITING_PUBLICATION = 'waiting_publication';

    public function plan(?Carbon $asOf = null): array
    {
        if (! config('forward_data.enabled', true)) {
            return ['status' => 'disabled', 'created' => 0, 'blocked' => 0];
        }
        $start = config('forward_data.start_date');
        if (! is_string($start) || trim($start) === '') {
            return ['status' => 'blocked_configuration', 'created' => 0, 'blocked' => 0, 'reason' => 'forward_start_date_not_configured'];
        }
        if (! $this->officialSourceConfigured()) {
            return ['status' => 'blocked_configuration', 'created' => 0, 'blocked' => 0, 'reason' => 'official_nse_source_not_configured'];
        }
        $asOf ??= now(config('forward_data.timezone', 'Asia/Kolkata'));
        $last = TradingCalendar::lastRequiredPriceSession($asOf);
        $created = 0;
        $blocked = 0;
        for ($date = Carbon::parse($start); $date->lte($last); $date->addDay()) {
            if (! TradingCalendar::isEquitySessionDate($date)) continue;
            $boundary = MlUniverseSnapshotBoundary::query()->where('universe_key', MlHistoricalUniverseMembershipService::ACTIVE_ELIGIBLE_NSE)->whereDate('effective_from', $date)->exists();
            $work = ForwardCollectionWork::query()->firstOrCreate([
                'dataset_key' => self::DATASET_NSE_MEMBERSHIP,
                'exchange' => 'NSE',
                'session_date' => $date->toDateString(),
                'scope_key' => MlHistoricalUniverseMembershipService::ACTIVE_ELIGIBLE_NSE,
            ], [
                'state' => $boundary ? 'succeeded' : self::STATE_WAITING_PUBLICATION,
                'next_attempt_at' => $boundary ? null : now()->addHours((int) config('forward_data.publication_grace_hours', 21)),
                'last_successful_at' => $boundary ? now() : null,
            ]);
            if ($work->wasRecentlyCreated) $created++;
            if ($boundary && $work->state !== 'succeeded') {
                $work->forceFill(['state' => 'succeeded', 'next_attempt_at' => null, 'last_successful_at' => now(), 'last_error' => null, 'last_error_code' => null])->save();
            }
            if (! $boundary && $work->state === 'succeeded') {
                $work->forceFill(['state' => self::STATE_WAITING_PUBLICATION, 'last_successful_at' => null])->save();
                $blocked++;
            }
        }
        return ['status' => 'planned', 'created' => $created, 'blocked' => $blocked];
    }

    public function officialSourceConfigured(): bool
    {
        $local = trim((string) config('ml.historical_universe.mii_path', '')) !== ''
            || trim((string) config('ml.historical_universe.bhavcopy_path', '')) !== '';
        $base = rtrim((string) config('forward_data.official_source_base_url', ''), '/');
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        $remote = (bool) config('forward_data.official_source_enabled', false)
            && $base !== ''
            && in_array($host, (array) config('forward_data.official_source_allowed_hosts', []), true);

        return $local || $remote;
    }

    /** @return list<ForwardCollectionWork> */
    public function claim(int $limit): array
    {
        if ($this->isPaused()) return [];
        $now = now();
        ForwardCollectionWork::query()->whereIn('state', ['running', 'retry_wait'])->where('lease_expires_at', '<', $now)->update(['state' => 'pending', 'lease_token' => null, 'lease_expires_at' => null]);
        $rows = ForwardCollectionWork::query()->whereIn('state', ['pending', self::STATE_WAITING_PUBLICATION, 'retry_wait'])->where(function ($query) use ($now): void {
            $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
        })->orderByRaw("CASE WHEN state = 'pending' THEN 0 ELSE 1 END")->orderBy('session_date')->limit(max(1, $limit))->get();
        $claimed = [];
        foreach ($rows as $row) {
            $token = Str::random(32);
            $updated = ForwardCollectionWork::query()->whereKey($row->id)->whereIn('state', ['pending', self::STATE_WAITING_PUBLICATION, 'retry_wait'])->update(['state' => 'running', 'lease_token' => $token, 'lease_expires_at' => now()->addMinutes((int) config('forward_data.lease_minutes', 20)), 'last_attempted_at' => now(), 'attempts' => $row->attempts + 1]);
            if ($updated === 1) $claimed[] = $row->fresh();
        }
        return $claimed;
    }

    public function isPaused(): bool
    {
        return (bool) (ForwardCollectionControl::query()->value('paused') ?? false);
    }

    public function setPaused(bool $paused, ?int $actorId = null): array
    {
        $control = ForwardCollectionControl::query()->first() ?? new ForwardCollectionControl;
        $control->forceFill(['paused' => $paused, 'changed_by' => $actorId, 'changed_at' => now()])->save();
        return ['paused' => $paused, 'changed_at' => $control->changed_at?->toIso8601String(), 'changed_by' => $actorId];
    }

    public function succeed(ForwardCollectionWork $work, string $leaseToken, array $evidence = []): bool
    {
        return ForwardCollectionWork::query()->whereKey($work->id)->where('state', 'running')->where('lease_token', $leaseToken)->where('lease_expires_at', '>', now())->update(['state' => 'succeeded', 'lease_token' => null, 'lease_expires_at' => null, 'last_successful_at' => now(), 'last_error' => null, 'last_error_code' => null, 'owner_evidence' => $evidence]) === 1;
    }

    public function fail(ForwardCollectionWork $work, string $leaseToken, string $state, string $code, string $message, ?Carbon $nextAttemptAt): bool
    {
        return ForwardCollectionWork::query()->whereKey($work->id)->where('state', 'running')->where('lease_token', $leaseToken)->where('lease_expires_at', '>', now())->update(['state' => $state, 'next_attempt_at' => $nextAttemptAt, 'lease_token' => null, 'lease_expires_at' => null, 'last_error_code' => $code, 'last_error' => substr($message, 0, 1000)]) === 1;
    }
}
