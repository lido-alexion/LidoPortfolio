<?php

namespace App\Support\OpenApi;

/** Contract overlays for the bounded Admin fundamentals run control API. */
final class V1BootstrapOperationsOverlays
{
    public static function all(): array
    {
        return [
            'GET /api/v1/admin/fundamentals/bootstrap-operations/runs' => [
                'summary' => 'List normalized fundamentals bootstrap runs',
                'description' => 'Admin-only, read-only paginated history. Run data is an allowlisted progress projection.',
                'noBody' => true,
            ],
            'GET /api/v1/admin/fundamentals/bootstrap-operations/runs/{run}' => [
                'summary' => 'Read normalized bootstrap run progress and items',
                'description' => 'Returns the normalized run, progress, and paginated items under the data envelope.', 'noBody' => true,
            ],
            'POST /api/v1/admin/fundamentals/bootstrap-operations/runs' => [
                'summary' => 'Queue an Admin fundamentals bootstrap run',
                'description' => 'Queues eligible active equities in FIFO order. A scheduler advances durable slices. Forced targeted reruns use the existing governed source resolver and preserve prior run/job history.',
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                    'type' => 'object', 'required' => ['scope'], 'properties' => [
                        'scope' => ['type' => 'string', 'enum' => ['all', 'stocks']],
                        'stock_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 500],
                        'force' => ['type' => 'boolean'], 'reason' => ['type' => 'string', 'maxLength' => 2000],
                    ],
                ]]]],
                'parameters' => [[
                    'name' => 'Idempotency-Key', 'in' => 'header', 'required' => true,
                    'description' => 'Request identity is scoped to the authenticated Admin and operation payload.',
                    'schema' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 128],
                ]],
                'responses' => [
                    '201' => ['description' => 'New run queued; response contains normalized progress only.'],
                    '200' => ['description' => 'Exact idempotent replay; returns the existing normalized run.'],
                ],
            ],
            'POST /api/v1/admin/fundamentals/bootstrap-operations/runs/{run}/retry-failed' => [
                'summary' => 'Queue a new run for failed items', 'description' => 'Creates a new run and preserves the source run and its job history.',
                'parameters' => [[
                    'name' => 'Idempotency-Key', 'in' => 'header', 'required' => true,
                    'schema' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 128],
                ]],
            ],
            'POST /api/v1/admin/fundamentals/bootstrap-operations/runs/{run}/cancel' => [
                'summary' => 'Cancel unfinished bootstrap items',
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                    'type' => 'object', 'required' => ['reason'], 'properties' => ['reason' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 2000]],
                ]]]],
                'responses' => [
                    '200' => ['description' => 'Run cancelled or already terminal.'],
                    '409' => ['description' => 'A bootstrap slice holds the process fence; no cancellation was applied.'],
                ],
            ],
        ];
    }
}
