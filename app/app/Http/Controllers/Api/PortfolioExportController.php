<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PortfolioCsvExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortfolioExportController extends Controller
{
    public function __construct(protected PortfolioCsvExportService $exports) {}

    public function show(Request $request, string $dataset): StreamedResponse
    {
        abort_unless(in_array($dataset, [
            'cash_statement', 'historical_holdings', 'portfolio_compare', 'current_holdings',
            'transactions', 'portfolio_value_history', 'recommendations', 'orders_trades',
        ], true), 404);
        $rules = match ($dataset) {
            'cash_statement' => [
                'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
                'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today'],
            ],
            'historical_holdings' => ['as_of' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']],
            'portfolio_compare' => [
                'date_a' => ['required', 'date_format:Y-m-d', 'before:date_b', 'before_or_equal:today'],
                'date_b' => ['required', 'date_format:Y-m-d', 'after:date_a', 'before_or_equal:today'],
            ],
            default => [],
        };

        return $this->exports->response(\activePortfolio(), $dataset, $request->validate($rules));
    }
}
