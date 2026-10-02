<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stox_stock_classification_refresh_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->constrained('portfolio_stocks', indexName: 'stox_class_refresh_stock_fk')->cascadeOnDelete();
            $table->string('provider', 64);
            $table->string('taxonomy_version', 64);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('last_successful_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['stock_id', 'provider', 'taxonomy_version'], 'stox_class_refresh_identity_uq');
            $table->index(['next_attempt_at', 'last_successful_at'], 'stox_class_refresh_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_stock_classification_refresh_states');
    }
};
