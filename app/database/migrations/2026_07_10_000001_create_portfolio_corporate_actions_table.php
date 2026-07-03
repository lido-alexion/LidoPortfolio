<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_corporate_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->enum('action_type', ['split', 'bonus']);
            $table->unsignedSmallInteger('ratio_from');
            $table->unsignedSmallInteger('ratio_to');
            $table->date('ex_date');
            $table->text('notes')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('portfolio_users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'stock_id', 'ex_date'], 'pca_prof_stock_date_idx');
        });

        Schema::table('portfolio_transactions', function (Blueprint $table) {
            $table->foreignId('corporate_action_id')
                ->nullable()
                ->after('notes')
                ->constrained('portfolio_corporate_actions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('corporate_action_id');
        });

        Schema::dropIfExists('portfolio_corporate_actions');
    }
};
