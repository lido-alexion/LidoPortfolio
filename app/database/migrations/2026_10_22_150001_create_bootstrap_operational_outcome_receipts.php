<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stox_bootstrap_outcome_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 191)->unique('stox_bootstrap_outcome_event_uq');
            $table->string('event_type', 64);
            $table->foreignId('run_id')->nullable()->constrained('stox_fundamental_bootstrap_runs')->nullOnDelete();
            $table->unsignedBigInteger('occurrence_id')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['event_type', 'created_at'], 'stox_bootstrap_outcome_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_bootstrap_outcome_receipts');
    }
};
