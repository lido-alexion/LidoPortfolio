<?php

namespace App\Services\Alerts;

use App\Models\Alert;
use App\Models\AlertPolicy;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Services\HoldingsCalculationService;
use App\Services\PortfolioLoggerService;
use InvalidArgumentException;

class AlertPolicyEvaluationService
{
    private const MAX_DETAILS = 100;

    public function __construct(
        protected HoldingsCalculationService $holdings,
        protected HoldingFieldRegistry $fields,
        protected FormulaEvaluator $formula,
        protected AlertMessageRenderer $messageRenderer,
        protected PortfolioLoggerService $logger,
    ) {}

    /**
     * @return array{profiles: int, policies: int, generated: int, skipped: int, holdings_checked: int}
     */
    public function evaluateAllProfiles(): array
    {
        $totals = [
            'profiles' => 0,
            'policies' => 0,
            'generated' => 0,
            'skipped' => 0,
            'holdings_checked' => 0,
        ];

        PortfolioProfile::query()->each(function (PortfolioProfile $profile) use (&$totals) {
            $result = $this->evaluateProfile($profile);
            $totals['profiles']++;
            $totals['policies'] += $result['policies'];
            $totals['generated'] += $result['generated'];
            $totals['skipped'] += $result['skipped'];
            $totals['holdings_checked'] += $result['holdings_checked'];
        });

        $this->logger->scheduler('info', 'Alert policies evaluated for all profiles', [
            'category' => 'AlertPolicy',
            ...$totals,
        ]);

        return $totals;
    }

    /**
     * @return array{
     *     policies: int,
     *     generated: int,
     *     skipped: int,
     *     holdings_checked: int,
     *     details: list<array<string, mixed>>,
     *     details_truncated: bool
     * }
     */
    public function evaluateProfile(PortfolioProfile $profile): array
    {
        $this->holdings->recalculateForProfile($profile);

        $policies = AlertPolicy::query()
            ->where('profile_id', $profile->id)
            ->where('is_enabled', true)
            ->orderBy('id')
            ->get();

        $generated = 0;
        $skipped = 0;
        $holdingsChecked = 0;
        $details = [];
        $detailsTruncated = false;

        $this->logger->alertPolicy('info', 'Evaluating alert policies for portfolio', [
            'profile_id' => $profile->id,
            'profile_name' => $profile->name,
            'policy_count' => $policies->count(),
        ]);

        foreach ($policies as $policy) {
            $result = $this->evaluatePolicy($profile, $policy);
            $generated += $result['generated'];
            $skipped += $result['skipped'];
            $holdingsChecked += $result['holdings_checked'];

            foreach ($result['details'] as $row) {
                if (count($details) >= self::MAX_DETAILS) {
                    $detailsTruncated = true;
                    break 2;
                }
                $details[] = $row;
            }
            if ($result['details_truncated']) {
                $detailsTruncated = true;
            }
        }

        $summary = [
            'policies' => $policies->count(),
            'generated' => $generated,
            'skipped' => $skipped,
            'holdings_checked' => $holdingsChecked,
            'details' => $details,
            'details_truncated' => $detailsTruncated,
        ];

        $this->logger->alertPolicy('info', 'Alert policy evaluation finished', [
            'profile_id' => $profile->id,
            'profile_name' => $profile->name,
            'policies' => $summary['policies'],
            'generated' => $summary['generated'],
            'skipped' => $summary['skipped'],
            'holdings_checked' => $summary['holdings_checked'],
            'details_truncated' => $detailsTruncated,
        ]);

        return $summary;
    }

    /**
     * @return array{
     *     generated: int,
     *     skipped: int,
     *     holdings_checked: int,
     *     details: list<array<string, mixed>>,
     *     details_truncated: bool
     * }
     */
    public function evaluatePolicy(PortfolioProfile $profile, AlertPolicy $policy): array
    {
        if ($policy->stock_universe !== 'holdings') {
            $this->logger->alertPolicy('warning', 'Unsupported stock universe for policy', [
                'policy_id' => $policy->id,
                'stock_universe' => $policy->stock_universe,
            ]);

            return [
                'generated' => 0,
                'skipped' => 0,
                'holdings_checked' => 0,
                'details' => [],
                'details_truncated' => false,
            ];
        }

        $holdings = $profile->holdings()
            ->with('stock')
            ->where('quantity', '>', 0)
            ->get();

        $generated = 0;
        $skipped = 0;
        $details = [];
        $detailsTruncated = false;

        if ($holdings->isEmpty()) {
            $this->logger->alertPolicy('info', 'No open holdings for policy evaluation', [
                'profile_id' => $profile->id,
                'policy_id' => $policy->id,
                'policy_name' => $policy->name,
            ]);
        }

        foreach ($holdings as $holding) {
            $row = $this->evaluateHoldingRow($profile, $policy, $holding);
            if (count($details) < self::MAX_DETAILS) {
                $details[] = $row;
            } else {
                $detailsTruncated = true;
            }

            $this->logger->alertPolicy('debug', 'Alert policy holding evaluated', $row);

            if ($row['outcome'] === 'generated') {
                $generated++;
            } else {
                $skipped++;
            }
        }

        return [
            'generated' => $generated,
            'skipped' => $skipped,
            'holdings_checked' => $holdings->count(),
            'details' => $details,
            'details_truncated' => $detailsTruncated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function evaluateHoldingRow(
        PortfolioProfile $profile,
        AlertPolicy $policy,
        Holding $holding,
    ): array {
        $symbol = $holding->stock?->symbol ?? '?';
        $base = [
            'policy_id' => $policy->id,
            'policy_name' => $policy->name,
            'stock_id' => $holding->stock_id,
            'stock_symbol' => $symbol,
            'condition_column' => $policy->condition_column,
            'condition_operator' => $policy->condition_operator,
            'compare_type' => $policy->compare_type,
        ];

        try {
            $flat = $this->fields->flattenHolding($profile, $holding);
            $left = $this->fields->resolveNumericValue($policy->condition_column, $flat);

            try {
                $right = $this->resolveCompareValue($policy, $flat);
            } catch (InvalidArgumentException $e) {
                return array_merge($base, [
                    'outcome' => 'formula_error',
                    'left' => $left,
                    'right' => null,
                    'summary' => "Formula error for {$symbol}: ".$e->getMessage(),
                ]);
            }

            if ($left === null) {
                return array_merge($base, [
                    'outcome' => 'missing_left',
                    'left' => null,
                    'right' => $right,
                    'summary' => "{$symbol}: {$policy->condition_column} has no numeric value (check price data).",
                ]);
            }

            if ($right === null) {
                $compareHint = $policy->compare_type === 'derived'
                    ? 'derived formula'
                    : ($policy->compare_column ?? 'constant');

                return array_merge($base, [
                    'outcome' => 'missing_right',
                    'left' => $left,
                    'right' => null,
                    'summary' => "{$symbol}: compare value ({$compareHint}) has no numeric value.",
                ]);
            }

            if (! $this->matchesOperator($left, $policy->condition_operator, $right)) {
                return array_merge($base, [
                    'outcome' => 'condition_not_met',
                    'left' => $left,
                    'right' => $right,
                    'summary' => sprintf(
                        '%s: %s (%s) %s %s (%s) — condition not met.',
                        $symbol,
                        $policy->condition_column,
                        $this->formatNum($left),
                        $policy->condition_operator,
                        $policy->compare_type === 'column' ? $policy->compare_column : $policy->compare_type,
                        $this->formatNum($right),
                    ),
                ]);
            }

            $instanceKey = $this->buildInstanceKey(
                (int) $profile->user_id,
                (int) $profile->id,
                (int) $holding->stock_id,
                (int) $policy->id,
            );

            if (Alert::query()
                ->where('profile_id', $profile->id)
                ->where('instance_key', $instanceKey)
                ->active()
                ->exists()) {
                return array_merge($base, [
                    'outcome' => 'duplicate_active',
                    'left' => $left,
                    'right' => $right,
                    'instance_key' => $instanceKey,
                    'summary' => "{$symbol}: active alert already exists for this policy (dedup).",
                ]);
            }

            $displayValues = $this->buildDisplayValues($flat);
            $numericVariables = $this->buildNumericVariables($flat);
            $message = $this->messageRenderer->render(
                $policy->message_template,
                $numericVariables,
                $displayValues,
            );
            $conditionDisplay = $this->buildConditionDisplay($policy, $flat, $left, $right);
            $context = $this->buildContext($policy->context_columns ?? [], $flat, $displayValues);

            Alert::query()->create([
                'profile_id' => $profile->id,
                'stock_id' => $holding->stock_id,
                'alert_policy_id' => $policy->id,
                'alert_type' => 'policy',
                'instance_key' => $instanceKey,
                'message' => $message,
                'condition_display' => $conditionDisplay,
                'action_suggested' => $this->formatAction($policy),
                'context_json' => $context,
                'is_sent' => false,
                'created_at' => now(),
            ]);

            return array_merge($base, [
                'outcome' => 'generated',
                'left' => $left,
                'right' => $right,
                'instance_key' => $instanceKey,
                'summary' => "{$symbol}: alert generated.",
            ]);
        } catch (\Throwable $e) {
            report($e);

            return array_merge($base, [
                'outcome' => 'error',
                'left' => null,
                'right' => null,
                'summary' => "{$symbol}: ".$e->getMessage(),
            ]);
        }
    }

    protected function formatNum(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    protected function resolveCompareValue(AlertPolicy $policy, array $flat): ?float
    {
        return match ($policy->compare_type) {
            'column' => $this->fields->resolveNumericValue((string) $policy->compare_column, $flat),
            'constant' => $policy->compare_constant !== null ? (float) $policy->compare_constant : null,
            'derived' => $this->evaluateDerived($policy->compare_formula ?? '', $flat),
            default => null,
        };
    }

    protected function evaluateDerived(string $formula, array $flat): ?float
    {
        $variables = [];
        foreach ($this->fields->allowedColumnKeys() as $key) {
            $variables[$key] = $this->fields->resolveNumericValue($key, $flat);
        }

        return $this->formula->evaluate($formula, $variables);
    }

    protected function matchesOperator(float $left, string $operator, float $right): bool
    {
        $epsilon = 0.0001;

        return match ($operator) {
            'gt' => $left > $right,
            'lt' => $left < $right,
            'eq' => abs($left - $right) <= $epsilon,
            default => false,
        };
    }

    public function buildInstanceKey(int $userId, int $profileId, int $stockId, int $policyId): string
    {
        return "{$userId}-{$profileId}-{$stockId}-{$policyId}";
    }

    /**
     * @return array<string, string>
     */
    protected function buildDisplayValues(array $flat): array
    {
        $display = [];
        foreach ($flat as $key => $value) {
            $display[$key] = $this->fields->formatValueForDisplay($key, $value);
        }

        return $display;
    }

    /**
     * @return array<string, float|null>
     */
    protected function buildNumericVariables(array $flat): array
    {
        $variables = [];
        foreach ($this->fields->allowedColumnKeys() as $key) {
            $variables[$key] = $this->fields->resolveNumericValue($key, $flat);
        }

        return $variables;
    }

    /**
     * @param  list<string>  $contextColumns
     * @return list<array{key: string, label: string, value: string}>
     */
    protected function buildContext(array $contextColumns, array $flat, array $displayValues): array
    {
        $labels = $this->fields->columnLabels();
        $context = [];

        foreach ($contextColumns as $key) {
            if (! in_array($key, $this->fields->allowedColumnKeys(), true)) {
                continue;
            }
            $context[] = [
                'key' => $key,
                'label' => $labels[$key] ?? $key,
                'value' => $displayValues[$key] ?? $this->fields->formatValueForDisplay($key, $flat[$key] ?? null),
            ];
        }

        return $context;
    }

    protected function buildConditionDisplay(
        AlertPolicy $policy,
        array $flat,
        float $left,
        float $right,
    ): string {
        $labels = $this->fields->columnLabels();
        $leftLabel = $labels[$policy->condition_column] ?? $policy->condition_column;
        $operatorLabel = match ($policy->condition_operator) {
            'gt' => '>',
            'lt' => '<',
            'eq' => '=',
            default => $policy->condition_operator,
        };

        if ($policy->compare_type === 'column') {
            $rightValue = $this->fields->formatValueForDisplay($policy->compare_column, $flat[$policy->compare_column] ?? $right);
            $rightPart = ($labels[$policy->compare_column] ?? $policy->compare_column).' ('.$rightValue.')';
        } elseif ($policy->compare_type === 'constant') {
            $rightPart = $this->fields->formatValueForDisplay('latest_close', $right);
        } elseif ($policy->compare_type === 'derived') {
            $rightPart = $this->formatDerivedCondition($policy->compare_formula ?? '', $flat);
        } else {
            $rightPart = (string) $right;
        }

        $leftValue = $this->fields->formatValueForDisplay($policy->condition_column, $flat[$policy->condition_column] ?? $left);

        return sprintf('%s (%s) %s %s', $leftLabel, $leftValue, $operatorLabel, $rightPart);
    }

    protected function formatDerivedCondition(string $formula, array $flat): string
    {
        $display = $this->buildDisplayValues($flat);

        return $this->formula->substituteTags($formula, $display);
    }

    protected function formatAction(AlertPolicy $policy): string
    {
        if ($policy->action_type === 'custom') {
            return trim((string) $policy->action_custom) ?: 'Custom';
        }

        return match ($policy->action_type) {
            'sell' => 'Sell',
            'buy' => 'Buy',
            'top_up' => 'Top-up',
            'downsize' => 'Downsize',
            'track' => 'Track',
            default => ucfirst(str_replace('_', ' ', $policy->action_type)),
        };
    }
}
