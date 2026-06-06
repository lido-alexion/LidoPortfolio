<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_alerts', function (Blueprint $table) {
            $table->timestamp('expired_at')->nullable()->after('is_sent');
            $table->string('expiration_reason', 64)->nullable()->after('expired_at');

            $table->index(['stock_id', 'expired_at']);
            $table->index('expired_at');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_alerts', function (Blueprint $table) {
            $table->dropIndex(['stock_id', 'expired_at']);
            $table->dropIndex(['expired_at']);
            $table->dropColumn(['expired_at', 'expiration_reason']);
        });
    }
};
