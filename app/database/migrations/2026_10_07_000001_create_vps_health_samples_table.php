<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_vps_health_samples', function (Blueprint $table) {
            $table->id();
            $table->timestamp('sampled_at')->index();
            $table->string('status', 16);
            $table->json('issues');
            $table->json('metrics');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_vps_health_samples');
    }
};
