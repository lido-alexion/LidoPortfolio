<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stox_ml_lifecycle_schedules', function (Blueprint $table): void {
            $table->string('horizon', 8)->primary();
            $table->boolean('enabled')->default(false);
            $table->string('schedule', 64);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index('updated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_ml_lifecycle_schedules');
    }
};
