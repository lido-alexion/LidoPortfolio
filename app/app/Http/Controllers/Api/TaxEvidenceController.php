<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dividend;
use App\Models\OpeningTaxLot;
use App\Models\Stock;
use App\Models\TaxLoss;
use App\Services\Analytics\AccountTaxReportService;
use App\Services\Analytics\DividendStatementImportService;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TaxEvidenceController extends Controller
{
    public function __construct(
        private AccountTaxReportService $reports,
        private DividendStatementImportService $dividendImports,
    ) {}

    public function importDividends(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:2048', 'mimes:csv,txt'],
            'definition_version' => ['required', Rule::in([DividendStatementImportService::DEFINITION_VERSION])],
            'dry_run' => ['sometimes', 'boolean'],
        ]);
        $result = $this->dividendImports->import(
            $request->user(), $validated['file'], (bool) ($validated['dry_run'] ?? true),
        );

        return response()->json(['data' => $result], $result['valid'] ? 200 : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function report(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'financial_year' => ['required', 'regex:/^\\d{4}-\\d{2}$/'],
            'portfolio_ids' => ['sometimes', 'array'],
            'portfolio_ids.*' => ['integer', 'distinct'],
        ]);

        return response()->json(['data' => $this->reports->calculate(
            $request->user(),
            $validated['financial_year'],
            array_key_exists('portfolio_ids', $validated) ? $validated['portfolio_ids'] : null,
        )]);
    }

    public function dividends(Request $request): JsonResponse
    {
        $rows = Dividend::query()
            ->where('user_id', $request->user()->id)
            ->with('stock:id,symbol,name')
            ->orderByDesc('received_on')
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 50), 1), 200));

        return response()->json($rows);
    }

    public function storeDividend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stock_id' => ['nullable', 'integer', Rule::exists('portfolio_stocks', 'id')],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'source_reference' => ['nullable', 'string', 'max:255'],
            'source_evidence' => ['nullable', 'array'],
        ]);

        $deduplicationKey = hash('sha256', implode('|', [
            $request->user()->id,
            $validated['stock_id'] ?? 'unknown',
            $validated['received_on'],
            number_format((float) $validated['amount'], 4, '.', ''),
            strtolower(trim((string) ($validated['source_reference'] ?? 'manual'))),
        ]));

        $dividend = DB::transaction(function () use ($request, $validated, $deduplicationKey) {
            if (Dividend::query()->where('user_id', $request->user()->id)->where('deduplication_key', $deduplicationKey)->exists()) {
                throw ValidationException::withMessages(['dividend' => 'This dividend evidence is already recorded.']);
            }

            return Dividend::query()->create([
                'user_id' => $request->user()->id,
                'stock_id' => $validated['stock_id'] ?? null,
                'received_on' => $validated['received_on'],
                'amount' => $validated['amount'],
                'currency' => strtoupper($validated['currency'] ?? 'INR'),
                'source' => 'manual',
                'source_reference' => $validated['source_reference'] ?? null,
                'deduplication_key' => $deduplicationKey,
                'source_evidence' => $validated['source_evidence'] ?? null,
            ]);
        });

        return response()->json(['data' => $dividend->load('stock:id,symbol,name')], 201);
    }

    public function taxLosses(Request $request): JsonResponse
    {
        return response()->json(['data' => TaxLoss::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('financial_year')
            ->orderByDesc('id')
            ->get()]);
    }

    public function storeTaxLoss(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'financial_year' => ['required', 'regex:/^\\d{4}-\\d{2}$/'],
            'loss_type' => ['required', Rule::in(['short_term', 'long_term'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'status' => ['sometimes', Rule::in(['calculated', 'confirmed'])],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'reason' => ['required_if:status,confirmed', 'nullable', 'string', 'max:2000'],
        ]);

        $loss = TaxLoss::query()->create([
            ...$validated,
            'user_id' => $request->user()->id,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $loss], 201);
    }

    public function openingLots(Request $request): JsonResponse
    {
        return response()->json(['data' => OpeningTaxLot::query()
            ->where('profile_id', \activePortfolio()->id)
            ->with('stock:id,symbol,name')
            ->orderBy('acquired_on')
            ->orderBy('id')
            ->get()]);
    }

    public function storeOpeningLot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stock_id' => ['required', 'integer', Rule::exists('portfolio_stocks', 'id')],
            'acquired_on' => ['required', 'date', 'before_or_equal:today'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'cost_basis' => ['required', 'numeric', 'gte:0'],
            'source' => ['sometimes', Rule::in(['manual', 'broker_statement', 'external_record'])],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $lot = OpeningTaxLot::query()->create([
            ...$validated,
            'profile_id' => \activePortfolio()->id,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $lot->load('stock:id,symbol,name')], 201);
    }
}
