<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stox_intraday_backfill_controls')) {
            return;
        }

        Schema::create('stox_intraday_backfill_controls', function (Blueprint $table) {
            $table->id();
            $table->string('control_key', 64)->unique();
            $table->boolean('paused')->default(false);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_intraday_backfill_controls');
    }
};
