<?php

namespace App\Services\Fundamentals\AI;

use App\Models\Stock;

interface FundamentalInsightsAiProvider
{
    public function id(): string;

    public function isConfigured(): bool;

    /**
     * @param  array<string, mixed>  $deterministic
     * @return array{ok:bool,insights?:array<string,mixed>,error_code?:string,error_message?:string}
     */
    public function generate(Stock $stock, array $deterministic): array;
}
