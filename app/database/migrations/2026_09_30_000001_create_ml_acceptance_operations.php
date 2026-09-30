<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stox_ml_acceptance_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_id');
            $table->string('status', 24);
            $table->json('manifest');
            $table->unsignedBigInteger('received')->default(0);
            $table->json('evidence')->nullable();
            $table->json('history');
            $table->timestamps();
        });
        Schema::table('stox_ml_universe_snapshot_backfill_runs', function (Blueprint $table) {
            $table->json('acceptance')->nullable();
        });
        Schema::create('stox_ml_acceptance_campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_id');
            $table->date('cutoff_date');
            $table->string('status', 24);
            $table->json('identity');
            $table->json('active_models');
            $table->json('horizons');
            $table->json('history');
            $table->timestamp('qualified_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('stox_ml_acceptance_campaigns');
        Schema::table('stox_ml_universe_snapshot_backfill_runs', fn (Blueprint $table) => $table->dropColumn('acceptance'));
        Schema::dropIfExists('stox_ml_acceptance_sources');
    }
};
