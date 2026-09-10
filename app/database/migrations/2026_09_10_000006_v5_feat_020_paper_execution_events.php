<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_paper_execution_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->foreignId('recommendation_id')->constrained('portfolio_tos_recommendations')->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('portfolio_transactions')->nullOnDelete();
            $table->date('effective_session_date');
            $table->timestamp('processed_at');
            $table->string('status', 32);
            $table->string('side', 8);
            $table->decimal('requested_quantity', 18, 4);
            $table->decimal('executed_quantity', 18, 4)->default(0);
            $table->decimal('execution_price', 18, 4)->nullable();
            $table->json('evidence');
            $table->timestamps();
            $table->unique(['recommendation_id', 'effective_session_date'], 'paper_exec_rec_session_uq');
            $table->index(['profile_id', 'effective_session_date'], 'paper_exec_profile_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_paper_execution_events');
    }
};
