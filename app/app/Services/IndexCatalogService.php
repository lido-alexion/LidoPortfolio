<?php

namespace App\Services;

use App\Models\Stock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class IndexCatalogService
{
    public function __construct(
        protected ProviderResolverService $resolver,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('portfolio.indexes.enabled', true);
    }

    public function primarySymbol(): string
    {
        return strtoupper((string) config('portfolio.indexes.primary_symbol', 'NIFTY50'));
    }

    /**
     * @return array<int, array{
     *   symbol: string,
     *   name: string,
     *   exchange: string,
     *   nse_charting_name: ?string,
     *   yahoo_symbol: string,
     *   alpha_vantage_symbol: ?string,
     *   enabled: bool
     * }>
     */
    public function definitions(): array
    {
        $raw = config('portfolio.indexes.definitions', []);
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row) || empty($row['symbol'])) {
                continue;
            }
            $out[] = [
                'symbol' => strtoupper(trim((string) $row['symbol'])),
                'name' => (string) ($row['name'] ?? $row['symbol']),
                'exchange' => strtoupper((string) ($row['exchange'] ?? 'NSE')),
                'nse_charting_name' => isset($row['nse_charting_name']) && $row['nse_charting_name'] !== ''
                    ? (string) $row['nse_charting_name']
                    : null,
                'yahoo_symbol' => (string) ($row['yahoo_symbol'] ?? ''),
                'alpha_vantage_symbol' => isset($row['alpha_vantage_symbol']) && $row['alpha_vantage_symbol'] !== ''
                    ? (string) $row['alpha_vantage_symbol']
                    : null,
                'enabled' => (bool) ($row['enabled'] ?? true),
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function enabledDefinitions(): array
    {
        return array_values(array_filter(
            $this->definitions(),
            static fn (array $row) => $row['enabled'] === true,
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function definitionForSymbol(string $symbol): ?array
    {
        $symbol = strtoupper(trim($symbol));
        foreach ($this->definitions() as $row) {
            if ($row['symbol'] === $symbol) {
                return $row;
            }
        }

        return null;
    }

    public function isConfiguredIndex(string $symbol): bool
    {
        return $this->definitionForSymbol($symbol) !== null;
    }

    public function nseChartingNameForSymbol(string $symbol): ?string
    {
        $def = $this->definitionForSymbol($symbol);

        return $def['nse_charting_name'] ?? null;
    }

    /**
     * @return Collection<int, Stock>
     */
    public function ensureAllIndexStocks(): Collection
    {
        $stocks = collect();
        foreach ($this->enabledDefinitions() as $def) {
            $stocks->push($this->ensureIndexStock($def));
        }

        return $stocks->sortBy('symbol')->values();
    }

    /**
     * @param  array<string, mixed>  $def
     */
    public function ensureIndexStock(array $def): Stock
    {
        $stock = Stock::query()->firstOrCreate(
            [
                'symbol' => $def['symbol'],
                'exchange' => $def['exchange'],
            ],
            [
                'name' => $def['name'],
                'is_active' => true,
                'is_benchmark' => true,
                'yahoo_symbol' => $def['yahoo_symbol'] ?: null,
                'alpha_vantage_symbol' => $def['alpha_vantage_symbol'] ?: null,
            ],
        );

        $dirty = false;
        if (! $stock->is_benchmark) {
            $stock->is_benchmark = true;
            $dirty = true;
        }
        if (! $stock->is_active) {
            $stock->is_active = true;
            $dirty = true;
        }
        if ($stock->name !== $def['name']) {
            $stock->name = $def['name'];
            $dirty = true;
        }
        if ($def['yahoo_symbol'] && $stock->yahoo_symbol !== $def['yahoo_symbol']) {
            $stock->yahoo_symbol = $def['yahoo_symbol'];
            $dirty = true;
        }
        if ($def['alpha_vantage_symbol'] && $stock->alpha_vantage_symbol !== $def['alpha_vantage_symbol']) {
            $stock->alpha_vantage_symbol = $def['alpha_vantage_symbol'];
            $dirty = true;
        }

        $stock = $this->resolver->applyProviderSymbols($stock);
        if ($dirty || $stock->isDirty()) {
            $stock->save();
        }

        return $stock->fresh();
    }

    public function primaryBenchmarkStock(): Stock
    {
        $def = $this->definitionForSymbol($this->primarySymbol());
        if ($def === null) {
            $def = [
                'symbol' => 'NIFTY50',
                'name' => 'Nifty 50',
                'exchange' => 'NSE',
                'nse_charting_name' => 'NIFTY 50',
                'yahoo_symbol' => '^NSEI',
                'alpha_vantage_symbol' => 'NSEI',
                'enabled' => true,
            ];
        }

        return $this->ensureIndexStock($def);
    }

    /**
     * @return Builder<Stock>
     */
    public function indexStockQuery(): Builder
    {
        $symbols = array_column($this->enabledDefinitions(), 'symbol');

        return Stock::query()
            ->where('is_benchmark', true)
            ->whereIn('symbol', $symbols !== [] ? $symbols : ['__none__'])
            ->orderBy('symbol');
    }

    /**
     * @return array<int, string>
     */
    public function enabledSymbolsOrdered(): array
    {
        return array_column($this->enabledDefinitions(), 'symbol');
    }
}
