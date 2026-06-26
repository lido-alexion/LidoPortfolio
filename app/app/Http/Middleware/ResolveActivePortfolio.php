<?php

namespace App\Http\Middleware;

use App\Models\PortfolioProfile;
use App\Services\PortfolioProfileService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveActivePortfolio
{
    public function __construct(
        protected PortfolioProfileService $portfolios,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $portfolioId = $request->header('X-Profile-Id')
            ?? $request->header('X-Portfolio-Id')
            ?? $request->query('portfolio_id');

        if ($portfolioId !== null && $portfolioId !== '') {
            $profile = PortfolioProfile::query()
                ->where('user_id', $user->id)
                ->where('id', (int) $portfolioId)
                ->first();

            if ($profile === null) {
                abort(404, 'Portfolio not found.');
            }
        } else {
            $profile = $this->portfolios->defaultForUser($user);

            if ($profile === null) {
                $profile = $this->portfolios->createDefaultForUser($user);
            }
        }

        $request->attributes->set('active_portfolio', $profile);

        return $next($request);
    }
}
