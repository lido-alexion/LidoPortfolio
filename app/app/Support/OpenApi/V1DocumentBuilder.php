<?php

namespace App\Support\OpenApi;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * V4-FEAT-025 — build an OpenAPI 3.0.3 document from the live /api/v1 route list.
 * Overlay metadata adds summaries and known request/response shapes from source.
 * Does not invent endpoints that are not registered.
 */
class V1DocumentBuilder
{
    public const OPENAPI_VERSION = '3.0.3';

    public const CANONICAL_RELATIVE = 'openapi/v1.json';

    public function canonicalPath(): string
    {
        return base_path(self::CANONICAL_RELATIVE);
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $overlays = V1OperationOverlays::all();
        $paths = [];

        foreach ($this->v1Routes() as $entry) {
            $path = $entry['path'];
            $method = strtolower($entry['method']);
            $key = $entry['key'];
            $overlay = $overlays[$key] ?? [];

            $operation = $this->baseOperation($entry, $overlay);

            if (! isset($paths[$path])) {
                $paths[$path] = [];
            }
            $paths[$path][$method] = $operation;
        }

        ksort($paths);

        $spec = [
            'openapi' => self::OPENAPI_VERSION,
            'info' => [
                'title' => 'Stox /api/v1',
                'description' => implode("\n\n", [
                    'Machine-readable contract for the existing Stox (Stox by Lido Alexion) `/api/v1` HTTP API.',
                    'This documents current behaviour. It is not an API redesign.',
                    'Authentication is Laravel Sanctum **SPA session cookies**, not JWT and not a Bearer token in localStorage. Login lives on the legacy `/api/auth/*` surface (outside `/api/v1`). Call `GET /sanctum/csrf-cookie` (or `GET /api/auth/csrf-token`) before cookie-authenticated mutating requests.',
                    'Portfolio-scoped routes resolve the active portfolio from header `X-Profile-Id` (legacy alias `X-Portfolio-Id`), query `portfolio_id`, or the user default portfolio.',
                    'Successful `/api/v1` JSON uses `ApiEnvelope`: `{success: true, data, meta}`. Domain failures use `{success: false, error: {code, message}, meta}`. Laravel validation may still return the framework 422 `{message, errors}` shape.',
                    'Standalone Discovery / Evaluation / Recommendation endpoints are **not** gated by dataset freshness. `POST /api/v1/pipeline/run` **is** gated (V4-FEAT-022): last successful market sync must be ≤ 24 hours old, or ≤ 72 hours when the pipeline instant is Monday in `cron_timezone`. A missing/stale timestamp returns envelope error `DATASET_NOT_FRESH` (422) and does not run Discovery.',
                    '`GET /api/v1/dataset/status` `data.dataset_version` is the current immutable dataset version key (V4-FEAT-023), or `none`. `data.published` still means successfully synced today (inspection only; the pipeline does not gate on that boolean).',
                    '`POST /api/v1/evaluation/runs` resolves Strategy catalogue indicator parameters over Evaluation globals (V4-FEAT-021). Evaluation `market_regime` is Bullish→100 / Neutral→50 / Bearish→0 from Market Analysis (V4-FEAT-005).',
                    'Recording a ledger fill that completes a recommendation still goes through `POST /api/transactions` (legacy `/api`, not `/api/v1`). Sells that could affect more than one owner require `owner_key` or `strategy_id` (V4-SPEC-005); a linked `recommendation_id` identifies the Strategy. Recommendation `executed` status is owned by RecommendationEngine::markExecuted (V4-FEAT-024).',
                    'Paginated TOS lists (V4-FEAT-028) accept `page` (default 1) and `pageSize` (alias `per_page`). Meta is `{page, pageSize, total, lastPage}`. Maximum page size is 200 except `GET /price-bars` (500). Defaults match the previous implicit first-page caps. Not paginated (intentionally bounded): candidates, evaluations, positions, pending-execution, review dashboard/outcomes.',
                    'Strategy-position GTT Target / Stop-Loss (V4-FEAT-002): `GET/POST /api/v1/protections`, `POST /protections/{id}/cancel`, `POST /protections/{id}/reconcile`. Only one open protection per Strategy position. Prices come from Strategy `target_amount/quantity` or OD-13 stop. Broker acceptance is not a fill.',
                ]),
                'version' => '1.0.0',
            ],
            'servers' => [
                [
                    'url' => '/',
                    'description' => 'Application origin. Paths below are rooted at the host (e.g. `/api/v1/securities`). A subdirectory install (e.g. `/portfolio`) prefixes both the SPA and `/api` the same way.',
                ],
            ],
            'tags' => $this->tags(),
            'paths' => $paths,
            'components' => $this->components(),
            'security' => [
                ['sanctumCookie' => []],
            ],
        ];

        $this->assertValidDocument($spec);

        return $spec;
    }

    public function encode(array $spec): string
    {
        $json = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $json."\n";
    }

    public function write(?string $path = null): string
    {
        $path ??= $this->canonicalPath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $json = $this->encode($this->build());
        file_put_contents($path, $json);

        $publicCopy = public_path('docs/openapi-v1.json');
        $publicDir = dirname($publicCopy);
        if (is_dir($publicDir) || is_dir(public_path('docs'))) {
            if (! is_dir($publicDir)) {
                mkdir($publicDir, 0755, true);
            }
            file_put_contents($publicCopy, $json);
        }

        return $path;
    }

    /**
     * @return list<string> e.g. "GET /api/v1/dataset/status"
     */
    public function laravelOperationKeys(): array
    {
        return array_map(fn (array $e) => $e['key'], $this->v1Routes());
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<string>
     */
    public function specOperationKeys(array $spec): array
    {
        $keys = [];
        foreach ($spec['paths'] ?? [] as $path => $ops) {
            if (! is_array($ops)) {
                continue;
            }
            foreach ($ops as $method => $operation) {
                if (! is_array($operation) || in_array($method, ['parameters', 'summary', 'description', '$ref'], true)) {
                    continue;
                }
                $keys[] = strtoupper($method).' '.$path;
            }
        }
        usort($keys, function (string $a, string $b): int {
            [$methodA, $pathA] = explode(' ', $a, 2);
            [$methodB, $pathB] = explode(' ', $b, 2);

            return [$pathA, $methodA] <=> [$pathB, $methodB];
        });

        return $keys;
    }

    /**
     * @return list<array{method:string,path:string,key:string,middleware:list<string>,action:string,admin:bool,authenticated:bool}>
     */
    public function v1Routes(): array
    {
        $out = [];
        foreach (RouteFacade::getRoutes() as $route) {
            if (! $route instanceof Route) {
                continue;
            }
            $uri = ltrim($route->uri(), '/');
            if (! str_starts_with($uri, 'api/v1')) {
                continue;
            }
            $path = '/'.$uri;
            foreach ($route->methods() as $method) {
                $method = strtoupper($method);
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $middleware = $this->middlewareNames($route);
                $out[] = [
                    'method' => $method,
                    'path' => $path,
                    'key' => $method.' '.$path,
                    'middleware' => $middleware,
                    'action' => $route->getActionName(),
                    'admin' => in_array('admin', $middleware, true),
                    'authenticated' => in_array('auth:sanctum', $middleware, true),
                ];
            }
        }

        usort($out, fn ($a, $b) => [$a['path'], $a['method']] <=> [$b['path'], $b['method']]);

        return $out;
    }

    /**
     * @param  array{method:string,path:string,key:string,middleware:list<string>,action:string,admin:bool,authenticated:bool}  $entry
     * @param  array<string, mixed>  $overlay
     * @return array<string, mixed>
     */
    protected function baseOperation(array $entry, array $overlay): array
    {
        $tag = $this->tagForPath($entry['path']);
        $summary = $overlay['summary'] ?? $this->defaultSummary($entry);
        $description = $overlay['description'] ?? $this->defaultDescription($entry);
        $operationId = $overlay['operationId'] ?? $this->operationId($entry);

        $parameters = array_merge(
            $entry['authenticated'] ? [['$ref' => '#/components/parameters/XProfileId']] : [],
            $this->pathParameters($entry['path']),
            $overlay['parameters'] ?? [],
        );

        $successCode = (string) ($overlay['successStatus'] ?? $this->defaultSuccessStatus($entry['method']));
        $successRef = $overlay['successRef'] ?? '#/components/schemas/EnvelopeSuccess';

        $responses = [
            $successCode => [
                'description' => $overlay['successDescription'] ?? 'Envelope `{success: true, data, meta}`.',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => $successRef],
                    ],
                ],
            ],
            '422' => [
                'description' => 'Validation or domain precondition. May be ApiEnvelope (`error.code`) or Laravel `{message, errors}`.',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/ErrorOrValidation'],
                    ],
                ],
            ],
        ];

        if ($entry['authenticated']) {
            $responses['401'] = [
                'description' => 'Unauthenticated (Sanctum session missing or expired).',
            ];
        }

        if ($entry['admin']) {
            $responses['403'] = [
                'description' => 'Authenticated but not an admin (`admin` middleware).',
            ];
        }

        foreach ($overlay['responses'] ?? [] as $code => $response) {
            $responses[(string) $code] = $response;
        }
        ksort($responses);

        $operation = [
            'tags' => $overlay['tags'] ?? [$tag],
            'summary' => $summary,
            'description' => $description,
            'operationId' => $operationId,
            'parameters' => $parameters,
            'responses' => $responses,
        ];

        if (isset($overlay['requestBody'])) {
            $operation['requestBody'] = $overlay['requestBody'];
        } elseif (in_array($entry['method'], ['POST', 'PUT', 'PATCH'], true) && empty($overlay['noBody'])) {
            $operation['requestBody'] = [
                'required' => false,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'additionalProperties' => true,
                            'description' => 'JSON body as accepted by the current controller. Omitted properties are not implied.',
                        ],
                    ],
                ],
            ];
        }

        if ($entry['admin']) {
            $operation['x-stox-admin'] = true;
        }

        if (! $entry['authenticated']) {
            // Override the document-level Sanctum requirement for intentionally
            // public broker redirects that authenticate through signed state.
            $operation['security'] = [];
        }

        return $operation;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function pathParameters(string $path): array
    {
        preg_match_all('/\{([^}]+)\}/', $path, $matches);
        $params = [];
        foreach ($matches[1] as $name) {
            $params[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => [
                    'type' => $this->pathParamType($name),
                ],
            ];
        }

        return $params;
    }

    protected function pathParamType(string $name): string
    {
        if (in_array($name, ['type', 'id'], true) === false && preg_match('/id|Id|Request|Loan|Recall|Proceeds|Recommendation$/i', $name)) {
            return 'integer';
        }
        if (in_array($name, ['sourceId', 'capitalRequest', 'bridgeLoan', 'proceeds', 'recommendation', 'recall'], true)) {
            return 'integer';
        }
        if ($name === 'id') {
            return 'string';
        }

        return 'string';
    }

    /**
     * @param  array{method:string,path:string,action:string,admin:bool}  $entry
     */
    protected function defaultSummary(array $entry): string
    {
        $action = $entry['action'];
        if (str_contains($action, '@')) {
            $method = substr($action, strrpos($action, '@') + 1);

            return $entry['method'].' '.$method;
        }

        return $entry['method'].' '.$entry['path'];
    }

    /**
     * @param  array{method:string,path:string,action:string,admin:bool}  $entry
     */
    protected function defaultDescription(array $entry): string
    {
        $bits = ['Live route: `'.$entry['method'].' '.$entry['path'].'`.', 'Action: `'.$entry['action'].'`.'];
        if ($entry['admin']) {
            $bits[] = 'Requires admin (`is_admin`).';
        }

        return implode(' ', $bits);
    }

    /**
     * @param  array{method:string,path:string}  $entry
     */
    protected function operationId(array $entry): string
    {
        $slug = strtolower($entry['method']).'_'.trim(str_replace(['/api/v1/', '/', '{', '}'], ['_', '_', '', ''], $entry['path']), '_');
        $slug = preg_replace('/_+/', '_', $slug) ?? $slug;

        return $slug;
    }

    protected function defaultSuccessStatus(string $method): string
    {
        unset($method);

        return '200';
    }

    protected function tagForPath(string $path): string
    {
        $rest = preg_replace('#^/api/v1/#', '', $path) ?? $path;
        $first = explode('/', $rest)[0] ?? 'v1';

        return $first !== '' ? $first : 'v1';
    }

    /**
     * @return list<array{name:string,description:string}>
     */
    protected function tags(): array
    {
        $names = [];
        foreach ($this->v1Routes() as $entry) {
            $names[$this->tagForPath($entry['path'])] = true;
        }
        ksort($names);
        $out = [];
        foreach (array_keys($names) as $name) {
            $out[] = [
                'name' => $name,
                'description' => 'Routes under `/api/v1/'.$name.'`.',
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    protected function components(): array
    {
        return [
            'securitySchemes' => [
                'sanctumCookie' => [
                    'type' => 'apiKey',
                    'in' => 'cookie',
                    'name' => 'laravel_session',
                    'description' => 'Laravel Sanctum SPA session cookie after `POST /api/auth/login`. CSRF cookie `XSRF-TOKEN` / header `X-XSRF-TOKEN` required for cookie-authenticated mutating requests from browsers.',
                ],
            ],
            'parameters' => [
                'XProfileId' => [
                    'name' => 'X-Profile-Id',
                    'in' => 'header',
                    'required' => false,
                    'description' => 'Active portfolio id. Legacy alias: `X-Portfolio-Id`. Query `portfolio_id` is also accepted. Omitted → user default portfolio.',
                    'schema' => ['type' => 'integer'],
                ],
            ],
            'schemas' => [
                'EnvelopeSuccess' => [
                    'type' => 'object',
                    'required' => ['success', 'data', 'meta'],
                    'properties' => [
                        'success' => ['type' => 'boolean', 'enum' => [true]],
                        'data' => ['description' => 'Endpoint-specific payload.'],
                        'meta' => ['type' => 'object', 'additionalProperties' => true],
                    ],
                ],
                'EnvelopeError' => [
                    'type' => 'object',
                    'required' => ['success', 'error'],
                    'properties' => [
                        'success' => ['type' => 'boolean', 'enum' => [false]],
                        'error' => [
                            'type' => 'object',
                            'required' => ['code', 'message'],
                            'properties' => [
                                'code' => ['type' => 'string', 'example' => 'DATASET_NOT_FRESH'],
                                'message' => ['type' => 'string'],
                            ],
                        ],
                        'meta' => ['type' => 'object', 'additionalProperties' => true],
                    ],
                ],
                'LaravelValidationError' => [
                    'type' => 'object',
                    'properties' => [
                        'message' => ['type' => 'string'],
                        'errors' => [
                            'type' => 'object',
                            'additionalProperties' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
                'ErrorOrValidation' => [
                    'oneOf' => [
                        ['$ref' => '#/components/schemas/EnvelopeError'],
                        ['$ref' => '#/components/schemas/LaravelValidationError'],
                    ],
                ],
                'DatasetStatusData' => [
                    'type' => 'object',
                    'properties' => [
                        'published' => [
                            'type' => 'boolean',
                            'description' => 'True when the last successful daily market sync date is today in cron_timezone (inspection / UI). The decision pipeline does not gate on this boolean.',
                        ],
                        'dataset_version' => [
                            'type' => 'string',
                            'description' => 'Current immutable dataset version_key (V4-FEAT-023), or `none` if no successful version exists.',
                            'example' => 'ds-20260827140000-20260827',
                        ],
                        'securities_active' => ['type' => 'integer'],
                        'price_bars' => ['type' => 'integer'],
                        'latest_price_date' => ['type' => 'string', 'nullable' => true],
                        'daily_sync' => [
                            'type' => 'object',
                            'additionalProperties' => true,
                            'properties' => [
                                'synced_today' => ['type' => 'boolean'],
                                'sync_date' => ['type' => 'string', 'nullable' => true],
                                'synced_at' => ['type' => 'string', 'nullable' => true, 'description' => 'Last successful sync ISO-8601 timestamp (FEAT-022 freshness source).'],
                                'today' => ['type' => 'string'],
                                'timezone' => ['type' => 'string'],
                                'in_progress' => ['type' => 'boolean'],
                            ],
                        ],
                    ],
                ],
                'EnvelopeDatasetStatus' => [
                    'allOf' => [
                        ['$ref' => '#/components/schemas/EnvelopeSuccess'],
                        [
                            'type' => 'object',
                            'properties' => [
                                'data' => ['$ref' => '#/components/schemas/DatasetStatusData'],
                            ],
                        ],
                    ],
                ],
                'PaginationMeta' => [
                    'type' => 'object',
                    'required' => ['page', 'pageSize', 'total', 'lastPage'],
                    'properties' => [
                        'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Current 1-based page.'],
                        'pageSize' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Items per page after clamp.'],
                        'total' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Total matching rows.'],
                        'lastPage' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Last 1-based page (1 when total is 0).'],
                    ],
                ],
                'EnvelopePaginated' => [
                    'allOf' => [
                        ['$ref' => '#/components/schemas/EnvelopeSuccess'],
                        [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'object', 'additionalProperties' => true],
                                ],
                                'meta' => ['$ref' => '#/components/schemas/PaginationMeta'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    protected function middlewareNames(Route $route): array
    {
        $names = [];
        foreach ($route->gatherMiddleware() as $mw) {
            if (is_string($mw)) {
                $names[] = $mw;
            }
        }

        return $names;
    }

    /**
     * Lightweight OpenAPI 3.0 structural checks (no third-party validator in composer.json).
     *
     * @param  array<string, mixed>  $spec
     */
    public function assertValidDocument(array $spec): void
    {
        if (($spec['openapi'] ?? '') !== self::OPENAPI_VERSION) {
            throw new \RuntimeException('OpenAPI version must be '.self::OPENAPI_VERSION);
        }
        if (! is_string($spec['info']['title'] ?? null) || $spec['info']['title'] === '') {
            throw new \RuntimeException('OpenAPI info.title is required');
        }
        if (! is_string($spec['info']['version'] ?? null) || $spec['info']['version'] === '') {
            throw new \RuntimeException('OpenAPI info.version is required');
        }
        if (! is_array($spec['paths'] ?? null) || $spec['paths'] === []) {
            throw new \RuntimeException('OpenAPI paths must be a non-empty object');
        }
        if (! is_array($spec['components']['securitySchemes']['sanctumCookie'] ?? null)) {
            throw new \RuntimeException('OpenAPI must declare sanctumCookie security scheme');
        }
        $httpMethods = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];
        foreach ($spec['paths'] as $path => $ops) {
            if (! is_string($path) || ! str_starts_with($path, '/api/v1/')) {
                throw new \RuntimeException('Path is not under /api/v1: '.(string) $path);
            }
            if (! is_array($ops)) {
                throw new \RuntimeException('Path item is not an object: '.$path);
            }
            $hasOp = false;
            foreach ($ops as $method => $operation) {
                if (! in_array($method, $httpMethods, true)) {
                    continue;
                }
                $hasOp = true;
                if (! is_array($operation)) {
                    throw new \RuntimeException("Operation {$method} {$path} is not an object");
                }
                if (! is_array($operation['responses'] ?? null) || $operation['responses'] === []) {
                    throw new \RuntimeException("Operation {$method} {$path} has no responses");
                }
                foreach ($operation['responses'] as $code => $response) {
                    if (! preg_match('/^[1-5][0-9]{2}$/', (string) $code) && $code !== 'default') {
                        throw new \RuntimeException("Invalid response code {$code} on {$method} {$path}");
                    }
                    if (! is_array($response) || ! isset($response['description'])) {
                        throw new \RuntimeException("Response {$code} on {$method} {$path} needs a description");
                    }
                }
            }
            if (! $hasOp) {
                throw new \RuntimeException('Path has no HTTP operations: '.$path);
            }
        }
    }
}
