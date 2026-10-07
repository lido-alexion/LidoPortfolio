<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_account_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
            $table->string('setting_key', 64);
            $table->text('setting_value')->nullable();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['user_id', 'setting_key'], 'pas_user_key_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_account_settings');
    }
};
