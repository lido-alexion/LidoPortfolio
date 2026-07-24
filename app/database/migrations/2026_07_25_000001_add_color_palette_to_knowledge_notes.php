<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_knowledge_notes')) {
            return;
        }

        Schema::table('portfolio_knowledge_notes', function (Blueprint $table) {
            if (! Schema::hasColumn('portfolio_knowledge_notes', 'color_palette')) {
                $table->string('color_palette', 32)->default('default')->after('is_archived');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('portfolio_knowledge_notes')) {
            return;
        }

        Schema::table('portfolio_knowledge_notes', function (Blueprint $table) {
            if (Schema::hasColumn('portfolio_knowledge_notes', 'color_palette')) {
                $table->dropColumn('color_palette');
            }
        });
    }
};
