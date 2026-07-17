<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_watchlists')) {
            Schema::create('portfolio_watchlists', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
                $table->string('name', 80);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['profile_id', 'name'], 'pw_profile_name_unique');
                $table->index(['profile_id', 'sort_order'], 'pw_profile_sort_idx');
            });
        }

        if (! Schema::hasColumn('portfolio_watchlist_items', 'watchlist_id')) {
            Schema::table('portfolio_watchlist_items', function (Blueprint $table) {
                $table->foreignId('watchlist_id')
                    ->nullable()
                    ->after('profile_id')
                    ->constrained('portfolio_watchlists')
                    ->cascadeOnDelete();
            });
        }

        $this->migrateExistingItems();

        Schema::table('portfolio_watchlist_items', function (Blueprint $table) {
            if ($this->indexExists('portfolio_watchlist_items', 'pwi_profile_stock_unique')) {
                $table->dropUnique('pwi_profile_stock_unique');
            }
        });

        if (Schema::hasColumn('portfolio_watchlist_items', 'watchlist_id')) {
            DB::table('portfolio_watchlist_items')
                ->whereNull('watchlist_id')
                ->delete();

            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                Schema::table('portfolio_watchlist_items', function (Blueprint $table) {
                    $table->unsignedBigInteger('watchlist_id')->nullable(false)->change();
                });
            }

            if (! $this->indexExists('portfolio_watchlist_items', 'pwi_watchlist_stock_unique')) {
                Schema::table('portfolio_watchlist_items', function (Blueprint $table) {
                    $table->unique(['watchlist_id', 'stock_id'], 'pwi_watchlist_stock_unique');
                    $table->index(['watchlist_id', 'updated_at'], 'pwi_watchlist_updated_idx');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('portfolio_watchlist_items', 'watchlist_id')) {
            Schema::table('portfolio_watchlist_items', function (Blueprint $table) {
                if ($this->indexExists('portfolio_watchlist_items', 'pwi_watchlist_stock_unique')) {
                    $table->dropUnique('pwi_watchlist_stock_unique');
                }
                if ($this->indexExists('portfolio_watchlist_items', 'pwi_watchlist_updated_idx')) {
                    $table->dropIndex('pwi_watchlist_updated_idx');
                }
                $table->dropConstrainedForeignId('watchlist_id');
            });

            Schema::table('portfolio_watchlist_items', function (Blueprint $table) {
                if (! $this->indexExists('portfolio_watchlist_items', 'pwi_profile_stock_unique')) {
                    $table->unique(['profile_id', 'stock_id'], 'pwi_profile_stock_unique');
                }
            });
        }

        Schema::dropIfExists('portfolio_watchlists');
    }

    private function migrateExistingItems(): void
    {
        if (! Schema::hasTable('portfolio_watchlist_items') || ! Schema::hasTable('portfolio_watchlists')) {
            return;
        }

        $profileIds = DB::table('portfolio_watchlist_items')
            ->distinct()
            ->pluck('profile_id')
            ->merge(
                DB::table('portfolio_profiles')->pluck('id')
            )
            ->unique()
            ->values();

        foreach ($profileIds as $profileId) {
            $watchlistId = DB::table('portfolio_watchlists')
                ->where('profile_id', $profileId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->value('id');

            if ($watchlistId === null) {
                $watchlistId = DB::table('portfolio_watchlists')->insertGetId([
                    'profile_id' => $profileId,
                    'name' => 'My Watchlist',
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('portfolio_watchlist_items')
                ->where('profile_id', $profileId)
                ->whereNull('watchlist_id')
                ->update(['watchlist_id' => $watchlistId]);
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if (($row->name ?? null) === $index) {
                    return true;
                }
            }

            return false;
        }

        $database = $connection->getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
