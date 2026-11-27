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

    /** @param list<array<string,mixed>> $events @return array{ok:bool,candidates?:list<array<string,mixed>>,telemetry?:array<string,mixed>,error_code?:string} */
    public function discoverCorporateActionSources(array $events): array;
}
