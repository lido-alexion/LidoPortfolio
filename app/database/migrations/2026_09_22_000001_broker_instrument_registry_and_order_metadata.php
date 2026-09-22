<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_broker_instruments')) {
            Schema::create('portfolio_broker_instruments', function (Blueprint $table): void {
                $table->id();
                $table->string('provider', 32);
                $table->foreignId('stock_id')->constrained('portfolio_stocks')->cascadeOnDelete();
                $table->string('exchange', 16);
                $table->string('trading_symbol', 64);
                $table->string('instrument_token', 64)->nullable();
                $table->string('exchange_token', 64)->nullable();
                $table->string('series', 16)->nullable();
                $table->string('broker_name', 191)->nullable();
                $table->json('raw_metadata')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'stock_id', 'exchange'], 'broker_inst_current_uq');
                $table->index(['provider', 'exchange', 'trading_symbol'], 'broker_inst_lookup_idx');
            });
        }

        if (Schema::hasTable('portfolio_tos_orders')) {
            Schema::table('portfolio_tos_orders', function (Blueprint $table): void {
                if (! Schema::hasColumn('portfolio_tos_orders', 'broker_variety')) {
                    $table->string('broker_variety', 16)->nullable()->after('broker_provider');
                }
                if (! Schema::hasColumn('portfolio_tos_orders', 'broker_error_message')) {
                    $table->string('broker_error_message', 500)->nullable()->after('broker_status');
                }
                if (! Schema::hasColumn('portfolio_tos_orders', 'broker_error_type')) {
                    $table->string('broker_error_type', 96)->nullable()->after('broker_error_message');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('portfolio_tos_orders')) {
            Schema::table('portfolio_tos_orders', function (Blueprint $table): void {
                foreach (['broker_variety', 'broker_error_message', 'broker_error_type'] as $column) {
                    if (Schema::hasColumn('portfolio_tos_orders', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('portfolio_broker_instruments');
    }
};
