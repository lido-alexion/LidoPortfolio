<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kite_session_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
            $table->string('broker_account_id', 128);
            $table->date('session_date');
            $table->string('provider', 24)->default('kite');
            $table->string('session_status', 24);
            $table->string('ledger_status', 24)->default('unknown');
            $table->string('buying_power_status', 24)->default('unknown');
            $table->string('holdings_status', 24)->default('unknown');
            $table->string('orders_status', 24)->default('unknown');
            $table->string('trades_status', 24)->default('unknown');
            $table->json('evidence')->nullable();
            $table->json('source_timestamps')->nullable();
            $table->json('blocking_reasons')->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();
            $table->unique(['profile_id', 'session_date', 'broker_account_id'], 'kite_session_ev_profile_account_uk');
            $table->index(['user_id', 'broker_account_id', 'session_date'], 'kite_session_ev_account_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kite_session_evidence');
    }
};
