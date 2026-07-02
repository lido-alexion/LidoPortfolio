<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_operational_alerts', function (Blueprint $table) {
            $table->string('alert_key', 64)->primary();
            $table->string('severity', 16);
            $table->string('title');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('first_triggered_at');
            $table->timestamp('last_triggered_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('last_telegram_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_operational_alerts');
    }
};
