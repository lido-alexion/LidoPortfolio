<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V4-FEAT-023 — immutable dataset version identity.
 * Insert-only: successful daily market syncs append a row; rows are never updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portfolio_tos_dataset_versions')) {
            return;
        }

        Schema::create('portfolio_tos_dataset_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version_key', 80)->unique();
            $table->timestamp('synced_at');
            $table->date('latest_price_date')->nullable();
            $table->unsignedInteger('price_bars')->default(0);
            $table->unsignedInteger('securities_active')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['synced_at'], 'tos_ds_ver_synced_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_tos_dataset_versions');
    }
};
