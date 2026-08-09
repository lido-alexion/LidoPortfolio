<?php

namespace Tests\Concerns;

use App\Models\DataQualityIssue;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

trait CreatesDataQualityFixtures
{
    protected function createDataQualityStock(string $symbol = 'DQTEST'): Stock
    {
        return Stock::query()->create([
            'symbol' => $symbol,
            'name' => $symbol.' Ltd',
            'exchange' => 'NSE',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
    }

    protected function seedGapPrices(Stock $stock, float $prevClose, float $currOpen, string $prevDate = '2026-01-01', string $currDate = '2026-01-02'): void
    {
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => $prevDate,
            'open_price' => $prevClose,
            'high_price' => $prevClose,
            'low_price' => $prevClose,
            'close_price' => $prevClose,
            'volume' => 100000,
            'data_source' => 'test',
            'created_at' => now(),
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => $currDate,
            'open_price' => $currOpen,
            'high_price' => $currOpen,
            'low_price' => $currOpen,
            'close_price' => $currOpen,
            'volume' => 200000,
            'data_source' => 'test',
            'created_at' => now(),
        ]);
    }

    protected function createPendingExchangeIssue(Stock $stock, array $overrides = []): DataQualityIssue
    {
        return DataQualityIssue::query()->create(array_merge([
            'stock_id' => $stock->id,
            'symbol' => $stock->symbol,
            'issue_type' => DataQualityIssue::TYPE_CORPORATE_ACTION,
            'issue_status' => DataQualityIssue::STATUS_PENDING_REVIEW,
            'detection_method' => DataQualityIssue::DETECTION_METHOD_EXCHANGE_FEED,
            'detection_source' => 'exchange_feed',
            'suggested_ratio' => 2.0,
            'latest_suggested_ratio' => 2.0,
            'confidence' => 1.0,
            'corporate_action_type' => 'split',
            'ex_date' => '2026-01-15',
            'exchange_match' => true,
            'detected_at' => now(),
        ], $overrides));
    }

    protected function createPendingHeuristicIssue(Stock $stock, array $overrides = []): DataQualityIssue
    {
        return DataQualityIssue::query()->create(array_merge([
            'stock_id' => $stock->id,
            'symbol' => $stock->symbol,
            'issue_type' => DataQualityIssue::TYPE_CORPORATE_ACTION,
            'issue_status' => DataQualityIssue::STATUS_PENDING_REVIEW,
            'detection_method' => DataQualityIssue::DETECTION_METHOD_HEURISTIC_GAP,
            'detection_source' => 'heuristic',
            'suggested_ratio' => 2.0,
            'latest_suggested_ratio' => 2.0,
            'confidence' => 0.85,
            'corporate_action_type' => 'split',
            'ex_date' => '2026-01-02',
            'exchange_match' => false,
            'detected_at' => now(),
        ], $overrides));
    }

    protected function createAdminUser(): User
    {
        $user = User::query()->create([
            'name' => 'DQ Admin',
            'email' => 'dq-admin-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ]);
        $user->is_admin = true;
        $user->save();
        $this->defaultPortfolioFor($user);

        return $user;
    }

    protected function createRegularUser(): User
    {
        $user = User::query()->create([
            'name' => 'DQ User',
            'email' => 'dq-user-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
            'is_admin' => false,
        ]);
        $this->defaultPortfolioFor($user);

        return $user;
    }
}
