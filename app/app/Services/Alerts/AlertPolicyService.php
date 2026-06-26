<?php

namespace App\Services\Alerts;

use App\Models\AlertPolicy;
use App\Models\PortfolioProfile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AlertPolicyService
{
    public function __construct(
        protected HoldingFieldRegistry $fields,
        protected AlertPolicyTemplateValidator $templateValidator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return [
            'stock_universes' => [
                ['value' => 'holdings', 'label' => 'Holdings'],
            ],
            'condition_operators' => [
                ['value' => 'gt', 'label' => 'Greater than'],
                ['value' => 'lt', 'label' => 'Less than'],
                ['value' => 'eq', 'label' => 'Equals'],
            ],
            'compare_types' => [
                ['value' => 'column', 'label' => 'Column'],
                ['value' => 'derived', 'label' => 'Derived'],
                ['value' => 'constant', 'label' => 'Constant'],
            ],
            'action_types' => [
                ['value' => 'sell', 'label' => 'Sell'],
                ['value' => 'buy', 'label' => 'Buy'],
                ['value' => 'top_up', 'label' => 'Top-up'],
                ['value' => 'downsize', 'label' => 'Downsize'],
                ['value' => 'track', 'label' => 'Track'],
                ['value' => 'custom', 'label' => 'Custom'],
            ],
            'columns' => $this->fields->columnDefinitions(),
        ];
    }

    /**
     * @return Collection<int, AlertPolicy>
     */
    public function listForProfile(PortfolioProfile $profile): Collection
    {
        return AlertPolicy::query()
            ->where('profile_id', $profile->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(PortfolioProfile $profile, array $data): AlertPolicy
    {
        $validated = $this->validatePayload($data, null, $profile);

        if (AlertPolicy::query()->where('profile_id', $profile->id)->where('name', $validated['name'])->exists()) {
            throw ValidationException::withMessages(['name' => ['Alert name must be unique in this portfolio.']]);
        }

        return AlertPolicy::query()->create(array_merge($validated, [
            'profile_id' => $profile->id,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(AlertPolicy $policy, array $data): AlertPolicy
    {
        if ($policy->is_system) {
            throw ValidationException::withMessages([
                'policy' => ['System policies cannot be edited.'],
            ]);
        }

        $validated = $this->validatePayload($data, $policy, $policy->profile);
        $policy->update($validated);

        return $policy->fresh();
    }

    public function delete(AlertPolicy $policy): void
    {
        if ($policy->is_system) {
            throw ValidationException::withMessages([
                'policy' => ['System policies cannot be deleted.'],
            ]);
        }

        $policy->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validatePayload(array $data, ?AlertPolicy $existing = null, ?PortfolioProfile $profile = null): array
    {
        $allowedKeys = $this->fields->allowedColumnKeys();

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Alert name is required.']]);
        }

        $profileId = $existing?->profile_id;
        if ($profileId) {
            $exists = AlertPolicy::query()
                ->where('profile_id', $profileId)
                ->where('name', $name)
                ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages(['name' => ['Alert name must be unique in this portfolio.']]);
            }
        }

        $stockUniverse = $data['stock_universe'] ?? 'holdings';
        if ($stockUniverse !== 'holdings') {
            throw ValidationException::withMessages(['stock_universe' => ['Unsupported stock universe.']]);
        }

        $alertDefinition = trim((string) ($data['alert_definition'] ?? ''));
        $alertDefinition = $alertDefinition !== '' ? $alertDefinition : null;
        if ($alertDefinition !== null && strlen($alertDefinition) > 2000) {
            throw ValidationException::withMessages(['alert_definition' => ['Alert definition must be 2000 characters or fewer.']]);
        }

        $conditionColumn = (string) ($data['condition_column'] ?? '');
        if (! in_array($conditionColumn, $allowedKeys, true)) {
            throw ValidationException::withMessages(['condition_column' => ['Invalid condition column.']]);
        }

        $operator = (string) ($data['condition_operator'] ?? '');
        if (! in_array($operator, ['gt', 'lt', 'eq'], true)) {
            throw ValidationException::withMessages(['condition_operator' => ['Invalid condition operator.']]);
        }

        $compareType = (string) ($data['compare_type'] ?? '');
        if (! in_array($compareType, ['column', 'derived', 'constant'], true)) {
            throw ValidationException::withMessages(['compare_type' => ['Invalid compare type.']]);
        }

        $compareColumn = null;
        $compareFormula = null;
        $compareConstant = null;

        if ($compareType === 'column') {
            $compareColumn = (string) ($data['compare_column'] ?? '');
            if (! in_array($compareColumn, $allowedKeys, true)) {
                throw ValidationException::withMessages(['compare_column' => ['Invalid compare column.']]);
            }
        } elseif ($compareType === 'derived') {
            $compareFormula = trim((string) ($data['compare_formula'] ?? ''));
            if ($compareFormula === '') {
                throw ValidationException::withMessages(['compare_formula' => ['Formula is required for derived compare value.']]);
            }
        } else {
            if (! isset($data['compare_constant']) || ! is_numeric($data['compare_constant'])) {
                throw ValidationException::withMessages(['compare_constant' => ['Constant value is required.']]);
            }
            $compareConstant = (float) $data['compare_constant'];
        }

        $messageTemplate = trim((string) ($data['message_template'] ?? ''));
        if ($messageTemplate === '') {
            throw ValidationException::withMessages(['message_template' => ['Alert message is required.']]);
        }

        $actionType = (string) ($data['action_type'] ?? '');
        if (! in_array($actionType, ['sell', 'buy', 'top_up', 'downsize', 'track', 'custom'], true)) {
            throw ValidationException::withMessages(['action_type' => ['Invalid action type.']]);
        }

        $actionCustom = null;
        if ($actionType === 'custom') {
            $actionCustom = trim((string) ($data['action_custom'] ?? ''));
            if ($actionCustom === '') {
                throw ValidationException::withMessages(['action_custom' => ['Custom action text is required.']]);
            }
        }

        $contextColumns = $data['context_columns'] ?? [];
        if (! is_array($contextColumns)) {
            throw ValidationException::withMessages(['context_columns' => ['Context columns must be a list.']]);
        }
        $contextColumns = array_values(array_unique(array_filter($contextColumns, fn ($key) => in_array($key, $allowedKeys, true))));

        $validated = [
            'name' => $name,
            'stock_universe' => $stockUniverse,
            'alert_definition' => $alertDefinition,
            'condition_column' => $conditionColumn,
            'condition_operator' => $operator,
            'compare_type' => $compareType,
            'compare_column' => $compareColumn,
            'compare_formula' => $compareFormula,
            'compare_constant' => $compareConstant,
            'message_template' => $messageTemplate,
            'action_type' => $actionType,
            'action_custom' => $actionCustom,
            'context_columns' => $contextColumns,
            'is_enabled' => array_key_exists('is_enabled', $data)
                ? (bool) $data['is_enabled']
                : ($existing?->is_enabled ?? true),
        ];

        if ($profile !== null) {
            $this->templateValidator->validateForProfile($profile, $validated);
        }

        return $validated;
    }
}
