<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL identifiers max out at 64 characters. Laravel's default name
        // `portfolio_transaction_import_batch_items_batch_id_sort_order_index` is 68
        // and fails with SQLSTATE 1059, leaving empty leftover tables. Drop those
        // leftovers so a re-run of this still-pending migration can succeed.
        Schema::dropIfExists('portfolio_transaction_import_batch_items');
        Schema::dropIfExists('portfolio_transaction_import_batches');

        Schema::create('portfolio_transaction_import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_key')->unique();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('portfolio_users')->nullOnDelete();
            $table->string('status', 32)->default('committed');
            $table->unsignedInteger('row_count')->default(0);
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'batch_key'], 'ptib_profile_batch_idx');
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

            $table->unique(['batch_id', 'row_key'], 'ptibi_batch_row_uq');
            $table->index(['batch_id', 'sort_order'], 'ptibi_batch_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_transaction_import_batch_items');
        Schema::dropIfExists('portfolio_transaction_import_batches');
    }
};
