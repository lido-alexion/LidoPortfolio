<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_export_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
            $table->uuid('token')->unique();
            $table->string('dataset', 120);
            $table->string('format', 12);
            $table->string('path');
            $table->string('status', 24)->default('queued');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('portfolio_export_baskets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('portfolio_users')->cascadeOnDelete();
            // MySQL does not allow defaults on JSON columns. ExportBasket
            // initializes this value at the model/application boundary.
            $table->json('items');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_export_baskets');
        Schema::dropIfExists('portfolio_export_artifacts');
    }
};
