<?php

namespace App\Support\OpenApi;

/** Root integration contracts for the read-only health projection and immutable gap launch. */
final class V1BootstrapIntegrationOverlays
{
    public static function all(): array
    {
        return [
            'GET /api/v1/admin/fundamentals/bootstrap-operations/health' => [
                'summary' => 'Read global bootstrap operational health',
                'description' => 'Admin-only read-only aggregate projection: actual active owner, explicit managed queue count, last successful run, unresolved technical failures/warnings/gaps and next enabled schedule. Does not reconcile, dispatch ingestion, create records or notify.',
                'noBody' => true,
            ],
            'POST /api/v1/admin/fundamentals/bootstrap-operations/runs/from-gaps' => [
                'summary' => 'Queue a compatible coverage-gap stock selection',
                'description' => 'Admin-only explicit action. Select 1–100 actionable/reopened gaps sharing metric and cadence. The existing engine targets their exact eligible stocks across supported cadences; metrics and periods are provenance, not execution bounds. Persists the gap selection atomically with the run and replays the same actor-scoped request even if gaps later resolve.',
                'parameters' => [[
                    'name' => 'Idempotency-Key', 'in' => 'header', 'required' => true,
                    'schema' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 128, 'pattern' => '^[A-Za-z0-9._:-]{8,128}$'],
                ]],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['gap_ids'],
                    'properties' => ['gap_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'uniqueItems' => true,
                        'items' => ['type' => 'integer', 'minimum' => 1]]],
                ]]]],
                'responses' => ['201' => ['description' => 'A new managed run was queued.'], '200' => ['description' => 'Original run returned for the same request.'],
                    '403' => ['description' => 'Caller is not an Admin.'], '422' => ['description' => 'Invalid, incompatible, ineligible or conflicting request.']],
            ],
        ];
    }
}
