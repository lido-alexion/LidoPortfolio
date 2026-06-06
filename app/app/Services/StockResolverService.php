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
            $stock = Stock::query()
                ->where('is_benchmark', false)
                ->where('is_active', true)
                ->find($request->input('stock_id'));

            if (! $stock) {
                throw ValidationException::withMessages([
                    'stock_id' => ['Invalid or inactive stock selected.'],
                ]);
            }

            return $stock;
        }

        $request->validate([
            'symbol' => ['required', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:255'],
            'exchange' => ['nullable', 'string', 'in:NSE,BSE'],
            'sector' => ['nullable', 'string', 'max:100'],
        ]);

        $symbol = strtoupper(trim((string) $request->input('symbol')));
        $exchange = strtoupper((string) $request->input('exchange', 'NSE'));

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
            $request->input('name'),
            $request->input('isin'),
            $request->input('sector'),
        );

        if (! $result->valid || ! $result->stock) {
            throw ValidationException::withMessages([
                'symbol' => $result->errors ?: ['Unable to validate stock symbol. Check symbol and exchange.'],
            ]);
        }

        return $result->stock;
    }
}
