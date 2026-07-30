<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Stock extends Model
{
    protected $table = 'portfolio_stocks';

    protected $fillable = [
        'symbol',
        'exchange',
        'series',
        'name',
        'isin',
        'bse_scrip_code',
        'sector',
        'yahoo_symbol',
        'alpha_vantage_symbol',
        'is_active',
        'is_benchmark',
        'is_dual_listed',
        'last_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_benchmark' => 'boolean',
            'is_dual_listed' => 'boolean',
            'last_verified_at' => 'datetime',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function holdings(): HasMany
    {
        return $this->hasMany(Holding::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(StockPrice::class);
    }

    public function metrics(): HasOne
    {
        return $this->hasOne(StockMetric::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function dataQualityIssues(): HasMany
    {
        return $this->hasMany(DataQualityIssue::class);
    }

    public function priceAdjustmentFactors(): HasMany
    {
        return $this->hasMany(PriceAdjustmentFactor::class);
    }
}
