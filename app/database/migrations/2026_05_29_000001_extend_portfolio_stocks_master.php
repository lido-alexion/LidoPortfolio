<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_stocks', function (Blueprint $table) {
            if (! Schema::hasColumn('portfolio_stocks', 'yahoo_symbol')) {
                $table->string('yahoo_symbol', 32)->nullable()->after('sector');
            }
            if (! Schema::hasColumn('portfolio_stocks', 'alpha_vantage_symbol')) {
                $table->string('alpha_vantage_symbol', 32)->nullable()->after('yahoo_symbol');
            }
            if (! Schema::hasColumn('portfolio_stocks', 'last_verified_at')) {
                $table->timestamp('last_verified_at')->nullable()->after('is_benchmark');
            }
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $this->upMysqlIndexes();
        } else {
            $this->upDefaultIndexes();
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $this->downMysqlIndexes();
        } else {
            $this->downDefaultIndexes();
        }

        Schema::table('portfolio_stocks', function (Blueprint $table) {
            $drop = [];
            if (Schema::hasColumn('portfolio_stocks', 'yahoo_symbol')) {
                $drop[] = 'yahoo_symbol';
            }
            if (Schema::hasColumn('portfolio_stocks', 'alpha_vantage_symbol')) {
                $drop[] = 'alpha_vantage_symbol';
            }
            if (Schema::hasColumn('portfolio_stocks', 'last_verified_at')) {
                $drop[] = 'last_verified_at';
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }

    private function upMysqlIndexes(): void
    {
        $indexes = $this->portfolioStocksIndexNames();

        if ($indexes->contains('portfolio_stocks_symbol_exchange_unique')) {
            return;
        }

        try {
            Schema::table('portfolio_stocks', function (Blueprint $table) {
                $table->unique(['symbol', 'exchange'], 'portfolio_stocks_symbol_exchange_unique');
            });
        } catch (QueryException $e) {
            if ($this->isIndexPrivilegeDenied($e)) {
                $this->restoreSymbolUniqueIfMissing();

                return;
            }

            throw $e;
        }

        $indexes = $this->portfolioStocksIndexNames();

        if ($indexes->contains('portfolio_stocks_symbol_unique')) {
            try {
                Schema::table('portfolio_stocks', function (Blueprint $table) {
                    $table->dropUnique('portfolio_stocks_symbol_unique');
                });
            } catch (QueryException $e) {
                if (! $this->isIndexPrivilegeDenied($e)) {
                    throw $e;
                }
            }
        }
    }

    private function downMysqlIndexes(): void
    {
        $indexes = $this->portfolioStocksIndexNames();

        if ($indexes->contains('portfolio_stocks_symbol_exchange_unique')) {
            try {
                Schema::table('portfolio_stocks', function (Blueprint $table) {
                    $table->dropUnique('portfolio_stocks_symbol_exchange_unique');
                });
            } catch (QueryException $e) {
                if (! $this->isIndexPrivilegeDenied($e)) {
                    throw $e;
                }
            }
        }

        $indexes = $this->portfolioStocksIndexNames();

        if (! $indexes->contains('portfolio_stocks_symbol_unique')) {
            try {
                Schema::table('portfolio_stocks', function (Blueprint $table) {
                    $table->unique('symbol', 'portfolio_stocks_symbol_unique');
                });
            } catch (QueryException $e) {
                if (! $this->isIndexPrivilegeDenied($e)) {
                    throw $e;
                }
            }
        }
    }

    private function upDefaultIndexes(): void
    {
        Schema::table('portfolio_stocks', function (Blueprint $table) {
            $table->dropUnique(['symbol']);
            $table->unique(['symbol', 'exchange']);
        });
    }

    private function downDefaultIndexes(): void
    {
        Schema::table('portfolio_stocks', function (Blueprint $table) {
            $table->dropUnique(['symbol', 'exchange']);
            $table->unique('symbol');
        });
    }

    /** @return \Illuminate\Support\Collection<int, string> */
    private function portfolioStocksIndexNames()
    {
        return collect(DB::select('SHOW INDEX FROM portfolio_stocks'))
            ->pluck('Key_name')
            ->unique();
    }

    private function isIndexPrivilegeDenied(QueryException $e): bool
    {
        return (string) $e->getCode() === '42000' && str_contains($e->getMessage(), '1142');
    }

    private function restoreSymbolUniqueIfMissing(): void
    {
        $indexes = $this->portfolioStocksIndexNames();

        if ($indexes->contains('portfolio_stocks_symbol_unique')
            || $indexes->contains('portfolio_stocks_symbol_exchange_unique')) {
            return;
        }

        try {
            Schema::table('portfolio_stocks', function (Blueprint $table) {
                $table->unique('symbol', 'portfolio_stocks_symbol_unique');
            });
        } catch (QueryException $e) {
            if (! $this->isIndexPrivilegeDenied($e)) {
                throw $e;
            }
        }
    }
};
