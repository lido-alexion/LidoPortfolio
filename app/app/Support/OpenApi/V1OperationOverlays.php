<?php

namespace App\Support\OpenApi;

/**
 * Source-derived summaries, query params, and request bodies for /api/v1 operations.
 * Keys: "METHOD /api/v1/...". Unknown keys are ignored; missing keys use builder defaults.
 */
final class V1OperationOverlays
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $json = static fn (array $schema, bool $required = true): array => [
            'required' => $required,
            'content' => [
                'application/json' => ['schema' => $schema],
            ],
        ];

        $q = static fn (string $name, string $type, string $description, bool $required = false): array => [
            'name' => $name,
            'in' => 'query',
            'required' => $required,
            'description' => $description,
            'schema' => ['type' => $type],
        ];

        $pageParams = static function (int $defaultPageSize, int $maxPageSize = 200) use ($q): array {
            return [
                $q('page', 'integer', '1-based page number (default 1).'),
                $q('pageSize', 'integer', "Page size (default {$defaultPageSize}, maximum {$maxPageSize}). Alias: per_page."),
                $q('per_page', 'integer', 'Alias of pageSize.'),
            ];
        };

        $envError = static fn (string $code, string $description): array => [
            'description' => $description,
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/EnvelopeError'],
                    'example' => [
                        'success' => false,
                        'error' => ['code' => $code, 'message' => $description],
                        'meta' => (object) [],
                    ],
                ],
            ],
        ];

        return [
            'GET /api/v1/dataset/status' => [
                'summary' => 'Dataset / daily-sync inspection status',
                'description' => 'Returns DataEngine datasetStatus(). `dataset_version` is the current immutable version_key (V4-FEAT-023) or `none`. `published` / `daily_sync.synced_today` mean successfully synced today in cron_timezone. Freshness for the decision pipeline uses `daily_sync.synced_at` (V4-FEAT-022), not `published`.',
                'successStatus' => '200',
                'successRef' => '#/components/schemas/EnvelopeDatasetStatus',
                'noBody' => true,
            ],
            'POST /api/v1/imports' => [
                'summary' => 'Trigger daily market import',
                'description' => 'Wraps DailyMarketSyncService::runDailySyncIfNeeded. Successful sync records an immutable dataset version. Incomplete/failed sync does not.',
                'requestBody' => $json([
                    'type' => 'object',
                    'properties' => [
                        'force' => ['type' => 'boolean', 'description' => 'Run even if already synced successfully today.'],
                    ],
                ], false),
                'successStatus' => '202',
                'successDescription' => '202 when accepted; some skip/failure paths return 200 with the same envelope (see DataEngine::triggerImport).',
                'responses' => [
                    '200' => [
                        'description' => 'Finished without a new accepted import (e.g. skipped). Envelope success.',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/EnvelopeSuccess']]],
                    ],
                ],
            ],
            'POST /api/v1/pipeline/run' => [
                'summary' => 'Run the daily decision pipeline',
                'description' => 'Discovery → Evaluation → Recommendations (optional notify/review). Hard-gated by dataset freshness (DatasetFreshnessGate) before Discovery (V4-FEAT-022). Stale/missing last-successful-sync timestamp → 422 DATASET_NOT_FRESH, no Discovery. Evaluation uses Strategy indicator parameter overrides (V4-FEAT-021) and Market Analysis regime mapping (V4-FEAT-005).',
                'requestBody' => $json([
                    'type' => 'object',
                    'properties' => [
                        'notify' => ['type' => 'boolean', 'description' => 'Default true (also accepted as query).'],
                        'review' => ['type' => 'boolean', 'description' => 'Default true (also accepted as query).'],
                    ],
                ], false),
                'parameters' => [
                    $q('notify', 'boolean', 'Send notifications (default true).'),
                    $q('review', 'boolean', 'Generate review report (default true).'),
                ],
                'successStatus' => '201',
                'responses' => [
                    '422' => $envError('DATASET_NOT_FRESH', 'Market dataset is not within the allowed freshness window. Pipeline stopped before Discovery.'),
                ],
            ],
            'POST /api/v1/evaluation/runs' => [
                'summary' => 'Run Evaluation on latest discovery candidates',
                'description' => 'Uses ensureActive Strategy config_json via EvaluationParameterResolver (V4-FEAT-021). Factor market_regime is 100/50/0 from Market Analysis categorical regime (V4-FEAT-005). Not freshness-gated.',
                'noBody' => true,
                'successStatus' => '201',
                'responses' => [
                    '422' => $envError('EVALUATION_PRECONDITION', 'No completed evaluation run or other EvaluationEngine RuntimeException mapped locally.'),
                ],
            ],
            'GET /api/v1/evaluation/runs' => [
                'summary' => 'List recent Evaluation runs',
                'description' => 'Returns newest-first portfolio-scoped run history for the merged Discovery/Evaluation UX (V4-FEAT-034). Bounded to 50 runs.',
                'parameters' => [
                    $q('limit', 'integer', 'Maximum runs to return (default 20, maximum 50).'),
                ],
            ],
            'POST /api/v1/discovery/runs' => [
                'summary' => 'Run Discovery',
                'description' => 'Not freshness-gated. DiscoveryRun.dataset_version is stamped with DataEngine::currentDatasetVersion() (immutable key when a version exists).',
                'noBody' => true,
                'successStatus' => '201',
            ],
            'POST /api/v1/recommendations/generate' => [
                'summary' => 'Generate recommendations from latest evaluation',
                'description' => 'Not freshness-gated. Completing a recommendation by fill is not this endpoint; use POST /api/transactions with recommendation_id (legacy /api).',
                'noBody' => true,
                'successStatus' => '201',
            ],
            'GET /api/v1/recommendations' => [
                'summary' => 'List recommendations',
                'description' => 'Paginated (V4-FEAT-028). Default pageSize 100 (the previous implicit cap). Query `page` / `pageSize` (alias `per_page`); meta `{page, pageSize, total, lastPage}`. Filtering/open-default statuses unchanged. `GET /recommendations/pending-execution` is not paginated.',
                'parameters' => array_merge([
                    $q('status', 'string', 'Comma-separated statuses. If omitted and open=1 (default) and all is not set, uses OPEN_LIST_STATUSES.'),
                    $q('open', 'boolean', 'Default true when status/all are absent.'),
                    $q('all', 'boolean', 'If true, do not default to open statuses.'),
                ], $pageParams(100)),
                'successStatus' => '200',
                'successRef' => '#/components/schemas/EnvelopePaginated',
                'noBody' => true,
            ],
            'GET /api/v1/recommendations/pending-execution' => [
                'summary' => 'Recommendations awaiting ledger fill',
                'description' => 'Approved / pending_execution queue. Fill is POST /api/transactions (legacy), not this path.',
                'successStatus' => '200',
                'noBody' => true,
            ],
            'POST /api/v1/recommendations/{id}/review' => [
                'summary' => 'Approve, reject, or defer a recommendation',
                'requestBody' => $json([
                    'type' => 'object',
                    'required' => ['decision'],
                    'properties' => [
                        'decision' => [
                            'type' => 'string',
                            'enum' => ['approved', 'accepted', 'rejected', 'deferred', 'APPROVED', 'ACCEPTED', 'REJECTED', 'DEFERRED'],
                        ],
                        'notes' => ['type' => 'string', 'maxLength' => 2000, 'nullable' => true],
                    ],
                ]),
                'successStatus' => '200',
                'responses' => [
                    '404' => $envError('NOT_FOUND', 'Recommendation not found.'),
                ],
            ],
            'POST /api/v1/recommendations/{id}/reopen' => [
                'summary' => 'Reopen for review',
                'requestBody' => $json([
                    'type' => 'object',
                    'properties' => [
                        'notes' => ['type' => 'string', 'maxLength' => 2000, 'nullable' => true],
                    ],
                ], false),
                'successStatus' => '200',
            ],
            'POST /api/v1/recommendations/{id}/cancel-execution' => [
                'summary' => 'Cancel pending execution',
                'requestBody' => $json([
                    'type' => 'object',
                    'properties' => [
                        'reason' => ['type' => 'string'],
                        'notes' => ['type' => 'string', 'maxLength' => 2000, 'nullable' => true],
                    ],
                ], false),
                'successStatus' => '200',
            ],
            'POST /api/v1/recommendations/{id}/expire' => [
                'summary' => 'Manually expire a recommendation',
                'requestBody' => $json([
                    'type' => 'object',
                    'properties' => [
                        'notes' => ['type' => 'string', 'maxLength' => 2000, 'nullable' => true],
                    ],
                ], false),
                'successStatus' => '200',
            ],
            'GET /api/v1/candidates' => [
                'summary' => 'List discovery candidates',
                'description' => 'Latest completed discovery run for the portfolio (optional discovery_run_id). Intentionally bounded (strategy max_candidates); not paginated (V4-FEAT-028). Rank-sorted in PHP after Evaluation.',
                'parameters' => [
                    $q('discovery_run_id', 'integer', 'Optional run id; otherwise latest completed for the portfolio.'),
                    $q('source', 'string', 'Filter by candidate source.'),
                    $q('search', 'string', 'Symbol/name search.'),
                ],
                'successStatus' => '200',
                'noBody' => true,
            ],
            'GET /api/v1/evaluations' => [
                'summary' => 'List evaluation results',
                'description' => 'Latest evaluation run (optional evaluation_run_id). Intentionally bounded to that run; not paginated (V4-FEAT-028).',
                'parameters' => [
                    $q('evaluation_run_id', 'integer', 'Optional evaluation run id.'),
                ],
                'successStatus' => '200',
                'noBody' => true,
            ],
            'GET /api/v1/securities' => [
                'summary' => 'List securities',
                'description' => 'Paginated (V4-FEAT-028). Default pageSize 50, maximum 200. Meta `{page, pageSize, total, lastPage}`.',
                'parameters' => array_merge([
                    $q('search', 'string', 'Symbol/name filter.'),
                ], $pageParams(50)),
                'successStatus' => '200',
                'successRef' => '#/components/schemas/EnvelopePaginated',
                'noBody' => true,
            ],
            'GET /api/v1/price-bars' => [
                'summary' => 'Query OHLCV bars',
                'description' => 'Paginated (V4-FEAT-028). Default pageSize 100, maximum 500. Meta `{page, pageSize, total, lastPage}`.',
                'parameters' => array_merge([
                    $q('security_id', 'integer', 'Required stock id (alias securityId).', true),
                    $q('securityId', 'integer', 'Alias of security_id.'),
                    $q('from', 'string', 'From date.'),
                    $q('to', 'string', 'To date.'),
                ], $pageParams(100, 500)),
                'successStatus' => '200',
                'successRef' => '#/components/schemas/EnvelopePaginated',
                'noBody' => true,
            ],
            'GET /api/v1/orders' => [
                'summary' => 'List TOS orders',
                'description' => 'Paginated (V4-FEAT-028). Default pageSize 50 (previous implicit cap), maximum 200. Optional status filter preserved.',
                'parameters' => array_merge([
                    $q('status', 'string', 'Optional order status filter (pending, executed, cancelled).'),
                ], $pageParams(50)),
                'successStatus' => '200',
                'successRef' => '#/components/schemas/EnvelopePaginated',
                'noBody' => true,
            ],
            'GET /api/v1/transactions' => [
                'summary' => 'List TOS ledger transactions for the active portfolio',
                'description' => 'Paginated (V4-FEAT-028). Default pageSize 100 (previous implicit cap), maximum 200. Distinct from legacy `GET /api/transactions`. Ledger rows may include `owner_key` when SELL attribution was stored (V4-SPEC-005).',
                'parameters' => $pageParams(100),
                'successStatus' => '200',
                'successRef' => '#/components/schemas/EnvelopePaginated',
                'noBody' => true,
            ],
            'GET /api/v1/notifications' => [
                'summary' => 'List TOS notification history',
                'description' => 'Paginated (V4-FEAT-028). Default pageSize 50 (previous implicit cap), maximum 200.',
                'parameters' => $pageParams(50),
                'successStatus' => '200',
                'successRef' => '#/components/schemas/EnvelopePaginated',
                'noBody' => true,
            ],
            'GET /api/v1/reviews' => [
                'summary' => 'List review reports',
                'description' => 'Paginated (V4-FEAT-028). Default pageSize 20 (previous implicit cap), maximum 200. Dashboard/outcomes remain unpaginated aggregates.',
                'parameters' => $pageParams(20),
                'successStatus' => '200',
                'successRef' => '#/components/schemas/EnvelopePaginated',
                'noBody' => true,
            ],
            'POST /api/v1/orders' => [
                'summary' => 'Create a TOS order (legacy / BC)',
                'description' => 'Prefer recording a ledger transaction with recommendation_id. Default execute_now is false.',
                'requestBody' => $json([
                    'type' => 'object',
                    'required' => ['side', 'quantity'],
                    'properties' => [
                        'side' => ['type' => 'string', 'enum' => ['buy', 'sell', 'BUY', 'SELL']],
                        'quantity' => ['type' => 'number'],
                        'price' => ['type' => 'number', 'nullable' => true],
                        'fees' => ['type' => 'number', 'nullable' => true],
                        'transaction_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                        'notes' => ['type' => 'string', 'nullable' => true],
                        'recommendation_id' => ['type' => 'integer', 'nullable' => true],
                        'security_id' => ['type' => 'integer', 'nullable' => true],
                        'stock_id' => ['type' => 'integer', 'nullable' => true],
                        'symbol' => ['type' => 'string', 'nullable' => true],
                        'execute_now' => ['type' => 'boolean', 'nullable' => true],
                        'limit_price' => ['type' => 'number', 'nullable' => true],
                    ],
                ]),
                'successStatus' => '201',
            ],
            'POST /api/v1/orders/{id}/execute' => [
                'summary' => 'Execute a pending TOS order',
                'description' => 'Marks the recommendation executed via RecommendationEngine::markExecuted when the order is linked to an actionable recommendation (V4-FEAT-024).',
                'requestBody' => $json([
                    'type' => 'object',
                    'required' => ['price'],
                    'properties' => [
                        'price' => ['type' => 'number'],
                        'fees' => ['type' => 'number', 'nullable' => true],
                        'transaction_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                        'notes' => ['type' => 'string', 'nullable' => true],
                    ],
                ]),
                'successStatus' => '200',
            ],
            'GET /api/v1/strategy' => [
                'summary' => 'Active/editor strategy (optional strategy_id)',
                'parameters' => [
                    $q('strategy_id', 'integer', 'Editor selection. Omitted → first enabled / factory.'),
                ],
                'successStatus' => '200',
                'noBody' => true,
            ],
            'PUT /api/v1/strategy' => [
                'summary' => 'Save strategy configuration in place',
                'requestBody' => $json([
                    'type' => 'object',
                    'required' => ['config'],
                    'properties' => [
                        'strategy_id' => ['type' => 'integer', 'nullable' => true],
                        'name' => ['type' => 'string', 'nullable' => true],
                        'description' => ['type' => 'string', 'nullable' => true],
                        'change_notes' => ['type' => 'string', 'nullable' => true],
                        'config' => ['type' => 'object', 'additionalProperties' => true],
                    ],
                ]),
                'successStatus' => '200',
            ],
            'PUT /api/v1/strategy/screeners' => [
                'summary' => 'Assign eligibility screeners',
                'requestBody' => $json([
                    'type' => 'object',
                    'required' => ['eligibility_sources'],
                    'properties' => [
                        'eligibility_sources' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'required' => ['screener_id'],
                                'properties' => [
                                    'screener_id' => ['type' => 'integer'],
                                    'enabled' => ['type' => 'boolean', 'nullable' => true],
                                    'priority' => ['type' => 'integer', 'nullable' => true],
                                    'display_order' => ['type' => 'integer', 'nullable' => true],
                                ],
                            ],
                        ],
                    ],
                ]),
                'successStatus' => '200',
            ],
            'POST /api/v1/strategy-registry' => [
                'summary' => 'Create a strategy (from-default or artifact JSON)',
                'description' => 'Body `{name, description}` (no JSON pack) clones the factory/default as a draft. Artifact envelope import remains supported on this same path when a pack is posted.',
                'requestBody' => $json([
                    'type' => 'object',
                    'additionalProperties' => true,
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'description' => ['type' => 'string', 'nullable' => true],
                    ],
                ], false),
                'successStatus' => '201',
            ],
            'PUT /api/v1/capital/allocations' => [
                'summary' => 'Update enabled-strategy allocation percents',
                'description' => 'Requires every enabled strategy; sum must be 100 ± 0.01. No auto-normalize.',
                'requestBody' => $json([
                    'type' => 'object',
                    'required' => ['allocations'],
                    'properties' => [
                        'allocations' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'object',
                                'required' => ['strategy_id', 'allocation_pct'],
                                'properties' => [
                                    'strategy_id' => ['type' => 'integer'],
                                    'allocation_pct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                                ],
                            ],
                        ],
                    ],
                ]),
                'successStatus' => '200',
            ],
            'PUT /api/v1/capital/reserve-pct' => [
                'summary' => 'Set portfolio cash reserve percent',
                'requestBody' => $json([
                    'type' => 'object',
                    'required' => ['portfolio_cash_reserve_pct'],
                    'properties' => [
                        'portfolio_cash_reserve_pct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                    ],
                ]),
                'successStatus' => '200',
            ],
            'POST /api/v1/capital/requests/{capitalRequest}/approve' => [
                'summary' => 'Approve a capital request as lender',
                'requestBody' => $json([
                    'type' => 'object',
                    'required' => ['lender_strategy_id'],
                    'properties' => [
                        'lender_strategy_id' => ['type' => 'integer'],
                    ],
                ]),
                'successStatus' => '200',
            ],
            'POST /api/v1/capital/recalls' => [
                'summary' => 'Request a capital recall',
                'requestBody' => $json([
                    'type' => 'object',
                    'required' => ['loan_id', 'kind'],
                    'properties' => [
                        'loan_id' => ['type' => 'integer'],
                        'kind' => ['type' => 'string', 'enum' => ['full', 'partial']],
                        'amount' => ['type' => 'number', 'nullable' => true],
                        'lender_strategy_id' => ['type' => 'integer', 'nullable' => true],
                    ],
                ]),
                'successStatus' => '201',
            ],
            'POST /api/v1/capital/bridge-loans' => [
                'summary' => 'Manual Recall Bridge Loan create (rejected)',
                'description' => 'Always 405. Bridge loans are created only by the recall settlement workflow.',
                'noBody' => true,
                'successStatus' => '405',
                'successRef' => '#/components/schemas/EnvelopeError',
                'successDescription' => '405 FORBIDDEN — cannot be created manually.',
            ],
            'POST /api/v1/capital/pending-sale-proceeds/{proceeds}/mark-available' => [
                'summary' => 'Manual mark-available (rejected)',
                'description' => 'Always 405. Settlement processing controls availability.',
                'noBody' => true,
                'successStatus' => '405',
                'successRef' => '#/components/schemas/EnvelopeError',
                'successDescription' => '405 FORBIDDEN — cannot be marked available manually.',
            ],
            'POST /api/v1/capital/resolve' => [
                'summary' => 'Run capital resolution',
                'requestBody' => $json([
                    'type' => 'object',
                    'required' => ['strategy_id', 'required_amount'],
                    'properties' => [
                        'strategy_id' => ['type' => 'integer'],
                        'required_amount' => ['type' => 'number'],
                        'recommendation_id' => ['type' => 'integer', 'nullable' => true],
                        'bridge_lender_strategy_id' => ['type' => 'integer', 'nullable' => true],
                    ],
                ]),
                'successStatus' => '200',
            ],
            'PUT /api/v1/capital/recall-period' => [
                'summary' => 'Set or clear portfolio recall-period override',
                'requestBody' => $json([
                    'type' => 'object',
                    'properties' => [
                        'portfolio_recall_period_days' => ['type' => 'integer', 'nullable' => true, 'minimum' => 0, 'maximum' => 3650],
                        'clear_override' => ['type' => 'boolean'],
                    ],
                ], false),
                'successStatus' => '200',
            ],
            'POST /api/v1/backtests' => [
                'summary' => 'Start a strategy backtest',
                'requestBody' => $json([
                    'type' => 'object',
                    'required' => ['session_token'],
                    'properties' => [
                        'name' => ['type' => 'string', 'nullable' => true],
                        'range_key' => ['type' => 'string', 'nullable' => true],
                        'from_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                        'to_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                        'initial_capital' => ['type' => 'number', 'nullable' => true],
                        'notes' => ['type' => 'string', 'nullable' => true],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'session_token' => ['type' => 'string'],
                        'strategy_version_id' => ['type' => 'integer', 'nullable' => true],
                    ],
                ]),
                'successStatus' => '200',
            ],
            'PUT /api/v1/backtests/{id}' => [
                'summary' => 'Update backtest metadata',
                'requestBody' => $json([
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'nullable' => true],
                        'notes' => ['type' => 'string', 'nullable' => true],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ], false),
                'successStatus' => '200',
            ],
            'POST /api/v1/artifacts/export' => [
                'summary' => 'Export an artifact package',
                'requestBody' => $json([
                    'type' => 'object',
                    'required' => ['targets'],
                    'properties' => [
                        'targets' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'object',
                                'required' => ['type', 'id'],
                                'properties' => [
                                    'type' => ['type' => 'string', 'enum' => ['indicator', 'screener', 'strategy']],
                                    'id' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ]),
                'successStatus' => '200',
            ],
            'GET /api/v1/indicators' => [
                'summary' => 'Admin Indicator Registry list',
                'description' => 'Requires admin middleware.',
                'successStatus' => '200',
                'noBody' => true,
            ],
            'POST /api/v1/reviews/generate' => [
                'summary' => 'Generate a review report',
                'parameters' => [
                    $q('period_start', 'string', 'Optional period start (date).'),
                    $q('period_end', 'string', 'Optional period end (date).'),
                ],
                'noBody' => true,
                'successStatus' => '201',
            ],
            'POST /api/v1/screener-registry' => [
                'summary' => 'Create a screener artifact',
                'successStatus' => '201',
            ],
            'POST /api/v1/screener-registry/import' => [
                'summary' => 'Import a screener artifact pack',
                'successStatus' => '201',
            ],
            'POST /api/v1/screener-registry/shared/{sourceId}/import' => [
                'summary' => 'Import a shared screener into this portfolio',
                'successStatus' => '201',
            ],
            'POST /api/v1/strategy-registry/import' => [
                'summary' => 'Import a strategy artifact pack',
                'successStatus' => '201',
            ],
            'POST /api/v1/artifacts/{type}' => [
                'summary' => 'Create an artifact of the given type',
                'description' => 'Indicator create requires admin. Success 201.',
                'successStatus' => '201',
            ],
            'GET /api/v1/analytics/stocks/{stock}/recommendation-preview' => [
                'summary' => 'Recommendation preview for a stock (F137)',
                'parameters' => [
                    $q('strategy_id', 'integer', 'Optional strategy id.'),
                ],
                'successStatus' => '200',
                'noBody' => true,
            ],
            'GET /api/v1/totp' => [
                'summary' => 'TOTP authenticator status',
                'description' => 'Returns whether authenticator TOTP is enabled or pending. Never returns the stored secret after enrollment (V4-FEAT-001).',
                'successStatus' => '200',
                'noBody' => true,
            ],
            'POST /api/v1/totp/begin' => [
                'summary' => 'Start TOTP enrollment',
                'description' => 'Creates a pending TOTP secret and returns otpauth URL, QR SVG, and secret for authenticator setup. Enrollment is not active until confirm.',
                'successStatus' => '200',
                'noBody' => true,
            ],
            'POST /api/v1/totp/confirm' => [
                'summary' => 'Confirm TOTP enrollment',
                'description' => 'Activates TOTP after a valid authenticator code. Returns one-time recovery codes. The stored secret is not returned.',
                'requestBody' => $json([
                    'type' => 'object',
                    'required' => ['code'],
                    'properties' => [
                        'code' => ['type' => 'string', 'description' => 'Current authenticator code. Never logged.'],
                    ],
                ]),
                'successStatus' => '200',
            ],
            'POST /api/v1/totp/verify' => [
                'summary' => 'Verify a TOTP or recovery code',
                'description' => 'Rate-limited. Replay of a used TOTP inside the validity window is rejected. Recovery codes are single-use when recovery=true.',
                'requestBody' => $json([
                    'type' => 'object',
                    'required' => ['code'],
                    'properties' => [
                        'code' => ['type' => 'string'],
                        'recovery' => ['type' => 'boolean'],
                    ],
                ]),
                'successStatus' => '200',
            ],
            'POST /api/v1/totp/recover' => [
                'summary' => 'Consume a single-use recovery code',
                'requestBody' => $json([
                    'type' => 'object',
                    'required' => ['code'],
                    'properties' => [
                        'code' => ['type' => 'string'],
                    ],
                ]),
                'successStatus' => '200',
            ],
            'POST /api/v1/totp/disable' => [
                'summary' => 'Disable TOTP',
                'description' => 'Requires a valid authenticator or recovery code. Clears secret and remaining recovery codes.',
                'requestBody' => $json([
                    'type' => 'object',
                    'required' => ['code'],
                    'properties' => [
                        'code' => ['type' => 'string'],
                        'recovery' => ['type' => 'boolean'],
                    ],
                ]),
                'successStatus' => '200',
            ],
            'GET /api/v1/execution/mode' => [
                'summary' => 'Portfolio execution mode and live-submit blockers',
                'description' => 'Manual / semi_automatic / automatic plus entitlement, TOTP, and broker blockers. Mode is per portfolio; entitlement is per user.',
                'successStatus' => '200',
                'noBody' => true,
            ],
            'PUT /api/v1/execution/mode' => [
                'summary' => 'Change portfolio execution mode',
                'description' => 'Manual requires no TOTP. Semi-Automatic and Automatic require entitlement plus a valid TOTP (or recovery) code. Manual→Automatic also requires confirm_automatic. Automatic→Manual does not cancel in-flight broker orders.',
                'requestBody' => $json([
                    'type' => 'object',
                    'required' => ['execution_mode'],
                    'properties' => [
                        'execution_mode' => ['type' => 'string', 'enum' => ['manual', 'semi_automatic', 'automatic']],
                        'confirm_automatic' => ['type' => 'boolean'],
                        'totp' => ['type' => 'string'],
                        'recovery_code' => ['type' => 'string'],
                    ],
                ]),
                'successStatus' => '200',
            ],
            'POST /api/v1/execution/submit-selected' => [
                'summary' => 'Semi-Automatic broker submit',
                'description' => 'Explicit Accept/Execute Selected. Server enforces user, portfolio, entitlement, TOTP, eligibility, capital/reservation, and broker session, then submits to Zerodha. Broker acceptance is not a ledger fill.',
                'requestBody' => $json([
                    'type' => 'object',
                    'required' => ['recommendation_ids'],
                    'properties' => [
                        'recommendation_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'totp' => ['type' => 'string'],
                        'recovery_code' => ['type' => 'string'],
                    ],
                ]),
                'successStatus' => '200',
            ],
            'POST /api/v1/orders/{id}/reconcile' => [
                'summary' => 'Reconcile a broker order',
                'description' => 'Polls broker status and applies only newly filled quantity to the ledger. Partial fill does not mark the recommendation executed.',
                'successStatus' => '200',
                'noBody' => true,
            ],
            'GET /api/v1/broker/status' => [
                'summary' => 'Zerodha/Kite connection status',
                'description' => 'Per-user Kite Connect session. Access tokens are never returned.',
                'successStatus' => '200',
                'noBody' => true,
            ],
            'GET /api/v1/broker/kite/login-url' => [
                'summary' => 'Kite Connect login URL',
                'successStatus' => '200',
                'noBody' => true,
            ],
            'GET /api/v1/broker/kite/callback' => [
                'summary' => 'Kite Connect OAuth callback',
                'description' => 'Public broker redirect authenticated by a short-lived encrypted state token, not by the SPA Sanctum cookie. Exchanges request_token for an access token and redirects to Settings → Account. Query request_token is not logged.',
                'parameters' => [
                    $q('request_token', 'string', 'Short-lived request token returned by Kite.'),
                    $q('state', 'string', 'Encrypted state created when the user initiated the connection.'),
                ],
                'successStatus' => '200',
                'noBody' => true,
            ],
            'POST /api/v1/broker/kite/session' => [
                'summary' => 'Complete Kite session from request_token',
                'requestBody' => $json([
                    'type' => 'object',
                    'required' => ['request_token'],
                    'properties' => [
                        'request_token' => ['type' => 'string'],
                    ],
                ]),
                'successStatus' => '200',
            ],
            'POST /api/v1/broker/kite/disconnect' => [
                'summary' => 'Disconnect Kite',
                'successStatus' => '200',
                'noBody' => true,
            ],
            'GET /api/v1/protections' => [
                'summary' => 'List Strategy position GTT protections',
                'description' => 'V4-FEAT-002. At most one open Target or Stop-Loss per Strategy position. Filter by holding_id or stock_id. Broker acceptance is not a fill.',
                'parameters' => [
                    $q('holding_id', 'integer', 'Optional holding id.'),
                    $q('stock_id', 'integer', 'Optional stock id.'),
                ],
                'successStatus' => '200',
                'noBody' => true,
            ],
            'GET /api/v1/protections/{id}' => [
                'summary' => 'Show one position protection',
                'description' => 'Scoped to the active portfolio. Includes state (pending, active, synchronizing, needs_attention, cancelled, reconciled).',
                'successStatus' => '200',
                'noBody' => true,
            ],
            'POST /api/v1/protections' => [
                'summary' => 'Place or replace GTT Target or Stop-Loss',
                'description' => 'Semi-Automatic attended placement. Requires automated-execution entitlement, TOTP, and a usable Kite session. Price is taken from Strategy target (target_amount/quantity) or stop (OD-13). Placing one type replaces the other. Does not write ledger, cash, capital, or lending. Manual mode is rejected.',
                'requestBody' => $json([
                    'type' => 'object',
                    'required' => ['holding_id', 'type'],
                    'properties' => [
                        'holding_id' => ['type' => 'integer'],
                        'type' => ['type' => 'string', 'enum' => ['target', 'stop']],
                        'totp' => ['type' => 'string', 'nullable' => true, 'description' => 'Authenticator code. Never logged.'],
                        'recovery_code' => ['type' => 'string', 'nullable' => true, 'description' => 'Single-use recovery code. Never logged.'],
                    ],
                ]),
                'successStatus' => '201',
            ],
            'POST /api/v1/protections/{id}/cancel' => [
                'summary' => 'Cancel broker protection for a Strategy position',
                'description' => 'Semi-Automatic attended cancel. Cancels the broker GTT when known. Does not create a ledger fill.',
                'requestBody' => $json([
                    'type' => 'object',
                    'properties' => [
                        'totp' => ['type' => 'string', 'nullable' => true],
                        'recovery_code' => ['type' => 'string', 'nullable' => true],
                    ],
                ]),
                'successStatus' => '200',
            ],
            'POST /api/v1/protections/{id}/reconcile' => [
                'summary' => 'Reconcile one GTT protection with the broker',
                'description' => 'Ingests GTT fills through the existing ledger path, then retries synchronization. Partial GTT sells are filled first; remaining quantity is synchronized on a later cycle. Ambiguous broker outcomes do not place a duplicate.',
                'successStatus' => '200',
                'noBody' => true,
            ],
            'PUT /api/v1/admin/users/{user}/automated-execution-entitlement' => [
                'summary' => 'Admin: set automated-execution entitlement',
                'description' => 'Per-user, disabled by default. Required for Semi-Automatic and Automatic broker submission. Not inferred from portfolio mode.',
                'requestBody' => $json([
                    'type' => 'object',
                    'required' => ['entitled'],
                    'properties' => [
                        'entitled' => ['type' => 'boolean'],
                    ],
                ]),
                'successStatus' => '200',
            ],
        ];
    }
}
