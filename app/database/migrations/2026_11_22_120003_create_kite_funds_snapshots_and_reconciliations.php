<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_kite_funds_snapshots')) {
            Schema::create('portfolio_kite_funds_snapshots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
                $table->string('broker_account_id', 64);
                $table->date('trading_date');
                $table->timestamp('captured_at');
                $table->decimal('available_balance', 18, 4)->nullable();
                $table->decimal('opening_balance', 18, 4)->nullable();
                $table->decimal('intraday_payin', 18, 4)->nullable();
                $table->decimal('utilised_payout', 18, 4)->nullable();
                $table->boolean('payout_semantics_verified')->default(false);
                $table->string('capture_source', 32);
                $table->string('freshness', 24);
                $table->string('idempotency_key', 80)->unique('kite_funds_snapshot_idem_uq');
                $table->json('provenance')->nullable();
                $table->timestamps();
                $table->index(['profile_id', 'trading_date', 'captured_at'], 'kite_funds_profile_date_capture_idx');
                $table->index(['broker_account_id', 'trading_date'], 'kite_funds_account_date_idx');
            });
        }

        if (! Schema::hasTable('portfolio_kite_funds_reconciliations')) {
            Schema::create('portfolio_kite_funds_reconciliations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
                $table->string('broker_account_id', 64);
                $table->date('trading_date');
                $table->foreignId('snapshot_id')->nullable()->constrained('portfolio_kite_funds_snapshots')->nullOnDelete();
                $table->string('status', 32);
                $table->decimal('snapshot_net_flow', 18, 4)->nullable();
                $table->decimal('manual_net_flow', 18, 4)->nullable();
                $table->unsignedBigInteger('cash_ledger_entry_id')->nullable();
                $table->string('suppression_reason', 32)->nullable();
                $table->timestamp('evaluated_at')->nullable();
                $table->timestamps();
                $table->unique(['profile_id', 'broker_account_id', 'trading_date'], 'kite_funds_reconcile_profile_acct_date_uq');
                $table->index(['profile_id', 'status', 'trading_date'], 'kite_funds_reconcile_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_kite_funds_reconciliations');
        Schema::dropIfExists('portfolio_kite_funds_snapshots');
    }
};
