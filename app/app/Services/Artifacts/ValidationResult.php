<?php

namespace App\Services\Artifacts;

final class ValidationResult
{
    /** @param list<ValidationIssue> $errors */
    /** @param list<ValidationIssue> $warnings */
    public function __construct(
        public readonly bool $ok,
        public readonly array $errors = [],
        public readonly array $warnings = [],
        public readonly array $resolvedDependencies = [],
    ) {}

    public static function pass(array $resolvedDependencies = []): self
    {
        return new self(true, [], [], $resolvedDependencies);
    }

    /**
     * @param  list<ValidationIssue>  $errors
     * @param  list<ValidationIssue>  $warnings
     */
    public static function fail(array $errors, array $warnings = [], array $resolvedDependencies = []): self
    {
        return new self(false, $errors, $warnings, $resolvedDependencies);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'errors' => array_map(fn (ValidationIssue $i) => $i->toArray(), $this->errors),
            'warnings' => array_map(fn (ValidationIssue $i) => $i->toArray(), $this->warnings),
            'resolved_dependencies' => $this->resolvedDependencies,
        ];
    }
}
