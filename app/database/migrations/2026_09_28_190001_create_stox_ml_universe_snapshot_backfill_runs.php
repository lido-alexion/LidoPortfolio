<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stox_ml_universe_snapshot_backfill_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('universe_key', 64);
            $table->string('source', 64);
            $table->json('requested_dates');
            $table->json('processed_dates')->nullable();
            $table->json('failed_dates')->nullable();
            $table->string('status', 32)->default('queued');
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['universe_key', 'status'], 'stox_ml_universe_backfill_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_ml_universe_snapshot_backfill_runs');
    }
};
