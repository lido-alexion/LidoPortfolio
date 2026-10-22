<?php

namespace App\Support\OpenApi;

/** Contract overlay for the bounded managed-run CSV download. */
final class V1BootstrapOperationalOutcomeOverlays
{
    public static function all(): array
    {
        return [
            'GET /api/v1/admin/fundamentals/bootstrap-operations/runs/{run}/export.csv' => [
                'summary' => 'Download a managed bootstrap run CSV',
                'description' => 'Admin-only streamed CSV containing a run summary and normalized per-stock/per-period outcomes. It accepts an optional item status or failed-only filter. Only run-linked safe projections are exported; raw errors, provider payloads, checkpoints, credentials, and filesystem paths are excluded. The run must have the current OPS-managed version and origin.',
                'noBody' => true,
                'parameters' => [[
                    'name' => 'status', 'in' => 'query', 'required' => false,
                    'schema' => ['type' => 'string', 'enum' => ['queued', 'running', 'retry', 'complete_good', 'complete_partial', 'complete_no_data', 'failed', 'cancelled']],
                ], [
                    'name' => 'failed_only', 'in' => 'query', 'required' => false,
                    'schema' => ['type' => 'boolean'],
                ]],
                'responses' => [
                    '200' => ['description' => 'Private CSV attachment with formula-safe string cells.'],
                    '403' => ['description' => 'Caller is not an Admin.'],
                    '404' => ['description' => 'Run is not in the current OPS-managed scope.'],
                    '422' => ['description' => 'Invalid filter or run exceeds the bounded export limit.'],
                ],
            ],
        ];
    }
}
