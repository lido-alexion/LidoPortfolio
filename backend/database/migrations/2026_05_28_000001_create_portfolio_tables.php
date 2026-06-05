<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_stocks', function (Blueprint $table) {
            $table->id();
            $table->string('symbol')->unique();
            $table->string('exchange')->default('NSE');
            $table->string('name');
            $table->string('isin')->nullable();
            $table->string('sector')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_benchmark')->default(false);
            $table->timestamps();
        });

        Schema::create('portfolio_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->enum('type', ['buy', 'sell']);
            $table->decimal('quantity', 18, 4);
            $table->decimal('price', 18, 4);
            $table->decimal('brokerage', 18, 4)->default(0);
            $table->date('transaction_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'stock_id', 'transaction_date']);
        });

        Schema::create('portfolio_holdings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('avg_buy_price', 18, 4)->default(0);
            $table->decimal('invested_amount', 18, 4)->default(0);
            $table->decimal('realized_profit', 18, 4)->default(0);
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['user_id', 'stock_id']);
        });

        Schema::create('portfolio_stock_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->date('price_date');
            $table->decimal('open_price', 18, 4)->nullable();
            $table->decimal('high_price', 18, 4)->nullable();
            $table->decimal('low_price', 18, 4)->nullable();
            $table->decimal('close_price', 18, 4);
            $table->unsignedBigInteger('volume')->nullable();
            $table->string('data_source');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['stock_id', 'price_date']);
            $table->index(['stock_id', 'price_date']);
        });

        Schema::create('portfolio_stock_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->unique()->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->decimal('highest_close', 18, 4)->nullable();
            $table->decimal('latest_close', 18, 4)->nullable();
            $table->decimal('stoploss_percent', 8, 4)->default(10);
            $table->decimal('trailing_stop_price', 18, 4)->nullable();
            $table->decimal('relative_strength_1m', 18, 6)->nullable();
            $table->decimal('relative_strength_3m', 18, 6)->nullable();
            $table->decimal('relative_strength_6m', 18, 6)->nullable();
            $table->boolean('tracking_active')->default(true);
            $table->timestamp('updated_at')->useCurrent();
        });

        Schema::create('portfolio_portfolio_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->decimal('portfolio_value', 18, 4);
            $table->decimal('invested_value', 18, 4);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'snapshot_date']);
        });

        Schema::create('portfolio_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->string('alert_type');
            $table->text('message');
            $table->boolean('is_sent')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('portfolio_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key')->unique();
            $table->text('setting_value')->nullable();
            $table->timestamp('updated_at')->useCurrent();
        });

        Schema::create('portfolio_system_logs', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->string('level')->default('error');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_system_logs');
        Schema::dropIfExists('portfolio_settings');
        Schema::dropIfExists('portfolio_alerts');
        Schema::dropIfExists('portfolio_portfolio_snapshots');
        Schema::dropIfExists('portfolio_stock_metrics');
        Schema::dropIfExists('portfolio_stock_prices');
        Schema::dropIfExists('portfolio_holdings');
        Schema::dropIfExists('portfolio_transactions');
        Schema::dropIfExists('portfolio_stocks');
    }
};
