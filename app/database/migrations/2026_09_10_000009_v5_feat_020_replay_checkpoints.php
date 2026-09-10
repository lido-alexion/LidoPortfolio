<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_replay_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('replay_run_id')->constrained('portfolio_replay_runs')->cascadeOnDelete();
            $table->date('effective_session_date');
            $table->timestamp('processed_at');
            $table->string('stage', 32);
            $table->json('state_before');
            $table->json('state_after');
            $table->json('market_evidence');
            $table->json('limitations')->nullable();
            $table->timestamps();
            $table->unique(['replay_run_id', 'effective_session_date'], 'replay_checkpoint_run_session_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_replay_checkpoints');
    }
};
