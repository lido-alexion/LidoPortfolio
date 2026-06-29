<?php

use App\Models\AlertPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_alert_policies')) {
            return;
        }

        if (! Schema::hasColumn('portfolio_alert_policies', 'context_template')) {
            Schema::table('portfolio_alert_policies', function (Blueprint $table) {
                $table->text('context_template')->nullable()->after('message_template');
            });
        }

        $this->migrateLegacyContextColumns();
    }

    public function down(): void
    {
        if (! Schema::hasTable('portfolio_alert_policies')) {
            return;
        }

        if (! Schema::hasColumn('portfolio_alert_policies', 'context_template')) {
            return;
        }

        Schema::table('portfolio_alert_policies', function (Blueprint $table) {
            $table->dropColumn('context_template');
        });
    }

    protected function migrateLegacyContextColumns(): void
    {
        if (! class_exists(AlertPolicy::class)) {
            return;
        }

        $labels = [
            'symbol' => 'Symbol',
            'stock_name' => 'Stock name',
            'exchange' => 'Exchange',
            'quantity' => 'Quantity',
            'avg_buy_price' => 'Avg buy',
            'invested_amount' => 'Invested',
            'total_fees' => 'Fees',
            'realized_profit' => 'Realized P/L',
            'xirr' => 'XIRR',
            'latest_close' => 'Latest close',
            'latest_price_date' => 'Latest price date',
            'highest_close_since_buy' => 'Highest close since buy',
            'highest_close_since_buy_date' => 'Highest close date',
            'trailing_stop_price' => 'Trailing stop',
            'stoploss_percent' => 'Stoploss %',
            'first_buy_date' => 'First buy date',
            'price_row_count' => 'Price row count',
            'market_value' => 'Market value',
            'gain_loss_amount' => 'Gain/loss amount',
            'gain_loss_percent' => 'Gain/loss %',
        ];

        AlertPolicy::query()
            ->whereNull('context_template')
            ->whereNotNull('context_columns')
            ->each(function (AlertPolicy $policy) use ($labels) {
                $columns = $policy->context_columns;
                if (! is_array($columns) || $columns === []) {
                    return;
                }

                $lines = [];
                foreach ($columns as $key) {
                    if (! is_string($key) || $key === '') {
                        continue;
                    }
                    $label = $labels[$key] ?? $key;
                    $lines[] = "{$label}: {{$key}}";
                }

                if ($lines === []) {
                    return;
                }

                $policy->update(['context_template' => implode("\n", $lines)]);
            });
    }
};
