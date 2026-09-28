<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stox_ml_training_horizon_locks', function (Blueprint $table): void {
            $table->string('horizon', 8)->primary();
            $table->timestamps();
        });

        $now = now();
        DB::table('stox_ml_training_horizon_locks')->insert(array_map(
            static fn (string $horizon): array => [
                'horizon' => $horizon,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['1m', '3m', '6m'],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_ml_training_horizon_locks');
    }
};
