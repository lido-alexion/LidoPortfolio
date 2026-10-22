<?php

namespace App\Services\Fundamentals;

use Illuminate\Validation\ValidationException;

/** Stable JSON context comparisons survive database object-key normalization without changing list order or types. */
final class BootstrapRequestContext
{
    public static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (! array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::canonical($item);
        return $value;
    }

    public static function same(mixed $left, mixed $right): bool
    {
        return self::canonical($left) === self::canonical($right);
    }

    public static function validateSchedule(array $configuration, int $actorId, array $receipt): void
    {
        $trigger = $receipt['trigger'] ?? null;
        $valid = in_array($trigger, ['scheduled', 'manual'], true);
        foreach (['schedule_id', 'schedule_generation', 'schedule_version'] as $field) {
            $valid = $valid && is_int($receipt[$field] ?? null) && $receipt[$field] > 0;
        }
        $valid = $valid && ($receipt['actor_admin_id'] ?? null) === $actorId;
        $valid = $valid && array_key_exists('preset_id', $receipt) && array_key_exists('preset_revision', $receipt);
        $hasPreset = isset($receipt['preset_id']);
        $valid = $valid && $hasPreset === isset($receipt['preset_revision']);
        if ($hasPreset) {
            $valid = $valid && is_int($receipt['preset_id']) && $receipt['preset_id'] > 0
                && is_int($receipt['preset_revision']) && $receipt['preset_revision'] > 0;
        }
        if ($trigger === 'scheduled') {
            $instant = $receipt['scheduled_at'] ?? null;
            $parsed = is_string($instant) ? \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $instant, new \DateTimeZone('UTC')) : false;
            $valid = $valid && $parsed && $parsed->format('Y-m-d\\TH:i:s\\Z') === $instant && ($receipt['run_now'] ?? null) === false;
        } else {
            $valid = $valid && array_key_exists('scheduled_at', $receipt) && $receipt['scheduled_at'] === null && ($receipt['run_now'] ?? null) === true;
        }
        $valid = $valid && self::same($receipt['configuration_snapshot'] ?? null, $configuration)
            && is_string($receipt['configuration_sha256'] ?? null)
            && hash_equals(hash('sha256', json_encode($configuration, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), $receipt['configuration_sha256']);
        if (! $valid) throw ValidationException::withMessages(['schedule_receipt' => 'A complete matching immutable schedule receipt is required.']);
    }
}
