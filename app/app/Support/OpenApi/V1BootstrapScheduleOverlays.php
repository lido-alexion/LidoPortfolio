<?php

namespace App\Support\OpenApi;

/** OpenAPI metadata for the bounded OPS-001 preset and recurring schedule endpoints. */
final class V1BootstrapScheduleOverlays
{
    public static function all(): array
    {
        $configuration = [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['scope'],
            'properties' => [
                'scope' => ['type' => 'string', 'enum' => ['all', 'stocks'], 'description' => '`all` means the whole currently eligible universe; `stocks` means explicit eligible stock IDs.'],
                'stock_ids' => ['type' => 'array', 'maxItems' => 500, 'uniqueItems' => true, 'items' => ['type' => 'integer', 'minimum' => 1]],
                'force' => ['type' => 'boolean', 'default' => false, 'description' => 'Optional governed forced rerun; requires scope=stocks and reason.'],
                'reason' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 2000],
            ],
        ];
        $rows = [
            'GET /api/v1/admin/fundamentals/bootstrap-operations/presets' => 'List shared bootstrap presets (bounded pagination).',
            'POST /api/v1/admin/fundamentals/bootstrap-operations/presets' => 'Create a shared Admin preset for the whole eligible universe or explicit eligible stocks. This reuses the existing scope and stock selection only; it does not accept cadence or provider overrides.',
            'PUT /api/v1/admin/fundamentals/bootstrap-operations/presets/{preset}' => 'Revise a preset; existing schedules retain their snapshots.',
            'POST /api/v1/admin/fundamentals/bootstrap-operations/presets/{preset}/clone' => 'Clone a shared preset.',
            'DELETE /api/v1/admin/fundamentals/bootstrap-operations/presets/{preset}' => 'Delete a preset only when no schedule references it.',
            'GET /api/v1/admin/fundamentals/bootstrap-operations/schedules' => 'List recurring schedules and occurrence counts.',
            'POST /api/v1/admin/fundamentals/bootstrap-operations/schedules' => 'Create a disabled daily, weekly, or monthly schedule over the whole eligible universe or explicit eligible stocks. Bootstrap configuration does not accept cadence or provider overrides.',
            'PUT /api/v1/admin/fundamentals/bootstrap-operations/schedules/{schedule}' => 'Edit only future schedule occurrences.',
            'PUT /api/v1/admin/fundamentals/bootstrap-operations/schedules/{schedule}/enabled' => 'Enable or disable a schedule.',
            'POST /api/v1/admin/fundamentals/bootstrap-operations/schedules/{schedule}/clone' => 'Clone a schedule as disabled.',
            'GET /api/v1/admin/fundamentals/bootstrap-operations/schedules/{schedule}/occurrences' => 'Read bounded immutable occurrence receipts.',
            'POST /api/v1/admin/fundamentals/bootstrap-operations/schedules/{schedule}/run-now' => 'Queue one manual run without advancing the recurring schedule.',
        ];
        $out = [];
        foreach ($rows as $operation => $summary) {
            [$method, $path] = explode(' ', $operation, 2);
            $out[$operation] = ['summary' => $summary, 'description' => $summary, 'operationId' => strtolower($method.'_bootstrap_schedule_'.substr(sha1($path), 0, 10)), 'noBody' => $method === 'GET'];
        }
        $presetCreate = ['type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'configuration'], 'properties' => [
            'name' => ['type' => 'string', 'maxLength' => 120], 'configuration' => $configuration,
        ]];
        $scheduleCreate = ['type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'frequency', 'timezone', 'starts_at_local'],
            'anyOf' => [['required' => ['preset_id']], ['required' => ['configuration']]], 'properties' => [
            'name' => ['type' => 'string', 'maxLength' => 120], 'preset_id' => ['type' => 'integer', 'minimum' => 1], 'configuration' => $configuration,
            'frequency' => ['type' => 'string', 'enum' => ['daily', 'weekly', 'monthly']], 'interval' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365],
            'weekday' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7], 'monthday' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 31],
            'timezone' => ['type' => 'string', 'description' => 'IANA timezone; recurrence uses local calendar boundaries.'],
            'local_time' => ['type' => 'string', 'pattern' => '^([01]\\d|2[0-3]):[0-5]\\d(:[0-5]\\d)?$'],
            'starts_at_local' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}(:\\d{2})?$'],
            'ends_on_local' => ['type' => 'string', 'format' => 'date'], 'max_occurrences' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000],
        ]];
        $out['POST /api/v1/admin/fundamentals/bootstrap-operations/presets']['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => $presetCreate]]];
        $out['PUT /api/v1/admin/fundamentals/bootstrap-operations/presets/{preset}']['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => $presetCreate]]];
        $out['POST /api/v1/admin/fundamentals/bootstrap-operations/schedules']['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => $scheduleCreate]]];
        $base = '/api/v1/admin/fundamentals/bootstrap-operations';
        $body = fn (array $schema): array => ['required' => true, 'content' => ['application/json' => ['schema' => $schema]]];
        $name = ['type' => 'object', 'additionalProperties' => false, 'required' => ['name'],
            'properties' => ['name' => ['type' => 'string', 'maxLength' => 120]]];
        $out['POST '.$base.'/presets/{preset}/clone']['requestBody'] = $body($name);
        $out['POST '.$base.'/schedules/{schedule}/clone']['requestBody'] = $body($name);
        $scheduleEdit = $scheduleCreate;
        $scheduleEdit['required'] = ['frequency', 'timezone', 'starts_at_local'];
        unset($scheduleEdit['anyOf'], $scheduleEdit['properties']['preset_id']);
        $out['PUT '.$base.'/schedules/{schedule}']['requestBody'] = $body($scheduleEdit);
        $out['PUT '.$base.'/schedules/{schedule}/enabled']['requestBody'] = $body([
            'type' => 'object', 'additionalProperties' => false, 'required' => ['enabled'],
            'properties' => ['enabled' => ['type' => 'boolean']],
        ]);
        $out['DELETE '.$base.'/presets/{preset}']['noBody'] = true;
        $out['POST '.$base.'/schedules/{schedule}/run-now']['requestBody'] = $body([
            'type' => 'object', 'additionalProperties' => false, 'maxProperties' => 0,
        ]);
        $out['POST '.$base.'/schedules/{schedule}/run-now']['parameters'] = [[
            'name' => 'Idempotency-Key', 'in' => 'header', 'required' => true,
            'schema' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 128, 'pattern' => '^[A-Za-z0-9._:-]{8,128}$'],
        ]];
        return $out;
    }
}
