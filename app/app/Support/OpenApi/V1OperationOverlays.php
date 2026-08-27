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
                'parameters' => [
                    $q('status', 'string', 'Comma-separated statuses. If omitted and open=1 (default) and all is not set, uses OPEN_LIST_STATUSES.'),
                    $q('open', 'boolean', 'Default true when status/all are absent.'),
                    $q('all', 'boolean', 'If true, do not default to open statuses.'),
                ],
                'successStatus' => '200',
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
                'parameters' => [
                    $q('evaluation_run_id', 'integer', 'Optional evaluation run id.'),
                ],
                'successStatus' => '200',
                'noBody' => true,
            ],
            'GET /api/v1/securities' => [
                'summary' => 'List securities',
                'parameters' => [
                    $q('search', 'string', 'Symbol/name filter.'),
                    $q('pageSize', 'integer', 'Page size (alias per_page).'),
                    $q('per_page', 'integer', 'Page size alias.'),
                    $q('page', 'integer', 'Page number.'),
                ],
                'successStatus' => '200',
                'noBody' => true,
            ],
            'GET /api/v1/price-bars' => [
                'summary' => 'Query OHLCV bars',
                'parameters' => [
                    $q('security_id', 'integer', 'Required stock id (alias securityId).', true),
                    $q('securityId', 'integer', 'Alias of security_id.'),
                    $q('from', 'string', 'From date.'),
                    $q('to', 'string', 'To date.'),
                    $q('pageSize', 'integer', 'Page size.'),
                    $q('page', 'integer', 'Page number.'),
                ],
                'successStatus' => '200',
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
        ];
    }
}
