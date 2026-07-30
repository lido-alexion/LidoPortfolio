<?php

namespace App\Services\Artifacts;

use App\Models\PortfolioProfile;
use App\Services\Artifacts\Contracts\ArtifactRegistryInterface;
use App\Services\Indicators\IndicatorRegistry;
use App\Services\Indicators\IndicatorStatus;
use App\Services\Indicators\IndicatorType as IndType;
use App\Services\Screener\ScreenerCatalog;

/**
 * Structural + referential validation for artifact envelopes (no execution).
 */
final class ArtifactValidationService
{
    public function __construct(
        private IndicatorRegistry $indicators,
    ) {}

    public function validateEnvelope(array $envelope, ?PortfolioProfile $profile = null): ValidationResult
    {
        $errors = [];
        $warnings = [];
        $type = (string) ($envelope['artifact_type'] ?? '');

        if (! ArtifactType::isValid($type)) {
            $errors[] = new ValidationIssue('ARTIFACT_TYPE_INVALID', "Unknown artifact_type: {$type}", 'error', '$.artifact_type');

            return ValidationResult::fail($errors);
        }

        $schema = (string) ($envelope['schema_version'] ?? '');
        if ($schema === '' || version_compare($this->major($schema), $this->major(ArtifactType::SCHEMA_VERSION), '>')) {
            $errors[] = new ValidationIssue(
                'SCHEMA_VERSION_UNSUPPORTED',
                "Unsupported schema_version: {$schema}",
                'error',
                '$.schema_version',
            );
        }

        if (trim((string) ($envelope['slug'] ?? '')) === '') {
            $errors[] = new ValidationIssue('SLUG_REQUIRED', 'slug is required', 'error', '$.slug');
        }
        if (trim((string) ($envelope['name'] ?? '')) === '') {
            $errors[] = new ValidationIssue('NAME_REQUIRED', 'name is required', 'error', '$.name');
        }
        if (! is_array($envelope['definition'] ?? null)) {
            $errors[] = new ValidationIssue('DEFINITION_REQUIRED', 'definition object is required', 'error', '$.definition');

            return ValidationResult::fail($errors, $warnings);
        }
        if (! is_array($envelope['metadata'] ?? null)) {
            $errors[] = new ValidationIssue('METADATA_REQUIRED', 'metadata object is required', 'error', '$.metadata');
        }

        $this->rejectCodeFields($envelope['definition'], '$.definition', $errors);

        $resolved = [];
        match ($type) {
            ArtifactType::INDICATOR => $this->validateIndicator($envelope, $errors, $warnings, $resolved),
            ArtifactType::SCREENER => $this->validateScreener($envelope, $errors, $warnings, $resolved),
            ArtifactType::STRATEGY => $this->validateStrategy($envelope, $profile, $errors, $warnings, $resolved),
            default => null,
        };

        return $errors === []
            ? ValidationResult::pass($resolved)
            : ValidationResult::fail($errors, $warnings, $resolved);
    }

    /**
     * @param  list<ValidationIssue>  $errors
     * @param  list<ValidationIssue>  $warnings
     * @param  list<array<string, mixed>>  $resolved
     */
    private function validateIndicator(array $envelope, array &$errors, array &$warnings, array &$resolved): void
    {
        $def = $envelope['definition'];
        $registryId = (string) ($def['registry_id'] ?? $envelope['slug'] ?? '');
        if ($registryId === '') {
            $errors[] = new ValidationIssue('INDICATOR_ID_REQUIRED', 'definition.registry_id is required', 'error', '$.definition.registry_id');

            return;
        }
        $kind = (string) ($def['indicator_kind'] ?? '');
        if (! in_array($kind, [IndType::PRIMARY, IndType::COMPOSITE, IndType::METRIC], true)) {
            $errors[] = new ValidationIssue('INDICATOR_KIND_ENUM', "Invalid indicator_kind: {$kind}", 'error', '$.definition.indicator_kind');
        }
        $deps = $def['depends_on'] ?? [];
        if (! is_array($deps)) {
            $errors[] = new ValidationIssue('DEPS_SHAPE', 'depends_on must be an array', 'error', '$.definition.depends_on');
            $deps = [];
        }
        if ($kind === IndType::COMPOSITE && $deps === [] && ($def['status'] ?? '') !== IndicatorStatus::STUB) {
            $warnings[] = new ValidationIssue(
                'COMPOSITE_DEPS_EMPTY',
                'Composite has empty depends_on',
                'warning',
                '$.definition.depends_on',
            );
        }
        foreach ($deps as $i => $dep) {
            $depId = (string) $dep;
            $resolved[] = ['artifact_type' => ArtifactType::INDICATOR, 'ref' => $depId];
            if (! $this->indicators->has($depId) && $this->indicators->resolveId($depId) === null) {
                $warnings[] = new ValidationIssue(
                    'DEP_UNKNOWN',
                    "depends_on references unknown indicator: {$depId}",
                    'warning',
                    "$.definition.depends_on.{$i}",
                );
            }
        }
    }

    /**
     * @param  list<ValidationIssue>  $errors
     * @param  list<ValidationIssue>  $warnings
     * @param  list<array<string, mixed>>  $resolved
     */
    private function validateScreener(array $envelope, array &$errors, array &$warnings, array &$resolved): void
    {
        $root = $envelope['definition']['root'] ?? null;
        if (! is_array($root)) {
            $errors[] = new ValidationIssue('SCREENER_ROOT_REQUIRED', 'definition.root is required', 'error', '$.definition.root');

            return;
        }
        $this->walkScreenerNode($root, '$.definition.root', 0, $errors, $warnings, $resolved);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<ValidationIssue>  $errors
     * @param  list<ValidationIssue>  $warnings
     * @param  list<array<string, mixed>>  $resolved
     */
    private function walkScreenerNode(array $node, string $path, int $depth, array &$errors, array &$warnings, array &$resolved): void
    {
        if ($depth > 8) {
            $errors[] = new ValidationIssue('SCREENER_DEPTH_LIMIT', 'Condition tree too deep', 'error', $path);

            return;
        }
        $type = (string) ($node['type'] ?? '');
        if ($type === 'group') {
            $op = (string) ($node['op'] ?? '');
            if (! in_array($op, ['AND', 'OR'], true)) {
                $errors[] = new ValidationIssue('SCREENER_OP_ENUM', "Invalid group op: {$op}", 'error', "{$path}.op");
            }
            $children = $node['children'] ?? [];
            if (! is_array($children)) {
                $errors[] = new ValidationIssue('SCREENER_CHILDREN', 'group.children must be an array', 'error', "{$path}.children");

                return;
            }
            foreach ($children as $i => $child) {
                if (is_array($child)) {
                    $this->walkScreenerNode($child, "{$path}.children.{$i}", $depth + 1, $errors, $warnings, $resolved);
                }
            }

            return;
        }
        if ($type === 'condition') {
            $op = (string) ($node['operator'] ?? '');
            if (! in_array($op, ['gt', 'gte', 'lt', 'lte', 'eq'], true)) {
                $errors[] = new ValidationIssue('SCREENER_OPERATOR_ENUM', "Invalid operator: {$op}", 'error', "{$path}.operator");
            }
            $this->checkOperand($node['left'] ?? null, "{$path}.left", $errors, $resolved);
            $this->checkOperand($node['right'] ?? null, "{$path}.right", $errors, $resolved);

            return;
        }
        $errors[] = new ValidationIssue('SCREENER_NODE_TYPE', "Unknown node type: {$type}", 'error', "{$path}.type");
    }

    /**
     * @param  list<ValidationIssue>  $errors
     * @param  list<array<string, mixed>>  $resolved
     */
    private function checkOperand(mixed $operand, string $path, array &$errors, array &$resolved): void
    {
        if (! is_array($operand)) {
            $errors[] = new ValidationIssue('SCREENER_OPERAND_SHAPE', 'Operand must be an object', 'error', $path);

            return;
        }
        if (($operand['type'] ?? null) === 'constant') {
            if (! array_key_exists('value', $operand) || ! is_numeric($operand['value'])) {
                $errors[] = new ValidationIssue('SCREENER_CONSTANT', 'Constant operand needs numeric value', 'error', $path);
            }

            return;
        }
        $id = (string) ($operand['indicator'] ?? '');
        if ($id === '') {
            $errors[] = new ValidationIssue('SCREENER_OPERAND_SHAPE', 'Indicator operand needs indicator id', 'error', $path);

            return;
        }
        $resolved[] = ['artifact_type' => ArtifactType::INDICATOR, 'ref' => $id];
        if (! in_array($id, ScreenerCatalog::indicatorIds(), true)) {
            $errors[] = new ValidationIssue(
                'SCREENER_INDICATOR_REGISTRY',
                "Indicator is not screenable / unknown: {$id}",
                'error',
                $path,
            );
        }
    }

    /**
     * @param  list<ValidationIssue>  $errors
     * @param  list<ValidationIssue>  $warnings
     * @param  list<array<string, mixed>>  $resolved
     */
    private function validateStrategy(array $envelope, ?PortfolioProfile $profile, array &$errors, array &$warnings, array &$resolved): void
    {
        $def = $envelope['definition'];
        foreach (['eligibility_sources', 'root', 'children'] as $forbidden) {
            // eligibility_sources allowed; embedded trees not
        }
        if (isset($def['root']) || isset($def['children'])) {
            $errors[] = new ValidationIssue(
                'STRATEGY_NO_EMBEDDED_SCREENER',
                'Strategy definition must not embed Screener condition trees',
                'error',
                '$.definition',
            );
        }

        $sources = $def['eligibility_sources'] ?? [];
        if (! is_array($sources)) {
            $errors[] = new ValidationIssue('STRATEGY_ELIGIBILITY_REFS', 'eligibility_sources must be an array', 'error', '$.definition.eligibility_sources');
            $sources = [];
        }
        foreach ($sources as $i => $src) {
            if (! is_array($src)) {
                continue;
            }
            $slug = (string) ($src['screener_slug'] ?? '');
            $fk = (string) ($src['screener_factory_key'] ?? $src['factory_key'] ?? '');
            $sid = $src['screener_id'] ?? null;
            if ($slug === '' && $fk === '' && $sid === null) {
                $errors[] = new ValidationIssue(
                    'STRATEGY_ELIGIBILITY_REFS',
                    'Eligibility source needs screener_slug, factory_key, or screener_id',
                    'error',
                    "$.definition.eligibility_sources.{$i}",
                );
            }
            $resolved[] = [
                'artifact_type' => ArtifactType::SCREENER,
                'ref' => $slug !== '' ? $slug : ($fk !== '' ? $fk : (string) $sid),
            ];
        }

        $scoring = $def['scoring_model'] ?? $def['indicators'] ?? null;
        if (! is_array($scoring) || $scoring === []) {
            $errors[] = new ValidationIssue(
                'STRATEGY_SCORING_REQUIRED',
                'scoring_model (or indicators) is required',
                'error',
                '$.definition.scoring_model',
            );

            return;
        }

        $weightSum = 0.0;
        foreach ($scoring as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                $errors[] = new ValidationIssue('STRATEGY_KEYS_REGISTRY', 'scoring row missing key', 'error', "$.definition.scoring_model.{$i}.key");

                continue;
            }
            $resolved[] = ['artifact_type' => ArtifactType::INDICATOR, 'ref' => $key];
            $ind = $this->indicators->find($key) ?? ($this->indicators->resolveId($key) ? $this->indicators->get($this->indicators->resolveId($key)) : null);
            if ($ind === null || ! $ind->hasCapability(\App\Services\Indicators\IndicatorCapability::STRATEGY_SCORABLE)) {
                // Allow catalogue keys even if capability flag missing on stubs — SupportedIndicators façade is BC check
                if (! \App\Engines\Strategy\SupportedIndicators::isSupported($key)) {
                    $errors[] = new ValidationIssue(
                        'STRATEGY_KEYS_REGISTRY',
                        "Scoring key is not strategy-scorable: {$key}",
                        'error',
                        "$.definition.scoring_model.{$i}.key",
                    );
                }
            }
            if ($row['enabled'] ?? false) {
                $weightSum += (float) ($row['weight'] ?? 0);
            }
        }
        if (abs($weightSum - 100.0) > 0.01 && $weightSum > 0) {
            $errors[] = new ValidationIssue(
                'STRATEGY_WEIGHTS_SUM_100',
                "Enabled weights sum to {$weightSum}, expected 100",
                'error',
                '$.definition.scoring_model',
            );
        }
        if ($weightSum <= 0) {
            $errors[] = new ValidationIssue(
                'STRATEGY_WEIGHTS_SUM_100',
                'At least one enabled scoring row with positive weight is required',
                'error',
                '$.definition.scoring_model',
            );
        }
    }

    /**
     * @param  list<ValidationIssue>  $errors
     */
    private function rejectCodeFields(array $definition, string $path, array &$errors): void
    {
        foreach (['code', 'script', 'formula', 'expression', 'wasm', 'php', 'javascript'] as $bad) {
            if (array_key_exists($bad, $definition)) {
                $errors[] = new ValidationIssue(
                    'NO_CODE_FIELDS',
                    "Forbidden field '{$bad}' in definition",
                    'error',
                    "{$path}.{$bad}",
                );
            }
        }
    }

    private function major(string $version): string
    {
        $parts = explode('.', $version);

        return $parts[0] !== '' ? $parts[0] : '0';
    }
}
