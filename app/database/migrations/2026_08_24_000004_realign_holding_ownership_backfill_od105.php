<?php

use App\Services\Strategy\HoldingOwnershipBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * V3 §10.5 — replace the 2026-08-19 one-strategy ownership heuristic.
 *
 * 1. Revert strategy-owned holdings that have no recommendation-linked buy for
 *    that strategy (heuristic leftovers). Does not merge into an existing unmanaged row.
 * 2. Re-run conservative lot-level inference.
 *
 * Quantities, cost, cash, and recommendations are not rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        $backfill = app(HoldingOwnershipBackfill::class);
        $backfill->revertUnattestedStrategyOwnershipAll();
        $backfill->inferAll();
    }

    public function down(): void
    {
        // Non-destructive: do not re-apply the one-strategy heuristic.
    }
};
