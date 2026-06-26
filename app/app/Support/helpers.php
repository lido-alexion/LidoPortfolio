<?php

use App\Models\PortfolioProfile;

if (! function_exists('activePortfolio')) {
    function activePortfolio(): ?PortfolioProfile
    {
        $request = request();

        if ($request === null || ! $request->attributes->has('active_portfolio')) {
            return null;
        }

        $profile = $request->attributes->get('active_portfolio');

        return $profile instanceof PortfolioProfile ? $profile : null;
    }
}
