<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataQualityIssue;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\BnagrochemF042EvidenceStager;
use App\Services\DataQualityResolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DataQualityController extends Controller
{
    protected DataQualityResolutionService $resolutions;

    public function __construct(
        DataQualityResolutionService $resolutions
    ) {
        $this->resolutions = $resolutions;
    }

    public function dashboard(): JsonResponse
    {
        $base = DataQualityIssue::query();

        return response()->json([
            'data' => [
                'pending_corporate_actions' => (clone $base)
                    ->where('issue_type', DataQualityIssue::TYPE_CORPORATE_ACTION)
                    ->where('issue_status', DataQualityIssue::STATUS_PENDING_REVIEW)
                    ->count(),
                'auto_accepted' => (clone $base)->where('auto_resolved', true)->count(),
                'rejected' => (clone $base)->where('issue_status', DataQualityIssue::STATUS_REJECTED)->count(),
                'accepted' => (clone $base)->where('issue_status', DataQualityIssue::STATUS_ACCEPTED)->count(),
                'total_issues' => (clone $base)->count(),
            ],
        ]);
    }

    public function unresolved(Request $request): JsonResponse
    {
        $issues = DataQualityIssue::query()
            ->with(['stock', 'evidences', 'linkedResolution.resolver'])
            ->where('issue_status', DataQualityIssue::STATUS_PENDING_REVIEW)
            ->when($request->filled('issue_type'), fn ($q) => $q->where('issue_type', (string) $request->input('issue_type')))
            ->orderByDesc('detected_at')
            ->limit(200)
            ->get();

        return response()->json(['data' => $issues]);
    }

    public function history(Request $request): JsonResponse
    {
        $issues = DataQualityIssue::query()
            ->with(['stock', 'resolutions.resolver'])
            ->whereIn('issue_status', [DataQualityIssue::STATUS_ACCEPTED, DataQualityIssue::STATUS_REJECTED])
            ->when($request->filled('issue_type'), fn ($q) => $q->where('issue_type', (string) $request->input('issue_type')))
            ->when($request->filled('resolution_type'), function ($q) use ($request) {
                $type = (string) $request->input('resolution_type');
                $q->whereHas('resolutions', fn ($r) => $r->where('resolution_type', $type));
            })
            ->orderByDesc('resolved_at')
            ->limit(400)
            ->get();

        return response()->json(['data' => $issues]);
    }

    public function show(DataQualityIssue $issue): JsonResponse
    {
        return response()->json([
            'data' => $issue->load(['stock', 'evidences', 'resolutions.resolver']),
        ]);
    }

    /** Read-only estimate of historic OHLCV rows owned by a proposed F042 action. */
    public function previewManualCorporateAction(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'stock_id' => ['required', 'integer', 'exists:portfolio_stocks,id'],
            'corporate_action_type' => ['required', Rule::in(['split', 'bonus'])],
            'ex_date' => ['required', 'date_format:Y-m-d'],
            'applied_ratio' => ['required', 'numeric', 'decimal:0,6', 'gt:0', 'max:10000'],
        ]);
        $stock = Stock::query()->findOrFail($payload['stock_id']);
        $divisor = $payload['corporate_action_type'] === 'bonus'
            ? 1 + (float) $payload['applied_ratio']
            : (float) $payload['applied_ratio'];
        $prices = StockPrice::query()->where('stock_id', $stock->id)
            ->whereDate('price_date', '<', $payload['ex_date'])
            ->orderBy('price_date');
        $digest = $this->manualPriceRowsDigest($stock, $payload['ex_date']);
        $count = $digest['count'];
        $sample = (clone $prices)->limit(10)->get(['price_date', 'open_price', 'high_price', 'low_price', 'close_price', 'volume']);
        $firstDate = $digest['first_date'];
        $lastDate = $digest['last_date'];
        $previewToken = hash_hmac('sha256', implode('|', [$stock->id, $payload['corporate_action_type'], $payload['ex_date'], (string) (float) $payload['applied_ratio'], $digest['rows_sha256'], auth()->id()]), (string) config('app.key'));

        return response()->json(['data' => [
            'stock' => ['id' => $stock->id, 'symbol' => $stock->symbol, 'name' => $stock->name, 'exchange' => $stock->exchange, 'isin' => $stock->isin],
            'corporate_action_type' => $payload['corporate_action_type'],
            'ex_date' => $payload['ex_date'],
            'applied_ratio' => (float) $payload['applied_ratio'],
            'price_divisor' => $divisor,
            'volume_multiplier' => $divisor,
            'affected_price_rows' => $count,
            'first_price_date' => $firstDate,
            'last_price_date' => $lastDate,
            'affected_rows_sha256' => $digest['rows_sha256'],
            'preview_token' => $previewToken,
            'sample_rows' => $sample->map(fn (StockPrice $row) => [
                'price_date' => $row->price_date->toDateString(),
                'close_before' => (float) $row->close_price,
                'close_after' => round((float) $row->close_price / $divisor, 4),
                'volume_before' => (int) $row->volume,
                'volume_after' => (int) round((int) $row->volume * $divisor),
            ])->values(),
            'writes_prices' => false,
        ]]);
    }

    /** Create a human-entered pending issue; approval remains a separate confirmed action. */
    public function createManualCorporateAction(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'stock_id' => ['required', 'integer', 'exists:portfolio_stocks,id'],
            'corporate_action_type' => ['required', Rule::in(['split', 'bonus'])],
            'ex_date' => ['required', 'date_format:Y-m-d'],
            'applied_ratio' => ['required', 'numeric', 'decimal:0,6', 'gt:0', 'max:10000'],
            'record_date' => ['nullable', 'date_format:Y-m-d'],
            'source_reference' => ['nullable', 'string', 'max:500'],
            'justification' => ['nullable', 'string', 'max:2000'],
            'preview_confirmed' => ['required', 'boolean', 'accepted'],
            'preview_token' => ['required', 'string', 'size:64'],
        ]);
        $stock = Stock::query()->findOrFail($payload['stock_id']);
        $digest = $this->manualPriceRowsDigest($stock, $payload['ex_date']);
        $expectedPreview = hash_hmac('sha256', implode('|', [$stock->id, $payload['corporate_action_type'], $payload['ex_date'], (string) (float) $payload['applied_ratio'], $digest['rows_sha256'], auth()->id()]), (string) config('app.key'));
        if (! hash_equals($expectedPreview, $payload['preview_token'])) {
            throw ValidationException::withMessages(['preview_token' => ['Price preview is stale or does not match this action. Preview again before creating the issue.']]);
        }
        try {
        $issue = DB::transaction(function () use ($payload, $stock): ?DataQualityIssue {
            $stock = Stock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
            $lockedDigest = $this->manualPriceRowsDigest($stock, $payload['ex_date']);
            $lockedToken = hash_hmac('sha256', implode('|', [$stock->id, $payload['corporate_action_type'], $payload['ex_date'], (string) (float) $payload['applied_ratio'], $lockedDigest['rows_sha256'], auth()->id()]), (string) config('app.key'));
            if (! hash_equals($lockedToken, $payload['preview_token'])) {
                throw ValidationException::withMessages(['preview_token' => ['Price preview is stale or does not match this action. Preview again before creating the issue.']]);
            }
            if (DataQualityIssue::query()->where('manual_preview_token', $payload['preview_token'])->exists()
                || DataQualityIssue::query()->where('stock_id', $stock->id)
                    ->where('issue_type', DataQualityIssue::TYPE_CORPORATE_ACTION)
                    ->whereDate('ex_date', $payload['ex_date'])
                    ->whereIn('issue_status', [DataQualityIssue::STATUS_PENDING_REVIEW, DataQualityIssue::STATUS_ACCEPTED])
                    ->exists()) return null;

            return DataQualityIssue::query()->create([
            'stock_id' => $stock->id,
            'symbol' => $stock->symbol,
            'issue_type' => DataQualityIssue::TYPE_CORPORATE_ACTION,
            'issue_status' => DataQualityIssue::STATUS_PENDING_REVIEW,
            'detection_method' => DataQualityIssue::DETECTION_METHOD_LEGACY_MANUAL,
            'detection_source' => 'admin_manual_entry',
            'suggested_ratio' => $payload['applied_ratio'],
            'latest_suggested_ratio' => $payload['applied_ratio'],
            'confidence' => null,
            'corporate_action_type' => $payload['corporate_action_type'],
            'ex_date' => $payload['ex_date'],
            'record_date' => $payload['record_date'] ?? null,
            'exchange_match' => false,
            'detection_payload' => ['provenance' => 'admin_manual_entry', 'source_reference' => isset($payload['source_reference']) ? trim($payload['source_reference']) : null, 'justification' => isset($payload['justification']) ? trim($payload['justification']) : null, 'entered_by' => auth()->id(), 'entered_at' => now()->toIso8601String()],
            'raw_payload' => ['origin' => 'authorized_admin_manual_entry', 'evidence_collected_by_stox' => false],
            'detected_at' => now(),
            'auto_resolved' => false,
            'manual_preview_token' => $payload['preview_token'],
        ]);
        });
        } catch (QueryException $exception) {
            if (str_starts_with((string) $exception->getCode(), '23')) {
                return response()->json(['message' => 'This confirmed preview has already created a corporate-action issue.'], 409);
            }
            throw $exception;
        }

        if ($issue === null) {
            return response()->json(['message' => 'A pending or accepted corporate action already exists for this stock and ex-date, or this preview was already used.'], 409);
        }

        return response()->json(['data' => $issue->load(['stock', 'evidences'])], 201);
    }

    /** @return array{count:int,first_date:?string,last_date:?string,rows_sha256:string} */
    private function manualPriceRowsDigest(Stock $stock, string $exDate): array
    {
        $query = StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '<', $exDate)->orderBy('id');
        $hash = hash_init('sha256');
        $count = 0;
        $firstDate = null;
        $lastDate = null;
        $query->chunkById(1000, function ($rows) use ($hash, &$count, &$firstDate, &$lastDate): void {
            foreach ($rows as $row) {
                $date = $row->price_date->toDateString();
                $firstDate ??= $date;
                $lastDate = $date;
                $count++;
                hash_update($hash, json_encode([
                    $row->id, $date, $row->open_price, $row->high_price, $row->low_price,
                    $row->close_price, $row->adjusted_close_price, $row->volume,
                ], JSON_THROW_ON_ERROR));
            }
        });

        return ['count' => $count, 'first_date' => $firstDate, 'last_date' => $lastDate, 'rows_sha256' => hash_final($hash)];
    }

    public function accept(Request $request, DataQualityIssue $issue): JsonResponse
    {
        $reResolve = $request->boolean('re_resolve');
        $manualOverride = $request->boolean('manual_evidence_override');
        $payload = $request->validate([
            'applied_ratio' => ['nullable', 'numeric', 'decimal:0,6', 'gt:0', 'max:10000'],
            'notes' => [
                Rule::requiredIf($reResolve),
                'nullable',
                'string',
                'max:2000',
            ],
            're_resolve' => ['sometimes', 'boolean'],
            'manual_evidence_override' => ['sometimes', 'boolean'],
            'confirm_manual_evidence_override' => [$manualOverride ? 'required' : 'sometimes', 'boolean', 'accepted'],
            'manual_source_reference' => ['nullable', 'string', 'max:500'],
            'manual_justification' => ['nullable', 'string', 'max:2000'],
            'historical_isin' => ['nullable', 'regex:/^INE[A-Z0-9]{9}$/'],
            'historical_valid_from' => ['nullable', 'date', 'before_or_equal:'.($issue->ex_date?->toDateString() ?? 'today')],
        ]);

        $notes = $this->normalizedNotes($payload['notes'] ?? null, $reResolve);

        $updated = $this->resolutions->accept(
            $issue,
            isset($payload['applied_ratio']) ? (float) $payload['applied_ratio'] : null,
            $notes,
            auth()->id(),
            false,
            requirePendingReview: ! $reResolve,
            adminManualOverride: $manualOverride,
            manualOverrideContext: [
                'source_reference' => isset($payload['manual_source_reference']) ? trim($payload['manual_source_reference']) : null,
                'justification' => isset($payload['manual_justification']) ? trim($payload['manual_justification']) : null,
                'confirmed' => $manualOverride,
                'historical_isin' => isset($payload['historical_isin']) ? strtoupper($payload['historical_isin']) : null,
                'historical_valid_from' => $payload['historical_valid_from'] ?? null,
            ],
        );

        return response()->json(['data' => $updated]);
    }

    /** Verify and stage the exact official #383 source artifacts; acceptance remains a separate human action. */
    public function stageBnagrochemF042Evidence(Request $request, DataQualityIssue $issue, BnagrochemF042EvidenceStager $stager): JsonResponse
    {
        $request->validate([
            'listing_circular' => ['required', 'file', 'mimes:pdf', 'max:512'],
            'clearing_circular' => ['required', 'file', 'mimes:pdf', 'max:512'],
            'bhavcopy_archive' => ['required', 'file', 'mimes:zip', 'max:2048'],
        ]);

        $updated = $stager->stage($issue, [
            'listing_circular' => $request->file('listing_circular'),
            'clearing_circular' => $request->file('clearing_circular'),
            'bhavcopy_archive' => $request->file('bhavcopy_archive'),
        ]);

        return response()->json(['data' => $updated]);
    }

    /** Bounded recovery for the retained Nov. 2026 NSE source bundle; creates pending review only. */
    public function discoverBnagrochemHistoricalAction(Request $request, BnagrochemF042EvidenceStager $stager): JsonResponse
    {
        $request->validate([
            'listing_circular' => ['required', 'file', 'mimes:pdf', 'max:512'],
            'clearing_circular' => ['required', 'file', 'mimes:pdf', 'max:512'],
            'bhavcopy_archive' => ['required', 'file', 'mimes:zip', 'max:2048'],
        ]);

        $issue = $stager->discoverBnagrochemHistorical([
            'listing_circular' => $request->file('listing_circular'),
            'clearing_circular' => $request->file('clearing_circular'),
            'bhavcopy_archive' => $request->file('bhavcopy_archive'),
        ]);

        return response()->json(['data' => $issue]);
    }

    public function reject(Request $request, DataQualityIssue $issue): JsonResponse
    {
        $reResolve = $request->boolean('re_resolve');
        $payload = $request->validate([
            'notes' => [
                Rule::requiredIf($reResolve),
                'nullable',
                'string',
                'max:2000',
            ],
            're_resolve' => ['sometimes', 'boolean'],
        ]);

        $notes = $this->normalizedNotes($payload['notes'] ?? null, $reResolve);

        $updated = $this->resolutions->reject(
            $issue,
            $notes,
            auth()->id(),
            requirePendingReview: ! $reResolve,
        );

        return response()->json(['data' => $updated]);
    }

    public function rollbackCompletedRepair(Request $request, DataQualityIssue $issue): JsonResponse
    {
        $payload = $request->validate(['notes' => ['required', 'string', 'min:1', 'max:2000']]);
        $notes = trim($payload['notes']);
        if ($notes === '') {
            throw ValidationException::withMessages(['notes' => ['A non-empty rollback audit note is required.']]);
        }
        return response()->json(['data' => $this->resolutions->rollbackCompletedRepair($issue, $notes, auth()->id())]);
    }

    /**
     * Historical re-resolution requires a non-empty note (F042 policy §4).
     * Pending-queue mutations may omit notes.
     */
    protected function normalizedNotes(mixed $notes, bool $reResolve): ?string
    {
        if (! is_string($notes)) {
            if ($reResolve) {
                throw ValidationException::withMessages([
                    'notes' => ['A non-empty note is required when re-resolving a historical issue.'],
                ]);
            }

            return null;
        }

        $trimmed = trim($notes);
        if ($reResolve && $trimmed === '') {
            throw ValidationException::withMessages([
                'notes' => ['A non-empty note is required when re-resolving a historical issue.'],
            ]);
        }

        return $trimmed === '' ? null : $trimmed;
    }
}
