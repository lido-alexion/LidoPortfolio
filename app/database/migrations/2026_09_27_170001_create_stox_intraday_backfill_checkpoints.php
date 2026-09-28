<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stox_intraday_backfill_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 32);
            $table->string('exchange', 16)->default('NSE');
            $table->date('window_start')->nullable();
            $table->date('window_end')->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('bars_written')->default(0);
            $table->json('last_error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'exchange', 'window_start', 'window_end'], 'stox_intraday_checkpoint_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_intraday_backfill_checkpoints');
    }
};
