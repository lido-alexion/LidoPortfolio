<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\PortfolioProfile;

class AlertService
{
    public function getActiveForProfile(PortfolioProfile $profile): array
    {
        return Alert::query()
            ->active()
            ->with('stock')
            ->where('profile_id', $profile->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->toArray();
    }
}
