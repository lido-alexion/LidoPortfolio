<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class ProfileSetting extends Model
{
    public $timestamps = false;

    protected $table = 'portfolio_profile_settings';

    protected $fillable = [
        'profile_id',
        'setting_key',
        'setting_value',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PortfolioProfile::class, 'profile_id');
    }

    public static function getValue(int $profileId, string $key, ?string $default = null): ?string
    {
        return Cache::remember("profile_setting.{$profileId}.{$key}", 60, function () use ($profileId, $key, $default) {
            $setting = static::query()
                ->where('profile_id', $profileId)
                ->where('setting_key', $key)
                ->first();

            return $setting?->setting_value ?? $default;
        });
    }

    public static function setValue(int $profileId, string $key, ?string $value): void
    {
        static::query()->updateOrCreate(
            ['profile_id' => $profileId, 'setting_key' => $key],
            ['setting_value' => $value, 'updated_at' => now()],
        );

        Cache::forget("profile_setting.{$profileId}.{$key}");
    }
}
