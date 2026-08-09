<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_transaction_import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_key')->unique();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('portfolio_users')->nullOnDelete();
            $table->string('status', 32)->default('committed');
            $table->unsignedInteger('row_count')->default(0);
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'batch_key']);
        });

        Schema::create('portfolio_transaction_import_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')
                ->constrained('portfolio_transaction_import_batches')
                ->cascadeOnDelete();
            $table->string('row_key', 64);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('transaction_id')
                ->constrained('portfolio_transactions')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['batch_id', 'row_key']);
            $table->index(['batch_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_transaction_import_batch_items');
        Schema::dropIfExists('portfolio_transaction_import_batches');
    }
};
