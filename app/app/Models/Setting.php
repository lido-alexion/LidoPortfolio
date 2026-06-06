<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    public $timestamps = false;
    protected $table = 'portfolio_settings';

    protected $fillable = [
        'setting_key',
        'setting_value',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'updated_at' => 'datetime',
        ];
    }

    public static function getValue(string $key, ?string $default = null): ?string
    {
        return Cache::remember("setting.{$key}", 60, function () use ($key, $default) {
            $setting = static::query()->where('setting_key', $key)->first();

            return $setting?->setting_value ?? $default;
        });
    }

    public static function setValue(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(
            ['setting_key' => $key],
            ['setting_value' => $value, 'updated_at' => now()],
        );

        Cache::forget("setting.{$key}");
    }
}
