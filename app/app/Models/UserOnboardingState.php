<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserOnboardingState extends Model
{
    protected $table = 'stox_user_onboarding_state';

    protected $fillable = [
        'user_id',
        'tour_version',
        'welcome_prompt_count',
        'permanently_dismissed_at',
        'completed_at',
        'current_step_id',
        'tour_in_progress',
    ];

    protected function casts(): array
    {
        return [
            'welcome_prompt_count' => 'integer',
            'permanently_dismissed_at' => 'datetime',
            'completed_at' => 'datetime',
            'tour_in_progress' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
