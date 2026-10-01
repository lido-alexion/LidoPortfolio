<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'portfolio_screener_backtest_days';
        $versionKey = ['screener_id', 'screener_version_id', 'as_of_date'];

        // Create the replacement FIRST: MySQL needs an index beginning with
        // screener_id to support its foreign key before the old one can go.
        if (! Schema::hasIndex($tableName, $versionKey, 'unique')) {
            Schema::table($tableName, function (Blueprint $table) use ($versionKey) {
                $table->unique($versionKey, 'portfolio_screener_backtest_days_ver_date_uq');
            });
        }

        foreach (Schema::getIndexes($tableName) as $index) {
            if ($index['unique'] && $index['columns'] === ['screener_id', 'as_of_date']) {
                Schema::table($tableName, function (Blueprint $table) use ($index) {
                    $table->dropUnique($index['name']);
                });
            }
        }
    }

    public function down(): void
    {
        // Intentionally preserve the repaired constraint. Restoring date-only
        // uniqueness would reject valid historical rows from different versions.
    }
};
