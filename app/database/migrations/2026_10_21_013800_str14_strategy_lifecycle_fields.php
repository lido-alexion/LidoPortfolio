<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portfolio_tos_strategies')) {
            Schema::table('portfolio_tos_strategies', function (Blueprint $table): void {
                if (! Schema::hasColumn('portfolio_tos_strategies', 'archived_at')) {
                    $table->timestamp('archived_at')->nullable();
                }
                if (! Schema::hasColumn('portfolio_tos_strategies', 'archive_reason')) {
                    $table->text('archive_reason')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('portfolio_tos_strategies')) {
            Schema::table('portfolio_tos_strategies', function (Blueprint $table): void {
                if (Schema::hasColumn('portfolio_tos_strategies', 'archive_reason')) {
                    $table->dropColumn('archive_reason');
                }
                if (Schema::hasColumn('portfolio_tos_strategies', 'archived_at')) {
                    $table->dropColumn('archived_at');
                }
            });
        }
    }
};
