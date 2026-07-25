<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SD-026: Cash management + capital allocation / reserved cash.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_cash_accounts')) {
            Schema::create('portfolio_cash_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')->unique()->constrained('portfolio_profiles')->cascadeOnDelete();
                $table->decimal('balance', 18, 4)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('portfolio_cash_ledger_entries')) {
            Schema::create('portfolio_cash_ledger_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
                $table->string('entry_type', 32);
                $table->decimal('amount', 18, 4);
                $table->decimal('balance_after', 18, 4);
                $table->string('reason', 500)->nullable();
                $table->unsignedBigInteger('transaction_id')->nullable();
                $table->unsignedBigInteger('recommendation_id')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('portfolio_users')->nullOnDelete();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['profile_id', 'created_at'], 'cash_ledger_profile_created_idx');
                $table->index('transaction_id', 'cash_ledger_tx_idx');
                $table->index('recommendation_id', 'cash_ledger_rec_idx');
            });
        }

        if (Schema::hasTable('portfolio_tos_recommendations')) {
            Schema::table('portfolio_tos_recommendations', function (Blueprint $table) {
                if (! Schema::hasColumn('portfolio_tos_recommendations', 'suggested_allocation_amount')) {
                    $table->decimal('suggested_allocation_amount', 18, 4)->nullable()->after('suggested_position_size');
                }
                if (! Schema::hasColumn('portfolio_tos_recommendations', 'reserved_amount')) {
                    $table->decimal('reserved_amount', 18, 4)->nullable()->after('suggested_allocation_amount');
                }
                if (! Schema::hasColumn('portfolio_tos_recommendations', 'reservation_status')) {
                    $table->string('reservation_status', 32)->nullable()->after('reserved_amount');
                }
                if (! Schema::hasColumn('portfolio_tos_recommendations', 'reserved_at')) {
                    $table->timestamp('reserved_at')->nullable()->after('reservation_status');
                }
                if (! Schema::hasColumn('portfolio_tos_recommendations', 'cash_balance_at_generation')) {
                    $table->decimal('cash_balance_at_generation', 18, 4)->nullable()->after('reserved_at');
                }
                if (! Schema::hasColumn('portfolio_tos_recommendations', 'reserved_cash_at_generation')) {
                    $table->decimal('reserved_cash_at_generation', 18, 4)->nullable()->after('cash_balance_at_generation');
                }
                if (! Schema::hasColumn('portfolio_tos_recommendations', 'available_cash_at_generation')) {
                    $table->decimal('available_cash_at_generation', 18, 4)->nullable()->after('reserved_cash_at_generation');
                }
                if (! Schema::hasColumn('portfolio_tos_recommendations', 'executed_amount')) {
                    $table->decimal('executed_amount', 18, 4)->nullable()->after('available_cash_at_generation');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('portfolio_tos_recommendations')) {
            Schema::table('portfolio_tos_recommendations', function (Blueprint $table) {
                $cols = array_values(array_filter(
                    [
                        'suggested_allocation_amount',
                        'reserved_amount',
                        'reservation_status',
                        'reserved_at',
                        'cash_balance_at_generation',
                        'reserved_cash_at_generation',
                        'available_cash_at_generation',
                        'executed_amount',
                    ],
                    fn (string $c) => Schema::hasColumn('portfolio_tos_recommendations', $c),
                ));
                if ($cols !== []) {
                    $table->dropColumn($cols);
                }
            });
        }

        Schema::dropIfExists('portfolio_cash_ledger_entries');
        Schema::dropIfExists('portfolio_cash_accounts');
    }
};
