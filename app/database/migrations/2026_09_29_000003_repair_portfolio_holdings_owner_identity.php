<?php

use App\Models\Holding;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_holdings')
            || ! Schema::hasColumn('portfolio_holdings', 'profile_id')
            || ! Schema::hasColumn('portfolio_holdings', 'stock_id')
            || ! Schema::hasColumn('portfolio_holdings', 'owner_key')) {
            return;
        }

        // The deployed database is MySQL. SQLite is used for the fast test
        // suite and has no SHOW INDEX equivalent; its schema is built from
        // the current migrations and already has the owner-aware constraint.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Older deployments may have rows created before owner-aware holdings
        // identity was introduced. Normalize those rows before enforcing the
        // three-column identity so a transaction edit cannot create a duplicate.
        DB::table('portfolio_holdings')
            ->whereNull('owner_key')
            ->update(['owner_key' => Holding::OWNER_UNMANAGED]);

        $indexes = DB::select('SHOW INDEX FROM portfolio_holdings');
        $columnsByIndex = [];
        foreach ($indexes as $index) {
            $name = (string) ($index->Key_name ?? '');
            if ($name === 'PRIMARY') {
                continue;
            }
            $columnsByIndex[$name][(int) ($index->Seq_in_index ?? 0)] = (string) ($index->Column_name ?? '');
        }

        foreach ($columnsByIndex as $name => $columns) {
            ksort($columns);
            if (array_values($columns) !== ['profile_id', 'stock_id']) {
                continue;
            }

            $isUnique = collect($indexes)
                ->first(fn ($index) => (string) ($index->Key_name ?? '') === $name)
                ?->Non_unique === 0;
            if (! $isUnique) {
                continue;
            }

            Schema::table('portfolio_holdings', function (Blueprint $table) use ($name) {
                $table->dropUnique($name);
            });
        }

        $hasOwnerIdentity = collect(DB::select('SHOW INDEX FROM portfolio_holdings'))
            ->filter(fn ($index) => (string) ($index->Key_name ?? '') === 'pph_prof_stock_owner_uq')
            ->isNotEmpty();

        if (! $hasOwnerIdentity) {
            Schema::table('portfolio_holdings', function (Blueprint $table) {
                $table->unique(
                    ['profile_id', 'stock_id', 'owner_key'],
                    'pph_prof_stock_owner_uq',
                );
            });
        }
    }

    public function down(): void
    {
        // This repair is intentionally non-destructive and does not restore
        // the obsolete uniqueness constraint on (profile_id, stock_id).
    }
};
