<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'profile_photo_path'])]
#[Hidden(['password', 'remember_token', 'profile_photo_path'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $appends = [
        'profile_photo_url',
    ];
    
    protected $table = 'portfolio_users';

    public function portfolios(): HasMany
    {
        return $this->hasMany(PortfolioProfile::class);
    }

    public function transactions(): HasManyThrough
    {
        return $this->hasManyThrough(Transaction::class, PortfolioProfile::class, 'user_id', 'profile_id');
    }

    public function holdings(): HasManyThrough
    {
        return $this->hasManyThrough(Holding::class, PortfolioProfile::class, 'user_id', 'profile_id');
    }

    public function portfolioSnapshots(): HasManyThrough
    {
        return $this->hasManyThrough(PortfolioSnapshot::class, PortfolioProfile::class, 'user_id', 'profile_id');
    }

    public function alerts(): HasManyThrough
    {
        return $this->hasManyThrough(Alert::class, PortfolioProfile::class, 'user_id', 'profile_id');
    }

    public function getProfilePhotoUrlAttribute(): ?string
    {
        if (! $this->profile_photo_path) {
            return null;
        }

        $version = $this->updated_at?->timestamp ?? time();
        $appPath = parse_url((string) config('app.url'), PHP_URL_PATH) ?: '';

        return rtrim($appPath, '/').'/api/profile/photo?v='.$version;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }
}
