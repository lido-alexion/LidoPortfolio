<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class UserSetting extends Model
{
    public $timestamps = false;

    protected $table = 'portfolio_user_settings';

    protected $fillable = [
        'user_id',
        'setting_key',
        'setting_value',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function getValue(int $userId, string $key, ?string $default = null): ?string
    {
        return Cache::remember("user_setting.{$userId}.{$key}", 60, function () use ($userId, $key, $default) {
            $setting = static::query()
                ->where('user_id', $userId)
                ->where('setting_key', $key)
                ->first();

            return $setting?->setting_value ?? $default;
        });
    }

    public static function setValue(int $userId, string $key, ?string $value): void
    {
        static::query()->updateOrCreate(
            ['user_id' => $userId, 'setting_key' => $key],
            ['setting_value' => $value, 'updated_at' => now()],
        );

        Cache::forget("user_setting.{$userId}.{$key}");
    }
}
