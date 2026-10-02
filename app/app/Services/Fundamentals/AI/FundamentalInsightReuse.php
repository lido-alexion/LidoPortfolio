<?php

namespace App\Services\Fundamentals\AI;

use App\Services\AI\EmbeddedAiContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Reuses FEAT-062 output only; never invokes a provider. */
class FundamentalInsightReuse
{
    public function remember(int $stockId, array $deterministic, array $result): void
    {
        if (($result['ai']['status'] ?? '') !== 'ok' || ! is_array($result['ai_interpretation'] ?? null)) {
            return;
        }
        if (! Schema::hasTable('stox_fundamental_ai_reuse')) {
            return;
        }
        DB::table('stox_fundamental_ai_reuse')->updateOrInsert(['stock_id' => $stockId], ['fingerprint' => EmbeddedAiContract::hash($deterministic), 'interpretation' => json_encode($result['ai_interpretation'], JSON_THROW_ON_ERROR), 'generated_at' => now()]);
    }

    public function current(int $stockId, array $deterministic): ?array
    {
        $row = DB::table('stox_fundamental_ai_reuse')->where('stock_id', $stockId)->where('fingerprint', EmbeddedAiContract::hash($deterministic))->first();

        return $row ? json_decode($row->interpretation, true, 512, JSON_THROW_ON_ERROR) : null;
    }
}
