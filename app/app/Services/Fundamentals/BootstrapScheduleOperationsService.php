<?php

namespace App\Services\Fundamentals;

use App\Models\User;
use App\Models\V7\BootstrapPreset;
use App\Models\V7\BootstrapSchedule;
use App\Models\V7\BootstrapScheduleOccurrence;
use App\Services\Fundamentals\BootstrapLaunchResult;
use App\Services\Fundamentals\Scheduling\BootstrapScheduleLaunchAdapter;
use App\Services\Fundamentals\Scheduling\BootstrapEligibleStockResolver;
use App\Services\Fundamentals\Scheduling\BootstrapScheduleRecurrence;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class BootstrapScheduleOperationsService
{
    private const MAX_DUE_BATCH = 500;
    public function __construct(private BootstrapScheduleRecurrence $recurrence, private ?BootstrapEligibleStockResolver $stockResolver = null) {}

    public function presets(int $perPage = 25): LengthAwarePaginator
    {
        $page = BootstrapPreset::query()->withCount('schedules')->orderBy('name')->paginate(min(100, max(1, $perPage)));
        $page->getCollection()->transform(fn (BootstrapPreset $preset): array => ['id' => $preset->id, 'name' => $preset->name, 'revision' => $preset->revision, 'configuration' => $preset->configuration, 'schedules_count' => $preset->schedules_count, 'created_at' => $preset->created_at?->toIso8601String(), 'updated_at' => $preset->updated_at?->toIso8601String()]);
        return $page;
    }

    /** @return array<string,mixed> */
    public function presentPreset(BootstrapPreset $preset): array
    {
        return ['id' => $preset->id, 'name' => $preset->name, 'revision' => $preset->revision, 'configuration' => $preset->configuration, 'created_at' => $preset->created_at?->toIso8601String(), 'updated_at' => $preset->updated_at?->toIso8601String()];
    }

    public function savePreset(array $input, int $actorId, ?BootstrapPreset $preset = null): BootstrapPreset
    {
        $this->assertAdmin($actorId);
        $this->assertKnownKeys($input, ['name', 'configuration'], 'preset');
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) throw ValidationException::withMessages(['name' => 'Name is required and limited to 120 characters.']);
        $config = $this->configuration($input['configuration'] ?? []);
        return DB::transaction(function () use ($preset, $name, $config, $actorId): BootstrapPreset {
            if ($preset) $preset = BootstrapPreset::query()->whereKey($preset->id)->lockForUpdate()->firstOrFail();
            if (BootstrapPreset::query()->where('name', $name)->when($preset, fn ($q) => $q->where('id', '<>', $preset->id))->exists()) {
                throw ValidationException::withMessages(['name' => 'Preset names must be unique.']);
            }
            if (! $preset) return BootstrapPreset::query()->create(['name' => $name, 'revision' => 1, 'configuration' => $config, 'created_by_user_id' => $actorId, 'updated_by_user_id' => $actorId]);
            $preset->fill(['name' => $name, 'configuration' => $config, 'revision' => $preset->revision + 1, 'updated_by_user_id' => $actorId])->save();
            return $preset->fresh();
        });
    }

    public function clonePreset(BootstrapPreset $preset, string $name, int $actorId): BootstrapPreset
    {
        return $this->savePreset(['name' => $name, 'configuration' => $preset->configuration], $actorId);
    }

    public function deletePreset(BootstrapPreset $preset, int $actorId): void
    {
        $this->assertAdmin($actorId);
        DB::transaction(function () use ($preset): void {
            $locked = BootstrapPreset::query()->whereKey($preset->id)->lockForUpdate()->firstOrFail();
            if ($locked->schedules()->exists()) throw ValidationException::withMessages(['preset' => 'A preset referenced by any schedule cannot be deleted.']);
            $locked->delete();
        });
    }

    public function schedules(int $perPage = 25): LengthAwarePaginator
    {
        $page = BootstrapSchedule::query()->withCount('occurrences')->latest('id')->paginate(min(100, max(1, $perPage)));
        $page->getCollection()->transform(fn (BootstrapSchedule $schedule): array => $this->presentSchedule($schedule));
        return $page;
    }

    public function occurrences(BootstrapSchedule $schedule, int $perPage = 25): LengthAwarePaginator
    {
        $page = $schedule->occurrences()->orderByDesc('scheduled_at_utc')->paginate(min(100, max(1, $perPage)));
        $page->getCollection()->transform(fn (BootstrapScheduleOccurrence $occurrence): array => [
            'id' => $occurrence->id, 'generation' => $occurrence->generation, 'schedule_version' => $occurrence->schedule_version,
            'scheduled_at_utc' => $this->rawUtcInstant($occurrence->getRawOriginal('scheduled_at_utc')), 'scheduled_at_local' => $occurrence->getRawOriginal('scheduled_at_local'),
            'timezone' => $occurrence->timezone, 'preset_revision' => $occurrence->preset_revision,
            'status' => $occurrence->status, 'run_id' => $occurrence->run_id, 'delivered_at' => $occurrence->delivered_at?->toIso8601String(),
            'failure_code' => $occurrence->failure_code, 'failed_at' => $occurrence->failed_at?->toIso8601String(),
            'delivery_error' => $occurrence->last_delivery_error !== null,
        ]);
        return $page;
    }

    /** @return array<string,mixed> */
    public function presentSchedule(BootstrapSchedule $schedule): array
    {
        return ['id' => $schedule->id, 'name' => $schedule->name, 'preset_id' => $schedule->preset_id, 'preset_revision' => $schedule->preset_revision,
            'configuration' => $schedule->configuration_snapshot, 'version' => $schedule->version, 'generation' => $schedule->generation,
            'frequency' => $schedule->frequency, 'interval' => $schedule->interval, 'weekday' => $schedule->weekday, 'monthday' => $schedule->monthday,
            'local_time' => $schedule->local_time, 'timezone' => $schedule->timezone, 'starts_at_local' => $schedule->starts_at_local?->toDateTimeString(),
            'ends_on_local' => $schedule->ends_on_local?->toDateString(), 'max_occurrences' => $schedule->max_occurrences,
            'occurrences_created' => $schedule->occurrences_created, 'next_occurrence_at' => $this->rawUtcInstant($schedule->getRawOriginal('next_occurrence_at')),
            'enabled' => $schedule->enabled, 'occurrences_count' => $schedule->occurrences_count ?? null];
    }

    public function createSchedule(array $input, int $actorId): BootstrapSchedule
    {
        $this->assertAdmin($actorId);
        $this->assertKnownKeys($input, ['name', 'preset_id', 'configuration', 'frequency', 'interval', 'weekday', 'monthday', 'local_time', 'timezone', 'starts_at_local', 'ends_on_local', 'max_occurrences'], 'schedule');
        return DB::transaction(function () use ($input, $actorId): BootstrapSchedule {
            $preset = isset($input['preset_id']) ? BootstrapPreset::query()->whereKey($input['preset_id'])->lockForUpdate()->firstOrFail() : null;
            $config = $preset ? $this->configuration($preset->configuration) : $this->configuration($input['configuration'] ?? []);
            if ($preset && array_key_exists('configuration', $input) && ! BootstrapRequestContext::same($this->configuration($input['configuration']), $preset->configuration)) {
                throw ValidationException::withMessages(['configuration' => 'A schedule created from a preset must use that preset revision exactly.']);
            }
            $rule = $this->rule($input);
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 120) throw ValidationException::withMessages(['name' => 'Name is required and limited to 120 characters.']);
            $first = $this->recurrence->next($rule);
            if (! $first || $first->lt(CarbonImmutable::now($rule['timezone']))) throw ValidationException::withMessages(['starts_at_local' => 'The first occurrence must be in the future.']);
            $schedule = BootstrapSchedule::query()->create([
                'name' => $name, 'preset_id' => $preset?->id, 'preset_revision' => $preset?->revision,
                'configuration_snapshot' => $config, 'version' => 1, 'generation' => 1,
                ...$rule, 'next_occurrence_at' => $first->utc()->toDateTimeString(), 'enabled' => false,
                'created_by_user_id' => $actorId, 'updated_by_user_id' => $actorId,
            ]);
            return $schedule;
        });
    }

    /** Replaces only the future rule. Already materialized occurrences keep their original snapshots. */
    public function editSchedule(BootstrapSchedule $schedule, array $input, int $actorId): BootstrapSchedule
    {
        $this->assertAdmin($actorId);
        $this->assertKnownKeys($input, ['name', 'configuration', 'frequency', 'interval', 'weekday', 'monthday', 'local_time', 'timezone', 'starts_at_local', 'ends_on_local', 'max_occurrences'], 'schedule');
        $result = DB::transaction(function () use ($schedule, $input, $actorId): array {
            $locked = BootstrapSchedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            $lockedNextUtc = $this->rawUtcCarbon($locked->getRawOriginal('next_occurrence_at'));
            $editNowUtc = CarbonImmutable::now('UTC');
            $materialized = 0;
            while ($locked->enabled && $lockedNextUtc && $lockedNextUtc->lessThanOrEqualTo($editNowUtc) && $materialized < self::MAX_DUE_BATCH) {
                if (! $this->materializeLockedOccurrence($locked, $lockedNextUtc)) break;
                $materialized++;
                $lockedNextUtc = $this->rawUtcCarbon($locked->getRawOriginal('next_occurrence_at'));
            }
            if ($locked->enabled && $lockedNextUtc && $lockedNextUtc->lessThanOrEqualTo($editNowUtc)) return ['schedule' => $locked->fresh(), 'backlog_pending' => true];
            $rule = $this->rule($input);
            $first = $this->recurrence->next($rule);
            if (! $first || $first->lt(CarbonImmutable::now($rule['timezone']))) throw ValidationException::withMessages(['starts_at_local' => 'The first future occurrence must be in the future.']);
            $config = array_key_exists('configuration', $input) ? $this->configuration($input['configuration']) : $locked->configuration_snapshot;
            $name = trim((string) ($input['name'] ?? $locked->name));
            if ($name === '' || mb_strlen($name) > 120) throw ValidationException::withMessages(['name' => 'Name is required and limited to 120 characters.']);
            $exhausted = $rule['max_occurrences'] !== null && $locked->occurrences_created >= $rule['max_occurrences'];
            $locked->fill([...$rule, 'configuration_snapshot' => $config, 'name' => $name, 'version' => $locked->version + 1, 'generation' => $locked->generation + 1,
                'next_occurrence_at' => $exhausted ? null : $first->utc()->toDateTimeString(), 'enabled' => $exhausted ? false : $locked->enabled,
                'updated_by_user_id' => $actorId])->save();
            return ['schedule' => $locked->fresh(), 'backlog_pending' => false];
        });
        if ($result['backlog_pending']) throw ValidationException::withMessages(['schedule' => 'Schedule is busy catching up due occurrences; the bounded batch was committed. Retry the edit after the due backlog catches up.']);
        return $result['schedule'];
    }

    public function setEnabled(BootstrapSchedule $schedule, bool $enabled, int $actorId): BootstrapSchedule
    {
        $this->assertAdmin($actorId);
        return DB::transaction(function () use ($schedule, $enabled, $actorId): BootstrapSchedule {
            $locked = BootstrapSchedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            if ($enabled && ($locked->max_occurrences !== null && $locked->occurrences_created >= $locked->max_occurrences)) {
                throw ValidationException::withMessages(['enabled' => 'An exhausted schedule cannot be re-enabled.']);
            }
            $changes = ['enabled' => $enabled, 'version' => $locked->version + 1, 'updated_by_user_id' => $actorId];
            if ($enabled) {
                $nextUtc = $this->rawUtcCarbon($locked->getRawOriginal('next_occurrence_at'));
                $nowUtc = CarbonImmutable::now('UTC');
                if (! $nextUtc || $nextUtc->lessThanOrEqualTo($nowUtc)) {
                    $rule = $this->ruleFromSchedule($locked);
                    $future = $this->recurrence->nextAfter($rule, $nowUtc->setTimezone($locked->timezone));
                    if (! $future) throw ValidationException::withMessages(['enabled' => 'No future occurrence remains in this schedule.']);
                    $changes['next_occurrence_at'] = $future->utc()->toDateTimeString();
                }
            }
            $locked->forceFill($changes)->save();
            return $locked->fresh();
        });
    }

    public function cloneSchedule(BootstrapSchedule $schedule, string $name, int $actorId): BootstrapSchedule
    {
        $this->assertAdmin($actorId);
        return DB::transaction(function () use ($schedule, $name, $actorId): BootstrapSchedule {
            $source = BootstrapSchedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            if ($source->preset_id) BootstrapPreset::query()->whereKey($source->preset_id)->lockForUpdate()->firstOrFail();
            $name = trim($name);
            if ($name === '' || mb_strlen($name) > 120) throw ValidationException::withMessages(['name' => 'Name is required and limited to 120 characters.']);
            $next = $this->rawUtcCarbon($source->getRawOriginal('next_occurrence_at'))?->setTimezone($source->timezone);
            $start = $next && $next->gt(CarbonImmutable::now('UTC')) ? $next->toDateTimeString() : CarbonImmutable::now($source->timezone)->toDateTimeString();
            $input = ['frequency' => $source->frequency, 'interval' => $source->interval, 'weekday' => $source->weekday,
                'monthday' => $source->monthday, 'local_time' => $source->local_time, 'timezone' => $source->timezone,
                'starts_at_local' => $start, 'ends_on_local' => $source->ends_on_local?->format('Y-m-d'),
                'max_occurrences' => $source->max_occurrences];
            $rule = $this->rule($input);
            $first = $this->recurrence->next($rule);
            if (! $first || $first->lt(CarbonImmutable::now($rule['timezone']))) throw ValidationException::withMessages(['starts_at_local' => 'A clone must have at least one future occurrence.']);
            return BootstrapSchedule::query()->create(['name' => $name, 'preset_id' => $source->preset_id, 'preset_revision' => $source->preset_revision,
                'configuration_snapshot' => $source->configuration_snapshot, 'version' => 1, 'generation' => 1, ...$rule,
                'next_occurrence_at' => $first->utc()->toDateTimeString(), 'enabled' => false,
                'created_by_user_id' => $actorId, 'updated_by_user_id' => $actorId]);
        });
    }

    /** Creates durable delivery intents. Rows are committed before any call across the engine boundary. */
    public function materializeDue(CarbonImmutable $nowUtc, int $limit = 100, ?int $onlyScheduleId = null): int
    {
        $limit = min(self::MAX_DUE_BATCH, max(1, $limit));
        $count = 0;
        $ids = BootstrapSchedule::query()->where('enabled', true)->whereNotNull('next_occurrence_at')->where('next_occurrence_at', '<=', $nowUtc->utc()->toDateTimeString())->when($onlyScheduleId !== null, fn ($query) => $query->whereKey($onlyScheduleId))->orderBy('next_occurrence_at')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $made = DB::transaction(function () use ($id, $nowUtc): bool {
                $schedule = BootstrapSchedule::query()->whereKey($id)->lockForUpdate()->first();
                $scheduledUtc = $schedule ? $this->rawUtcCarbon($schedule->getRawOriginal('next_occurrence_at')) : null;
                if (! $schedule || ! $schedule->enabled || ! $scheduledUtc || $scheduledUtc->gt($nowUtc)) return false;
                if ($schedule->max_occurrences !== null && $schedule->occurrences_created >= $schedule->max_occurrences) {
                    $schedule->forceFill(['enabled' => false, 'next_occurrence_at' => null])->save(); return false;
                }
                return $this->materializeLockedOccurrence($schedule, $scheduledUtc);
            });
            if ($made) $count++;
        }
        return $count;
    }

    /** Delivery is at-least-once; the adapter key is stable so a crash after canonical run creation is harmless. */
    public function deliverPending(BootstrapScheduleLaunchAdapter $adapter, int $limit = 50): int
    {
        $delivered = 0;
        foreach (BootstrapScheduleOccurrence::query()->where('status', 'pending')->orderBy('id')->limit(min(200, max(1, $limit)))->get() as $occurrence) {
            try {
                // Recover a crash after canonical creation, even if the original actor was
                // subsequently renamed or revoked. This acknowledges an existing commit;
                // it never creates more work under a revoked identity.
                $existing = \App\Models\V7\FundamentalBootstrapRun::query()->opsManaged()
                    ->where('idempotency_key', hash('sha256', $occurrence->actor_admin_id.':'.$occurrence->idempotency_key))->first();
                if ($existing) {
                    $immutableReceipt = $this->launchReceipt($occurrence->configuration_snapshot, (int) $occurrence->actor_admin_id,
                        (int) $occurrence->schedule_id, (int) $occurrence->generation, (int) $occurrence->schedule_version,
                        $occurrence->preset_id === null ? null : (int) $occurrence->preset_id,
                        $occurrence->preset_revision === null ? null : (int) $occurrence->preset_revision,
                        $this->rawUtcInstant($occurrence->getRawOriginal('scheduled_at_utc')), 'scheduled');
                    $this->verifyLaunchResult(new BootstrapLaunchResult($existing, false), $occurrence->configuration_snapshot,
                        (int) $occurrence->actor_admin_id, (string) ($existing->request_snapshot['requested_by']['name'] ?? ''),
                        $occurrence->idempotency_key, $immutableReceipt);
                    if ($this->markQueued($occurrence, (int) $existing->id)) $delivered++;
                    continue;
                }
                $actor = User::query()->find($occurrence->actor_admin_id);
                if (! $actor) {
                    $this->markCannotStart($occurrence, 'actor_unavailable');
                    continue;
                }
                if (! $actor->is_admin) {
                    $this->markCannotStart($occurrence, 'actor_not_admin');
                    continue;
                }
                $receipt = $this->launchReceipt($occurrence->configuration_snapshot, $actor->id, (int) $occurrence->schedule_id,
                    (int) $occurrence->generation, (int) $occurrence->schedule_version, $occurrence->preset_id === null ? null : (int) $occurrence->preset_id,
                    $occurrence->preset_revision === null ? null : (int) $occurrence->preset_revision,
                    $this->rawUtcInstant($occurrence->getRawOriginal('scheduled_at_utc')), 'scheduled');
                $result = $adapter->launch($occurrence->configuration_snapshot, (int) $actor->id, (string) $actor->name, $occurrence->idempotency_key, $receipt);
                $this->verifyLaunchResult($result, $occurrence->configuration_snapshot, $actor->id, (string) $actor->name, $occurrence->idempotency_key, $receipt);
                if ($this->markQueued($occurrence, (int) $result->run->id)) $delivered++;
            } catch (ValidationException $error) {
                $failureCode = $this->definitiveValidationFailureCode($error);
                if ($failureCode === null && array_keys($error->errors()) === ['actor']) {
                    $currentActor = User::query()->find($occurrence->actor_admin_id);
                    if (! $currentActor) $failureCode = 'actor_unavailable';
                    elseif (! $currentActor->is_admin) $failureCode = 'actor_not_admin';
                }
                if ($failureCode !== null) $this->markCannotStart($occurrence, $failureCode);
                else BootstrapScheduleOccurrence::query()->whereKey($occurrence->id)->where('status', 'pending')->update(['last_delivery_error' => 'Canonical validation rejection was not classified as a permanent configuration failure.', 'updated_at' => now()]);
            } catch (Throwable $error) {
                BootstrapScheduleOccurrence::query()->whereKey($occurrence->id)->where('status', 'pending')->update(['last_delivery_error' => mb_substr($error->getMessage(), 0, 2000), 'updated_at' => now()]);
            }
        }
        return $delivered;
    }

    public function runNow(BootstrapSchedule $schedule, int $actorId, string $actorName, string $key, BootstrapScheduleLaunchAdapter $adapter)
    {
        $this->assertAdmin($actorId);
        $this->assertActorName($actorName);
        $key = $this->idempotencyKey($key, 'run-now');
        $receipt = $this->launchReceipt($schedule->configuration_snapshot, $actorId, (int) $schedule->id, (int) $schedule->generation, (int) $schedule->version,
            $schedule->preset_id === null ? null : (int) $schedule->preset_id, $schedule->preset_revision === null ? null : (int) $schedule->preset_revision, null, 'manual');
        $result = $adapter->launch($schedule->configuration_snapshot, $actorId, $actorName, $key, $receipt);
        $this->verifyLaunchResult($result, $schedule->configuration_snapshot, $actorId, $actorName, $key, $receipt);
        return $result;
    }

    private function rule(array $input): array
    {
        $frequency = $input['frequency'] ?? null; $zone = $input['timezone'] ?? null;
        if (! in_array($frequency, ['daily', 'weekly', 'monthly'], true)) throw ValidationException::withMessages(['frequency' => 'Frequency must be daily, weekly, or monthly.']);
        if (! is_string($zone) || ! in_array($zone, timezone_identifiers_list(), true)) throw ValidationException::withMessages(['timezone' => 'Choose a valid IANA timezone.']);
        $interval = filter_var($input['interval'] ?? 1, FILTER_VALIDATE_INT);
        if (! $interval || $interval < 1 || $interval > 365) throw ValidationException::withMessages(['interval' => 'Interval must be between 1 and 365.']);
        if ($frequency === 'weekly' && (! isset($input['weekday']) || (int) $input['weekday'] < 1 || (int) $input['weekday'] > 7)) throw ValidationException::withMessages(['weekday' => 'Weekly schedules require ISO weekday 1 through 7.']);
        if ($frequency === 'monthly' && (! isset($input['monthday']) || (int) $input['monthday'] < 1 || (int) $input['monthday'] > 31)) throw ValidationException::withMessages(['monthday' => 'Monthly schedules require day 1 through 31; short months clamp to month end.']);
        $startInput = $input['starts_at_local'] ?? null;
        if (! is_string($startInput) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$/', $startInput, $startParts)
            || ! checkdate((int) ($startParts[2] ?? 0), (int) ($startParts[3] ?? 0), (int) ($startParts[1] ?? 0))
            || (int) ($startParts[4] ?? 99) > 23 || (int) ($startParts[5] ?? 99) > 59 || (int) ($startParts[6] ?? 0) > 59) {
            throw ValidationException::withMessages(['starts_at_local' => 'Use a valid local wall time YYYY-MM-DD HH:MM[:SS] without an offset.']);
        }
        try { $start = CarbonImmutable::parse($startInput, $zone); } catch (Throwable) { throw ValidationException::withMessages(['starts_at_local' => 'A valid local start datetime is required.']); }
        $time = $input['local_time'] ?? $start->format('H:i:s');
        if (! is_string($time) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time)) throw ValidationException::withMessages(['local_time' => 'Use local time HH:MM or HH:MM:SS.']);
        if (strlen($time) === 5) $time .= ':00';
        $start = $start->setTimeFromTimeString($time);
        $end = $input['ends_on_local'] ?? null;
        if ($end !== null && (! is_string($end) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $end, $parts) || ! checkdate((int) ($parts[2] ?? 0), (int) ($parts[3] ?? 0), (int) ($parts[1] ?? 0)) || $end < $start->toDateString())) throw ValidationException::withMessages(['ends_on_local' => 'End date must be a valid local date on or after the start.']);
        $max = $input['max_occurrences'] ?? null;
        if ($max !== null && (filter_var($max, FILTER_VALIDATE_INT) === false || (int) $max < 1 || (int) $max > 100000)) throw ValidationException::withMessages(['max_occurrences' => 'Maximum occurrences must be between 1 and 100000.']);
        return ['frequency' => $frequency, 'interval' => $interval, 'weekday' => $frequency === 'weekly' ? (int) $input['weekday'] : null, 'monthday' => $frequency === 'monthly' ? (int) $input['monthday'] : null, 'local_time' => $time, 'timezone' => $zone, 'starts_at_local' => $start->toDateTimeString(), 'ends_on_local' => $end, 'max_occurrences' => $max === null ? null : (int) $max];
    }

    private function ruleFromSchedule(BootstrapSchedule $schedule): array
    {
        return ['frequency' => $schedule->frequency, 'interval' => $schedule->interval, 'weekday' => $schedule->weekday, 'monthday' => $schedule->monthday,
            'local_time' => $schedule->local_time, 'timezone' => $schedule->timezone,
            'starts_at_local' => $schedule->starts_at_local?->format('Y-m-d H:i:s'), 'ends_on_local' => $schedule->ends_on_local?->format('Y-m-d')];
    }
    private function configuration(mixed $value): array
    {
        if (! is_array($value)) throw ValidationException::withMessages(['configuration' => 'Configuration must be an object.']);
        $unsupported = array_diff(array_keys($value), ['scope', 'stock_ids', 'force', 'reason']);
        if ($unsupported !== []) throw ValidationException::withMessages(['configuration' => 'Unsupported configuration fields: '.implode(', ', $unsupported).'.']);
        if (! in_array($value['scope'] ?? null, ['all', 'stocks'], true)) throw ValidationException::withMessages(['configuration.scope' => 'Configuration scope must be all or stocks.']);
        $ids = $value['stock_ids'] ?? [];
        if (! is_array($ids) || count($ids) > 500 || ($value['scope'] === 'all' && $ids) || ($value['scope'] === 'stocks' && ! $ids)) throw ValidationException::withMessages(['configuration' => 'Use the whole eligible universe or 1 to 500 explicit eligible stock IDs.']);
        foreach ($ids as $id) if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id < 1) throw ValidationException::withMessages(['configuration.stock_ids' => 'Stock IDs must be positive integers.']);
        $ids = array_map('intval', $ids); sort($ids);
        if (count(array_unique($ids)) !== count($ids)) throw ValidationException::withMessages(['configuration.stock_ids' => 'Stock IDs must be unique.']);
        $force = $value['force'] ?? false;
        if (! is_bool($force)) throw ValidationException::withMessages(['configuration.force' => 'Force must be a boolean.']);
        $reason = $value['reason'] ?? null;
        if ($force) {
            if ($value['scope'] !== 'stocks' || ! is_string($reason) || mb_strlen(trim($reason)) < 3 || mb_strlen($reason) > 2000) {
                throw ValidationException::withMessages(['configuration.reason' => 'Forced presets require explicit stocks and a reason between 3 and 2000 characters.']);
            }
            $reason = trim($reason);
        } elseif ($reason !== null) {
            throw ValidationException::withMessages(['configuration.reason' => 'A reason is supported only for a forced rerun preset.']);
        }
        if ($value['scope'] === 'stocks') {
            if (! $this->stockResolver) throw ValidationException::withMessages(['configuration.stock_ids' => 'Eligible-stock validation is not configured.']);
            $eligible = array_map('intval', $this->stockResolver->eligibleStockIds($ids)); sort($eligible);
            if ($eligible !== $ids) throw ValidationException::withMessages(['configuration.stock_ids' => 'Every selected stock must be an active eligible equity.']);
        }
        return ['scope' => $value['scope'], 'stock_ids' => $ids, 'force' => $force, 'reason' => $reason];
    }

    /** @param array<string,mixed> $configuration @return array<string,mixed> */
    private function launchReceipt(array $configuration, int $actorId, int $scheduleId, int $generation, int $scheduleVersion, ?int $presetId, ?int $presetRevision, ?string $scheduledAtUtc, string $trigger): array
    {
        return ['trigger' => $trigger, 'scheduled_at' => $scheduledAtUtc, 'schedule_id' => $scheduleId,
            'schedule_generation' => $generation, 'schedule_version' => $scheduleVersion, 'actor_admin_id' => $actorId,
            'preset_id' => $presetId, 'preset_revision' => $presetRevision, 'configuration_snapshot' => $configuration,
            'configuration_sha256' => hash('sha256', json_encode($configuration, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'run_now' => $trigger === 'manual'];
    }

    private function verifyLaunchResult(BootstrapLaunchResult $result, array $configuration, int $actorId, string $actorName, string $key, array $receipt): void
    {
        $actorName = trim($actorName);
        $run = $result->run;
        $snapshot = $run->request_snapshot ?? [];
        $expectedAKey = hash('sha256', $actorId.':'.$key);
        $expectedConfigHash = hash('sha256', json_encode($configuration, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $receiptMatchesInput = BootstrapRequestContext::same($receipt['configuration_snapshot'] ?? null, $configuration)
            && is_string($receipt['configuration_sha256'] ?? null)
            && hash_equals($expectedConfigHash, $receipt['configuration_sha256']);
        $sameInputs = ($snapshot['scope'] ?? null) === $configuration['scope']
            && ($snapshot['stock_ids'] ?? []) === $configuration['stock_ids']
            && ($snapshot['force'] ?? false) === $configuration['force']
            && ($snapshot['reason'] ?? null) === $configuration['reason'];
        $sameActor = ($snapshot['requested_by']['id'] ?? null) === $actorId
            && ($snapshot['requested_by']['name'] ?? null) === $actorName;
        $expectedRunTrigger = $configuration['force'] ? 'forced_rerun' : $receipt['trigger'];
        if (! $receiptMatchesInput || $run->idempotency_key !== $expectedAKey || $run->trigger !== $expectedRunTrigger
            || ! BootstrapRequestContext::same($snapshot['schedule_receipt'] ?? null, $receipt) || ! $sameInputs || ! $sameActor) {
            throw new \RuntimeException('Canonical bootstrap launch did not return the matching immutable receipt for this idempotency key.');
        }
    }

    private function rawUtcCarbon(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc();
    }

    private function markQueued(BootstrapScheduleOccurrence $occurrence, int $runId): bool
    {
        return DB::transaction(function () use ($occurrence, $runId): bool {
            $locked = BootstrapScheduleOccurrence::query()->whereKey($occurrence->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') return false;
            $locked->forceFill(['run_id' => $runId, 'status' => 'queued', 'delivered_at' => now(),
                'last_delivery_error' => null, 'failure_code' => null, 'failed_at' => null])->save();
            return true;
        });
    }

    private function markCannotStart(BootstrapScheduleOccurrence $occurrence, string $failureCode): bool
    {
        return DB::transaction(function () use ($occurrence, $failureCode): bool {
            DB::table('stox_fund_bootstrap_control')->where('id', 1)->lockForUpdate()->first();
            $existing = \App\Models\V7\FundamentalBootstrapRun::query()->opsManaged()
                ->where('idempotency_key', hash('sha256', $occurrence->actor_admin_id.':'.$occurrence->idempotency_key))->exists();
            if ($existing) return false;
            $locked = BootstrapScheduleOccurrence::query()->whereKey($occurrence->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') return false;
            $locked->forceFill(['status' => 'cannot_start', 'failure_code' => $failureCode, 'failed_at' => now(), 'last_delivery_error' => null])->save();
            return true;
        });
    }

    private function definitiveValidationFailureCode(ValidationException $error): ?string
    {
        $keys = array_keys($error->errors());
        $configurationKeys = ['configuration', 'configuration.scope', 'configuration.stock_ids', 'configuration.force', 'configuration.reason', 'scope', 'stock_ids', 'force', 'reason'];
        if ($keys === [] || array_diff($keys, $configurationKeys) !== []) return null;
        if (array_intersect($keys, ['stock_ids', 'configuration.stock_ids']) !== []) return 'eligible_stocks_unavailable';
        return 'configuration_invalid';
    }

    private function materializeLockedOccurrence(BootstrapSchedule $schedule, CarbonImmutable $scheduledUtc): bool
    {
        if ($schedule->max_occurrences !== null && $schedule->occurrences_created >= $schedule->max_occurrences) {
            $schedule->forceFill(['enabled' => false, 'next_occurrence_at' => null])->save();
            return false;
        }
        $local = $scheduledUtc->setTimezone($schedule->timezone);
        $identity = implode(':', [$schedule->id, $schedule->generation, $local->utc()->format('Ymd\\THis.u\\Z')]);
        BootstrapScheduleOccurrence::query()->firstOrCreate(['schedule_id' => $schedule->id, 'generation' => $schedule->generation, 'scheduled_at_utc' => $local->utc()->toDateTimeString()], [
            'schedule_version' => $schedule->version, 'scheduled_at_local' => $local->format('Y-m-d H:i:s'), 'timezone' => $schedule->timezone,
            'preset_id' => $schedule->preset_id, 'preset_revision' => $schedule->preset_revision,
            'configuration_snapshot' => $schedule->configuration_snapshot, 'actor_admin_id' => $schedule->created_by_user_id,
            'idempotency_key' => hash('sha256', 'bootstrap-schedule:'.$identity), 'status' => 'pending',
        ]);
        $next = $this->recurrence->next($this->ruleFromSchedule($schedule), $local);
        $created = $schedule->occurrences_created + 1;
        $finished = ($schedule->max_occurrences !== null && $created >= $schedule->max_occurrences)
            || ($next && $schedule->ends_on_local && $next->toDateString() > $schedule->ends_on_local->toDateString());
        $schedule->forceFill(['occurrences_created' => $created,
            'next_occurrence_at' => $finished || ! $next ? null : $next->utc()->toDateTimeString(),
            'enabled' => $finished || ! $next ? false : $schedule->enabled])->save();
        return true;
    }

    private function calendarDayDistance(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $fromDate = CarbonImmutable::parse($from->toDateString(), 'UTC');
        $toDate = CarbonImmutable::parse($to->toDateString(), 'UTC');
        return (int) $fromDate->diffInDays($toDate);
    }

    private function rawUtcInstant(?string $value): ?string
    {
        return $this->rawUtcCarbon($value)?->format('Y-m-d\\TH:i:s\\Z');
    }

    private function assertActorName(string $actorName): void
    {
        $actorName = trim($actorName);
        if ($actorName === '' || mb_strlen($actorName) > 255) throw ValidationException::withMessages(['actor' => 'A valid Admin name is required.']);
    }
    private function assertKnownKeys(array $input, array $allowed, string $field): void
    {
        $unsupported = array_diff(array_keys($input), $allowed);
        if ($unsupported !== []) throw ValidationException::withMessages([$field => 'Unsupported fields: '.implode(', ', $unsupported).'.']);
    }
    private function assertAdmin(int $actorId): void { if ($actorId < 1 || ! User::query()->whereKey($actorId)->where('is_admin', true)->exists()) throw ValidationException::withMessages(['actor' => 'A valid Admin identity is required.']); }
    private function idempotencyKey(string $key, string $prefix): string { if (! preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key)) throw ValidationException::withMessages(['Idempotency-Key' => 'A valid Idempotency-Key is required.']); return hash('sha256', $prefix.':'.$key); }
}
