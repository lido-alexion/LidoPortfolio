<?php

namespace App\Services\Artifacts;

/**
 * Immutable validation issue.
 */
final readonly class ValidationIssue
{
    public function __construct(
        public string $code,
        public string $message,
        public string $severity = 'error',
        public ?string $path = null,
    ) {}

    /**
     * @return array{code: string, message: string, severity: string, path: ?string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'severity' => $this->severity,
            'path' => $this->path,
        ];
    }
}
