<?php

namespace App\Services\Artifacts;

use Illuminate\Validation\ValidationException;

final class StrategyParameterSchema
{
    /** @return list<array<string,mixed>> */
    public function declarations(array $envelope): array
    {
        $rows = $envelope['configurable_parameters'] ?? [];

        return is_array($rows) && array_is_list($rows)
            ? array_values(array_filter($rows, 'is_array'))
            : [];
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    public function apply(array $envelope, array $overrides): array
    {
        $envelope['definition'] = $this->applyToDefinition($envelope['definition'] ?? [], $envelope, $overrides);

        return $envelope;
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $overrides @return array<string,mixed> */
    public function applyToDefinition(array $definition, array $envelope, array $overrides): array
    {
        $declared = collect($this->declarations($envelope))->keyBy('key');
        foreach ($overrides as $key => $value) {
            $declaration = $declared->get($key);
            if (! is_array($declaration)) {
                throw ValidationException::withMessages(["parameter_overrides.{$key}" => 'Parameter is not declared configurable by this Strategy version.']);
            }
            $this->assertValue((string) $key, $value, $declaration);
            data_set($definition, (string) $declaration['path'], $value);
        }

        return $definition;
    }

    /** @return list<string> */
    public function declarationErrors(array $envelope): array
    {
        $errors = [];
        if (array_key_exists('configurable_parameters', $envelope)
            && (! is_array($envelope['configurable_parameters']) || ! array_is_list($envelope['configurable_parameters']))) {
            return ['configurable_parameters must be a JSON array.'];
        }
        $seen = [];
        foreach ($this->declarations($envelope) as $index => $row) {
            $key = (string) ($row['key'] ?? '');
            $path = (string) ($row['path'] ?? '');
            $type = (string) ($row['type'] ?? '');
            if ($key === '' || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $key) !== 1 || isset($seen[$key])) {
                $errors[] = "configurable_parameters.{$index}.key must be unique and use letters, numbers, or underscores.";
            }
            $seen[$key] = true;
            if ($path === '' || preg_match('/^[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)*$/', $path) !== 1 || ! data_has($envelope['definition'] ?? [], $path)) {
                $errors[] = "configurable_parameters.{$index}.path must identify an existing field inside definition.";
            }
            if (! in_array($type, ['integer', 'number', 'boolean', 'string'], true)) {
                $errors[] = "configurable_parameters.{$index}.type is invalid.";
            }
            if (isset($row['enum']) && (! is_array($row['enum']) || $row['enum'] === [])) {
                $errors[] = "configurable_parameters.{$index}.enum must be a non-empty array.";
            }
        }

        return $errors;
    }

    private function assertValue(string $key, mixed $value, array $schema): void
    {
        $type = (string) $schema['type'];
        $valid = match ($type) {
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'string' => is_string($value),
            default => false,
        };
        if (! $valid) {
            throw ValidationException::withMessages(["parameter_overrides.{$key}" => "Value must have type {$type}."]);
        }
        if (is_numeric($value) && isset($schema['minimum']) && $value < $schema['minimum']) {
            throw ValidationException::withMessages(["parameter_overrides.{$key}" => "Value must be at least {$schema['minimum']}."]);
        }
        if (is_numeric($value) && isset($schema['maximum']) && $value > $schema['maximum']) {
            throw ValidationException::withMessages(["parameter_overrides.{$key}" => "Value must be at most {$schema['maximum']}."]);
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            throw ValidationException::withMessages(["parameter_overrides.{$key}" => 'Value is not one of the declared choices.']);
        }
    }
}
