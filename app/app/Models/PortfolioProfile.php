<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PortfolioProfile extends Model
{
    use SoftDeletes;

    protected $table = 'portfolio_profiles';

    protected $fillable = [
        'user_id',
        'name',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'is_default' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        return static::query()
            ->where('user_id', $user->id)
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'profile_id');
    }

    public function holdings(): HasMany
    {
        return $this->hasMany(Holding::class, 'profile_id');
    }

    public function portfolioSnapshots(): HasMany
    {
        return $this->hasMany(PortfolioSnapshot::class, 'profile_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class, 'profile_id');
    }

    public function alertPolicies(): HasMany
    {
        return $this->hasMany(AlertPolicy::class, 'profile_id');
    }

    public function settings(): HasMany
    {
        return $this->hasMany(ProfileSetting::class, 'profile_id');
    }

    public function watchlistItems(): HasMany
    {
        return $this->hasMany(\App\Models\WatchlistItem::class, 'profile_id');
    }

    public function knowledgeNotes(): HasMany
    {
        return $this->hasMany(KnowledgeNote::class, 'profile_id');
    }

    public function knowledgeTags(): HasMany
    {
        return $this->hasMany(KnowledgeTag::class, 'profile_id');
    }
}
