<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SD-025: Recommendation approval is separate from trade execution.
 * - accepted → pending_execution (Approved for Execution queue)
 * - transactions gain source + recommendation_id
 * - recommendations gain approval / cancel / execute metadata
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portfolio_tos_recommendations')) {
            Schema::table('portfolio_tos_recommendations', function (Blueprint $table) {
                if (! Schema::hasColumn('portfolio_tos_recommendations', 'approved_at')) {
                    $table->timestamp('approved_at')->nullable()->after('generated_at');
                }
                if (! Schema::hasColumn('portfolio_tos_recommendations', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable()->after('approved_at');
                }
                if (! Schema::hasColumn('portfolio_tos_recommendations', 'cancellation_reason')) {
                    $table->string('cancellation_reason', 64)->nullable()->after('cancelled_at');
                }
                if (! Schema::hasColumn('portfolio_tos_recommendations', 'executed_at')) {
                    $table->timestamp('executed_at')->nullable()->after('cancellation_reason');
                }
                if (! Schema::hasColumn('portfolio_tos_recommendations', 'executed_transaction_id')) {
                    $table->unsignedBigInteger('executed_transaction_id')->nullable()->after('executed_at');
                    $table->index('executed_transaction_id', 'tos_rec_exec_tx_idx');
                }
            });

            $now = now()->toDateTimeString();
            DB::table('portfolio_tos_recommendations')
                ->where('status', 'accepted')
                ->orderBy('id')
                ->chunkById(100, function ($rows) use ($now) {
                    foreach ($rows as $row) {
                        DB::table('portfolio_tos_recommendations')
                            ->where('id', $row->id)
                            ->update([
                                'status' => 'pending_execution',
                                'approved_at' => $row->approved_at
                                    ?? $row->updated_at
                                    ?? $row->generated_at
                                    ?? $now,
                            ]);
                    }
                });
        }

        if (Schema::hasTable('portfolio_transactions')) {
            Schema::table('portfolio_transactions', function (Blueprint $table) {
                if (! Schema::hasColumn('portfolio_transactions', 'source')) {
                    $table->string('source', 32)->nullable()->after('notes');
                }
                if (! Schema::hasColumn('portfolio_transactions', 'recommendation_id')) {
                    $table->unsignedBigInteger('recommendation_id')->nullable()->after('source');
                    $table->index('recommendation_id', 'tx_recommendation_id_idx');
                }
            });

            if (Schema::hasTable('portfolio_tos_order_transactions')
                && Schema::hasTable('portfolio_tos_orders')) {
                $links = DB::table('portfolio_tos_order_transactions as ot')
                    ->join('portfolio_tos_orders as o', 'o.id', '=', 'ot.order_id')
                    ->whereNotNull('o.recommendation_id')
                    ->select('ot.transaction_id', 'o.recommendation_id')
                    ->get();

                foreach ($links as $link) {
                    DB::table('portfolio_transactions')
                        ->where('id', $link->transaction_id)
                        ->whereNull('recommendation_id')
                        ->update([
                            'recommendation_id' => $link->recommendation_id,
                            'source' => 'recommendation',
                        ]);
                }
            }

            DB::table('portfolio_transactions')
                ->whereNull('source')
                ->whereNotNull('corporate_action_id')
                ->update(['source' => 'other']);

            DB::table('portfolio_transactions')
                ->whereNull('source')
                ->update(['source' => 'manual']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('portfolio_tos_recommendations')) {
            DB::table('portfolio_tos_recommendations')
                ->where('status', 'pending_execution')
                ->update(['status' => 'accepted']);

            Schema::table('portfolio_tos_recommendations', function (Blueprint $table) {
                $cols = array_values(array_filter(
                    ['approved_at', 'cancelled_at', 'cancellation_reason', 'executed_at', 'executed_transaction_id'],
                    fn (string $c) => Schema::hasColumn('portfolio_tos_recommendations', $c),
                ));
                if ($cols !== []) {
                    $table->dropColumn($cols);
                }
            });
        }

        if (Schema::hasTable('portfolio_transactions')) {
            Schema::table('portfolio_transactions', function (Blueprint $table) {
                $cols = array_values(array_filter(
                    ['source', 'recommendation_id'],
                    fn (string $c) => Schema::hasColumn('portfolio_transactions', $c),
                ));
                if ($cols !== []) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
