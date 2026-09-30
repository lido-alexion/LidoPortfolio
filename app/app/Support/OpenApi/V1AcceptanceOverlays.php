<?php

namespace App\Support\OpenApi;

use App\Services\ML\MlAcceptanceSourceService;

/** Source metadata for the FEAT-057 administration routes. */
final class V1AcceptanceOverlays
{
    public static function all(): array
    {
        $operations = [
            'GET ' => [200, 'Inspect production acceptance evidence'],
            'GET /sources' => [200, 'List private source manifests (25 per page)'],
            'POST /sources' => [201, 'Create a private source manifest'],
            'GET /sources/{source}' => [200, 'Inspect a private source manifest'],
            'PUT /sources/{source}/chunks' => [200, 'Upload a base64 source chunk at the expected byte offset'],
            'POST /sources/{source}/{action}' => [200, 'Finalize, resume or cancel a source upload'],
            'POST /backfills/preview' => [202, 'Queue a preview of sealed historical sources'],
            'GET /backfills/{backfill}' => [200, 'Inspect an acceptance backfill'],
            'POST /backfills/{backfill}/{action}' => [200, 'Apply, resume or cancel an acceptance backfill'],
            'POST /campaigns' => [202, 'Create an acceptance campaign and queue preflight'],
            'GET /campaigns/{campaign}' => [200, 'Inspect an acceptance campaign'],
            'POST /campaigns/{campaign}/{action}' => [200, 'Start, resume or cancel an acceptance campaign'],
        ];
        $httpError = static fn (string $description): array => [
            'description' => $description,
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => ['message' => ['type' => 'string'], 'request_id' => ['type' => 'string', 'nullable' => true]],
            ]]],
        ];
        $bodies = [
            'POST /sources' => [
                'version' => ['type' => 'integer', 'enum' => [1]],
                'source' => ['type' => 'string', 'enum' => ['nse_mii_security_file', 'nse_cash_bhavcopy']],
                'date' => ['type' => 'string', 'format' => 'date', 'description' => 'Not later than today.'],
                'filename' => ['type' => 'string', 'maxLength' => 150, 'description' => 'Official NSE/MII dated CSV or ZIP filename matching the selected source family.'],
                'bytes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => MlAcceptanceSourceService::MAX_BYTES],
                'sha256' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            ],
            'PUT /sources/{source}/chunks' => [
                'offset' => ['type' => 'integer', 'minimum' => 0],
                'chunk' => ['type' => 'string', 'format' => 'byte', 'maxLength' => 1398104],
            ],
            'POST /backfills/preview' => [
                'sources' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 5000, 'uniqueItems' => true, 'items' => ['type' => 'string', 'format' => 'uuid']],
            ],
            'POST /campaigns' => [
                'cutoff_date' => ['type' => 'string', 'format' => 'date', 'description' => 'Not later than today.'],
            ],
        ];
        $overlays = [];
        foreach ($operations as $suffix => [$status, $summary]) {
            [$method, $path] = explode(' ', $suffix, 2);
            $responses = [
                $status => [
                    'description' => 'Safe acceptance payload wrapped in `data`.',
                    'content' => ['application/json' => ['schema' => [
                        'type' => 'object', 'required' => ['data'],
                        'properties' => ['data' => ['type' => 'object', 'additionalProperties' => true]],
                    ]]],
                ],
                413 => $httpError('Content-Length exceeds the 1,500,000-byte acceptance request limit.'),
                422 => [
                    'description' => 'Laravel validation `{message, errors}`: invalid input, source quotas/state, queue prerequisites or campaign/backfill preconditions. Invalid base64 returns `{message, request_id}`.',
                ],
                429 => $httpError('Acceptance rate limit exceeded (60 requests per minute).'),
            ];
            if (str_contains($path, '{')) {
                $responses[404] = $httpError('Resource missing, route parameter/action invalid, or inspected backfill is not an acceptance backfill.');
            }
            $overlay = [
                'summary' => $summary,
                'successStatus' => $status,
                'responses' => $responses,
                'noBody' => true,
                'pathParameterSchemas' => [
                    'source' => ['type' => 'string', 'format' => 'uuid'],
                    'campaign' => ['type' => 'string', 'format' => 'uuid'],
                    'backfill' => ['type' => 'integer'],
                ],
            ];
            if (str_ends_with($path, '/{action}')) {
                $overlay['pathParameterSchemas']['action'] = ['type' => 'string', 'enum' => match (true) {
                    str_starts_with($path, '/sources/') => ['finalize', 'resume', 'cancel'],
                    str_starts_with($path, '/backfills/') => ['apply', 'resume', 'cancel'],
                    default => ['start', 'resume', 'cancel'],
                }];
            }
            if (isset($bodies[$suffix])) {
                $overlay['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => [
                    'type' => 'object', 'required' => array_keys($bodies[$suffix]), 'properties' => $bodies[$suffix],
                ]]]];
            }
            if ($suffix === 'GET /sources') {
                $overlay['parameters'] = [['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]]];
            }
            $overlays[$method.' /api/v1/admin/ml/acceptance'.$path] = $overlay;
        }

        return $overlays;
    }
}
