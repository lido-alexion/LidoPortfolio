<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * TD-010: business-rule failure that should map to a 4xx API response (not 500).
 *
 * Use for domain preconditions and rule violations. Keep {@see \Illuminate\Validation\ValidationException}
 * for input/form validation (field-level errors).
 */
class DomainException extends RuntimeException
{
    public function __construct(
        string $message,
        protected string $errorCode = 'DOMAIN_ERROR',
        protected int $httpStatus = 422,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
