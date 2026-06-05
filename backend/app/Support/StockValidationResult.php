<?php

namespace App\Support;

use App\Models\Stock;

class StockValidationResult
{
    /**
     * @param  array<int, string>  $errors
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public bool $valid,
        public ?Stock $stock = null,
        public string $source = 'none',
        public array $errors = [],
        public array $meta = [],
    ) {}

    public static function valid(Stock $stock, string $source, array $meta = []): self
    {
        return new self(true, $stock, $source, [], $meta);
    }

    /**
     * @param  array<int, string>  $errors
     */
    public static function invalid(array $errors, array $meta = []): self
    {
        return new self(false, null, 'none', $errors, $meta);
    }
}
