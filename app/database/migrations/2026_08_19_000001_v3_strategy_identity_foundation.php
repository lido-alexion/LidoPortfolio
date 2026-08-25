<?php

use App\Models\Holding;
use App\Services\Strategy\HoldingOwnershipBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V3 Workstream 1 — domain identity foundation.
 *
 * Proposed (and applied) migration:
 * - Allow multiple enabled strategies per portfolio (application rule only; no unique status constraint existed).
 * - Persist strategy `allocation_pct` (storage only; no sum-100 / capital formula in this workstream).
 * - Holdings unique identity becomes (profile_id, stock_id, owner_key) per OD-01.
 * - Historical note: this migration originally inferred ownership when a profile had exactly one
 *   strategy. That heuristic was superseded by §10.5 lot-level inference in
 *   `2026_08_24_000004_realign_holding_ownership_backfill_od105`. Quantities, cost, cash, and
 *   recommendations are not rewritten here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portfolio_tos_strategies') && ! Schema::hasColumn('portfolio_tos_strategies', 'allocation_pct')) {
            Schema::table('portfolio_tos_strategies', function (Blueprint $table) {
                $table->decimal('allocation_pct', 8, 4)->default(100)->after('status');
            });
        }

        if (Schema::hasTable('portfolio_holdings')) {
            Schema::table('portfolio_holdings', function (Blueprint $table) {
                if (! Schema::hasColumn('portfolio_holdings', 'strategy_id')) {
                    $table->unsignedBigInteger('strategy_id')->nullable()->after('stock_id');
                    $table->index('strategy_id', 'pph_strategy_idx');
                }
                if (! Schema::hasColumn('portfolio_holdings', 'owner_key')) {
                    $table->string('owner_key', 64)->default(Holding::OWNER_UNMANAGED)->after('strategy_id');
                }
            });

            if (Schema::hasTable('portfolio_tos_strategies')) {
                try {
                    Schema::table('portfolio_holdings', function (Blueprint $table) {
                        $table->foreign('strategy_id', 'pph_strategy_fk')
                            ->references('id')
                            ->on('portfolio_tos_strategies')
                            ->nullOnDelete();
                    });
                } catch (\Throwable) {
                }
            }

            app(HoldingOwnershipBackfill::class)->inferAll();

            try {
                Schema::table('portfolio_holdings', function (Blueprint $table) {
                    $table->dropUnique('pph_prof_stock_uq');
                });
            } catch (\Throwable) {
            }
            try {
                Schema::table('portfolio_holdings', function (Blueprint $table) {
                    $table->dropUnique(['profile_id', 'stock_id']);
                });
            } catch (\Throwable) {
            }

            try {
                Schema::table('portfolio_holdings', function (Blueprint $table) {
                    $table->unique(['profile_id', 'stock_id', 'owner_key'], 'pph_prof_stock_owner_uq');
                });
            } catch (\Throwable) {
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('portfolio_holdings')) {
            try {
                Schema::table('portfolio_holdings', function (Blueprint $table) {
                    $table->dropUnique('pph_prof_stock_owner_uq');
                });
            } catch (\Throwable) {
            }
            try {
                Schema::table('portfolio_holdings', function (Blueprint $table) {
                    $table->dropUnique(['profile_id', 'stock_id', 'owner_key']);
                });
            } catch (\Throwable) {
            }

            try {
                Schema::table('portfolio_holdings', function (Blueprint $table) {
                    $table->dropForeign('pph_strategy_fk');
                });
            } catch (\Throwable) {
            }

            try {
                Schema::table('portfolio_holdings', function (Blueprint $table) {
                    $table->dropIndex('pph_strategy_idx');
                });
            } catch (\Throwable) {
            }

            Schema::table('portfolio_holdings', function (Blueprint $table) {
                if (Schema::hasColumn('portfolio_holdings', 'strategy_id')) {
                    $table->dropColumn('strategy_id');
                }
                if (Schema::hasColumn('portfolio_holdings', 'owner_key')) {
                    $table->dropColumn('owner_key');
                }
            });

            try {
                Schema::table('portfolio_holdings', function (Blueprint $table) {
                    $table->unique(['profile_id', 'stock_id'], 'pph_prof_stock_uq');
                });
            } catch (\Throwable) {
            }
        }

        if (Schema::hasTable('portfolio_tos_strategies') && Schema::hasColumn('portfolio_tos_strategies', 'allocation_pct')) {
            Schema::table('portfolio_tos_strategies', function (Blueprint $table) {
                $table->dropColumn('allocation_pct');
            });
        }
    }
};
