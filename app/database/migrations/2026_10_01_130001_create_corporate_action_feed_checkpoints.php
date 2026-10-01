<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stox_corporate_action_feed_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->string('feed_key', 128)->unique('stox_ca_feed_checkpoint_key_uq');
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('last_successful_at')->nullable();
            $table->date('window_from')->nullable();
            $table->date('window_to')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->unsignedInteger('rows_seen')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_corporate_action_feed_checkpoints');
    }
};
