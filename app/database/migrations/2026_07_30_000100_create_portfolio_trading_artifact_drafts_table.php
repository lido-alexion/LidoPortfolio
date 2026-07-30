<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draft / imported Indicator artifact envelopes (SD-034).
 * Does not replace IndicatorRegistry runtime seed; drafts never execute until a future release wires them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_trading_artifact_drafts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('profile_id')->nullable()->index();
            $table->string('artifact_type', 32)->index();
            $table->uuid('artifact_uuid')->unique();
            $table->string('slug', 120);
            $table->string('name', 160);
            $table->unsignedInteger('artifact_version')->default(1);
            $table->string('status', 32)->default('draft');
            $table->string('origin', 32)->default('user');
            $table->string('definition_hash', 80)->nullable();
            $table->json('envelope_json');
            $table->timestamps();

            $table->unique(['profile_id', 'artifact_type', 'slug'], 'pta_drafts_profile_type_slug_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_trading_artifact_drafts');
    }
};
