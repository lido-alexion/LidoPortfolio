<?php

namespace App\Support\OpenApi;

/** OPS001 Package B operation metadata; merged into V1OperationOverlays by root integration. */
final class V1BootstrapCoverageOverlays
{
    public static function all(): array
    {
        $base = '/api/v1/admin/fundamentals/bootstrap-operations';
        $body = static fn (array $schema): array => ['required' => true, 'content' => ['application/json' => ['schema' => $schema]]];
        $idsBody = static fn (): array => $body(['type' => 'object', 'required' => ['ids'], 'properties' => [
            'ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'uniqueItems' => true,
                'items' => ['type' => 'integer', 'minimum' => 1]],
            'note' => ['type' => 'string', 'maxLength' => 1000, 'nullable' => true],
        ]]);
        return [
            "GET {$base}/coverage" => ['summary' => 'Read canonical fundamentals coverage', 'description' => 'Admin-only read projection by primary catalogue metric and quarterly/annual cadence, plus bounded actual known-period coverage. These buckets describe facts present; they are not missing-period coverage. Applicability and expected periods remain unknown where no authoritative expectation exists.', 'noBody' => true],
            "GET {$base}/gaps" => ['summary' => 'List paginated coverage gaps', 'description' => 'Admin-only stock/metric/cadence gap occurrences with deterministic reason, state, bounded acknowledgement history, and observation count. period_end is null where no expected-period evidence exists.', 'parameters' => self::queryParameters(['state' => 'actionable,reopened,accepted,resolved', 'reason' => 'deterministic reason category', 'metric' => 'primary catalogue fact key', 'cadence' => 'quarterly,annual', 'per_page' => '1..100'])],
            "GET {$base}/warnings" => ['summary' => 'List paginated quality warning occurrences', 'description' => 'Admin-only normalized warning occurrences with bounded acknowledgement history.', 'parameters' => self::queryParameters(['state' => 'unresolved,acknowledged', 'category' => 'normalized warning category', 'per_page' => '1..100'])],
            "POST {$base}/reconcile" => ['summary' => 'Explicitly reconcile a bootstrap run', 'description' => 'Materializes durable gap observations and normalized warnings from run jobs and canonical facts. No ingestion or provider calls.', 'requestBody' => $body(['type' => 'object', 'required' => ['run_id'], 'properties' => ['run_id' => ['type' => 'integer', 'minimum' => 1]]])],
            "POST {$base}/gaps/acknowledge" => ['summary' => 'Accept compatible gaps as expected missing', 'description' => 'Atomic bulk acknowledgement requires one metric, cadence, and reason. Records Admin, time, optional note, and retained acknowledgement history.', 'requestBody' => $idsBody()],
            "POST {$base}/warnings/acknowledge" => ['summary' => 'Acknowledge warning occurrences', 'description' => 'Atomic bulk acknowledgement records Admin, time, and optional note.', 'requestBody' => $idsBody()],
        ];
    }

    private static function queryParameters(array $items): array
    {
        $parameters = [];
        foreach ($items as $name => $description) {
            $parameters[] = ['name' => $name, 'in' => 'query', 'required' => false, 'description' => $description,
                'schema' => ['type' => $name === 'per_page' ? 'integer' : 'string']];
        }
        return $parameters;
    }
}
