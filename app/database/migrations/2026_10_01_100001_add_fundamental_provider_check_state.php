<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stox_fundamental_facts', function (Blueprint $table): void {
            $table->timestamp('last_provider_checked_at')->nullable()->after('first_fetched_at');
            $table->index(['stock_id', 'cadence', 'last_provider_checked_at'], 'stox_fund_last_provider_check_idx');
        });

        Schema::create('stox_fundamental_provider_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->constrained('portfolio_stocks')->cascadeOnDelete();
            $table->string('cadence', 16);
            $table->timestamp('last_successful_check_at');
            $table->string('provider', 32);
            $table->string('response_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['stock_id', 'cadence'], 'stox_fund_provider_checks_stock_cadence_uq');
            $table->index(['cadence', 'last_successful_check_at'], 'stox_fund_provider_checks_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_fundamental_provider_checks');
        Schema::table('stox_fundamental_facts', function (Blueprint $table): void {
            $table->dropIndex('stox_fund_last_provider_check_idx');
            $table->dropColumn('last_provider_checked_at');
        });
    }
};
