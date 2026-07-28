<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_market_depth_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('as_of_date');
            $table->string('exchange_scope', 8); // nse | bse
            $table->longText('payload_json');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['as_of_date', 'exchange_scope'], 'pmd_snapshots_date_scope_unique');
            $table->index('as_of_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_market_depth_snapshots');
    }
};
