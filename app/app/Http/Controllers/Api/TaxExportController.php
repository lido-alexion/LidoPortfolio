<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analytics\TaxCsvExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaxExportController extends Controller
{
    public function __construct(private TaxCsvExportService $exports) {}

    public function show(Request $request, string $dataset): StreamedResponse
    {
        abort_unless(in_array($dataset, ['realized_gains', 'open_lots', 'dividends', 'losses', 'summary', 'assumptions'], true), 404);
        $validated = $request->validate([
            'financial_year' => ['required', 'regex:/^\\d{4}-\\d{2}$/'],
            'portfolio_ids' => ['sometimes', 'array'],
            'portfolio_ids.*' => ['integer', 'distinct'],
        ]);

        return $this->exports->response(
            $request->user(), $dataset, $validated['financial_year'],
            array_key_exists('portfolio_ids', $validated) ? $validated['portfolio_ids'] : null,
        );
    }
}
