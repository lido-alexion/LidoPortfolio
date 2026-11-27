<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataQualityIssue;
use App\Services\BnagrochemF042EvidenceStager;
use App\Services\DataQualityResolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    public function accept(Request $request, DataQualityIssue $issue): JsonResponse
    {
        $reResolve = $request->boolean('re_resolve');
        $payload = $request->validate([
            'applied_ratio' => ['nullable', 'numeric', 'gt:0'],
            'notes' => [
                Rule::requiredIf($reResolve),
                'nullable',
                'string',
                'max:2000',
            ],
            're_resolve' => ['sometimes', 'boolean'],
        ]);

        $notes = $this->normalizedNotes($payload['notes'] ?? null, $reResolve);

        $updated = $this->resolutions->accept(
            $issue,
            isset($payload['applied_ratio']) ? (float) $payload['applied_ratio'] : null,
            $notes,
            auth()->id(),
            false,
            requirePendingReview: ! $reResolve,
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
