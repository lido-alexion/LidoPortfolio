<?php

namespace App\Services;

use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StockResolverService
{
    public function __construct(
        protected StockValidationService $validation,
        protected ProviderResolverService $resolver,
    ) {}

    /**
     * Resolve stock from stock_id or symbol (validated against master / providers).
     */
    public function resolve(Request $request, bool $allowCreate = true): Stock
    {
        if ($request->filled('stock_id')) {
            return $this->resolveByStockId((int) $request->input('stock_id'));
        }

        $request->validate([
            'symbol' => ['required', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:255'],
            'exchange' => ['nullable', 'string', 'in:NSE,BSE'],
            'sector' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->resolveFromAttributes([
            'symbol' => $request->input('symbol'),
            'name' => $request->input('name'),
            'exchange' => $request->input('exchange'),
            'isin' => $request->input('isin'),
            'sector' => $request->input('sector'),
        ], $allowCreate);
    }

    public function resolveByStockId(int $stockId): Stock
    {
        $stock = Stock::query()
            ->where('is_benchmark', false)
            ->where('is_active', true)
            ->find($stockId);

        if (! $stock) {
            throw ValidationException::withMessages([
                'stock_id' => ['Invalid or inactive stock selected.'],
            ]);
        }

        return $stock;
    }

    /**
     * @param  array{symbol?: string, name?: ?string, exchange?: ?string, isin?: ?string, sector?: ?string, stock_id?: int|null}  $attrs
     */
    public function resolveFromAttributes(array $attrs, bool $allowCreate = true): Stock
    {
        if (! empty($attrs['stock_id'])) {
            return $this->resolveByStockId((int) $attrs['stock_id']);
        }

        $symbol = strtoupper(trim((string) ($attrs['symbol'] ?? '')));
        $exchange = strtoupper((string) ($attrs['exchange'] ?? 'NSE'));
        if ($exchange === '') {
            $exchange = 'NSE';
        }

        if ($symbol === '') {
            throw ValidationException::withMessages([
                'symbol' => ['Symbol is required.'],
            ]);
        }

        if ($this->resolver->isMalformed($symbol)) {
            throw ValidationException::withMessages([
                'symbol' => ['Symbol format is invalid.'],
            ]);
        }

        if (! $allowCreate) {
            $result = $this->validation->validate($symbol, $exchange, false);
            if (! $result->valid || ! $result->stock) {
                throw ValidationException::withMessages([
                    'symbol' => ["Stock {$symbol} ({$exchange}) is not in the master list."],
                ]);
            }

            return $result->stock;
        }

        $result = $this->validation->validateAndPersist(
            $symbol,
            $exchange,
            $attrs['name'] ?? null,
            $attrs['isin'] ?? null,
            $attrs['sector'] ?? null,
        );

        if (! $result->valid || ! $result->stock) {
            throw ValidationException::withMessages([
                'symbol' => $result->errors ?: ['Unable to validate stock symbol. Check symbol and exchange.'],
            ]);
        }

        return $result->stock;
    }
}
