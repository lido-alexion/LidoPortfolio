<?php

namespace App\Services\Backtest;

/**
 * Mutable simulation state persisted between resume requests in BacktestRun.context_json.
 * Cleared when the run reaches COMPLETED / FAILED.
 */
final class SimulationContext
{
    public const TIME_BUDGET_SECONDS = 20.0;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private array $data = []) {}

    public static function blank(float $initialCapital, array $tradingDays): self
    {
        return new self([
            'cash' => $initialCapital,
            'realized_profit' => 0.0,
            'peak_portfolio_value' => $initialCapital,
            'max_concurrent_positions' => 0,
            'utilization_sum' => 0.0,
            'utilization_days' => 0,
            'holdings' => [], // stock_id => {qty, avg_cost, buy_date, invested, symbol}
            'open_lots' => [], // stock_id => list of {qty, price, buy_date}
            'day_cursor' => 0,
            'trading_days' => $tradingDays,
            'eligibility' => [
                'screeners' => [], // list of {screener_id, role, stock_ids[], stock_cursor, stock_total, done}
                'phase' => 'pending', // pending|running|done
            ],
            'config_snapshot' => null,
            'warnings' => [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public static function fromArray(?array $data): self
    {
        return new self(is_array($data) ? $data : []);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function cash(): float
    {
        return (float) ($this->data['cash'] ?? 0);
    }

    public function setCash(float $cash): void
    {
        $this->data['cash'] = round($cash, 4);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function holdings(): array
    {
        $h = $this->data['holdings'] ?? [];

        return is_array($h) ? $h : [];
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $holdings
     */
    public function setHoldings(array $holdings): void
    {
        $this->data['holdings'] = $holdings;
    }

    /**
     * @return list<string>
     */
    public function tradingDays(): array
    {
        $days = $this->data['trading_days'] ?? [];

        return is_array($days) ? array_values(array_map('strval', $days)) : [];
    }

    public function dayCursor(): int
    {
        return (int) ($this->data['day_cursor'] ?? 0);
    }

    public function setDayCursor(int $cursor): void
    {
        $this->data['day_cursor'] = $cursor;
    }

    public function addWarning(string $message): void
    {
        $warnings = is_array($this->data['warnings'] ?? null) ? $this->data['warnings'] : [];
        if (! in_array($message, $warnings, true)) {
            $warnings[] = $message;
        }
        $this->data['warnings'] = $warnings;
    }
}
