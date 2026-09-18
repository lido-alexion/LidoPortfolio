<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_recommendation_reservation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id');
            $table->foreign('profile_id', 'prre_profile_fk')
                ->references('id')->on('portfolio_profiles')->cascadeOnDelete();
            $table->foreignId('recommendation_id');
            $table->foreign('recommendation_id', 'prre_recommendation_fk')
                ->references('id')->on('portfolio_tos_recommendations')->cascadeOnDelete();
            $table->string('state', 24);
            $table->decimal('amount', 18, 4);
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['recommendation_id', 'occurred_at'], 'reservation_events_rec_time_idx');
        });

        Schema::create('portfolio_tos_recall_bridge_loan_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bridge_loan_id');
            $table->foreign('bridge_loan_id', 'ptblr_bridge_loan_fk')
                ->references('id')->on('portfolio_tos_recall_bridge_loans')->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->timestamp('returned_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['bridge_loan_id', 'returned_at'], 'bridge_returns_loan_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_tos_recall_bridge_loan_returns');
        Schema::dropIfExists('portfolio_recommendation_reservation_events');
    }
};
