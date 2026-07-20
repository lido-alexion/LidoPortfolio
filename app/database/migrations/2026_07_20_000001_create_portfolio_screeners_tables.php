<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_screeners', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('profile_id');
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('scope', 32)->default('holdings');
            $table->unsignedBigInteger('watchlist_id')->nullable();
            $table->json('definition_json');
            $table->boolean('schedule_enabled')->default(false);
            $table->string('schedule_time', 5)->nullable();
            $table->json('schedule_days')->nullable();
            $table->boolean('telegram_enabled')->default(true);
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'name'], 'portfolio_screeners_profile_name_uq');
            $table->index(['profile_id', 'is_enabled'], 'portfolio_screeners_profile_enabled_idx');
            $table->index(['schedule_enabled', 'is_enabled'], 'portfolio_screeners_schedule_idx');
            $table->foreign('profile_id')->references('id')->on('portfolio_profiles')->cascadeOnDelete();
            $table->foreign('watchlist_id')->references('id')->on('portfolio_watchlists')->nullOnDelete();
        });

        Schema::create('portfolio_screener_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('screener_id');
            $table->string('triggered_by', 16);
            $table->string('status', 16)->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->json('stats_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['screener_id', 'created_at'], 'portfolio_screener_runs_screener_created_idx');
            $table->foreign('screener_id')->references('id')->on('portfolio_screeners')->cascadeOnDelete();
        });

        Schema::create('portfolio_screener_run_hits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id');
            $table->unsignedBigInteger('stock_id');
            $table->string('symbol', 32);
            $table->string('exchange', 16)->nullable();
            $table->string('name', 255)->nullable();
            $table->json('metrics_json')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'symbol'], 'portfolio_screener_run_hits_run_symbol_idx');
            $table->foreign('run_id')->references('id')->on('portfolio_screener_runs')->cascadeOnDelete();
            $table->foreign('stock_id')->references('id')->on('portfolio_stocks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_screener_run_hits');
        Schema::dropIfExists('portfolio_screener_runs');
        Schema::dropIfExists('portfolio_screeners');
    }
};
