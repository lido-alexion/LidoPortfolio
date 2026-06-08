<?php

use App\Models\Transaction;
use App\Services\FeeCalculatorService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $calculator = app(FeeCalculatorService::class);
        $components = $calculator->componentsFromSettings();

        Transaction::query()
            ->with('stock')
            ->orderBy('id')
            ->chunkById(200, function ($transactions) use ($calculator, $components) {
                foreach ($transactions as $transaction) {
                    $exchange = $transaction->stock?->exchange ?? 'NSE';
                    $result = $calculator->calculate(
                        (float) $transaction->quantity,
                        (float) $transaction->price,
                        $transaction->type,
                        $exchange,
                        $components,
                    );
                    $transaction->update(['fees' => $result['total']]);
                }
            });
    }

    public function down(): void
    {
        // Historical fee values cannot be restored after recalculation.
    }
};
