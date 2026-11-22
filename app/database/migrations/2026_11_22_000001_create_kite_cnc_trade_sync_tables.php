<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kite_cnc_trade_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
            $table->date('trading_date');
            $table->string('trigger', 24);
            $table->string('status', 24);
            $table->unsignedInteger('received_count')->default(0);
            $table->unsignedInteger('eligible_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->text('failure')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['profile_id', 'trading_date'], 'kite_cnc_sync_profile_date_idx');
        });

        Schema::create('kite_cnc_trade_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->foreignId('sync_run_id')->constrained('kite_cnc_trade_sync_runs')->cascadeOnDelete();
            $table->foreignId('stock_id')->nullable()->constrained('portfolio_stocks')->nullOnDelete();
            $table->string('broker_trade_id', 64);
            $table->string('broker_order_id', 64)->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained('portfolio_transactions')->nullOnDelete();
            $table->foreignId('matched_transaction_id')->nullable()->constrained('portfolio_transactions')->nullOnDelete();
            $table->string('symbol', 64);
            $table->string('exchange', 8);
            $table->string('side', 8);
            $table->decimal('quantity', 18, 4);
            $table->decimal('price', 18, 4);
            $table->timestamp('executed_at')->nullable();
            $table->string('product', 16);
            $table->string('status', 32);
            $table->json('broker_evidence')->nullable();
            $table->timestamps();
            $table->unique(['profile_id', 'broker_trade_id'], 'kite_cnc_trade_profile_trade_uq');
            $table->index(['profile_id', 'status'], 'kite_cnc_trade_profile_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kite_cnc_trade_imports');
        Schema::dropIfExists('kite_cnc_trade_sync_runs');
    }
};
