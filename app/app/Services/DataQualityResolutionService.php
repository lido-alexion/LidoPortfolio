<?php

namespace App\Services;

use App\Models\DataQualityIssue;
use App\Models\DataQualityIssueResolution;
use App\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

class DataQualityResolutionService
{
    public function __construct(
        protected DataQualityAdjustmentFactorService $adjustments,
    ) {}

    public function accept(
        DataQualityIssue $issue,
        ?float $ratio = null,
        ?string $notes = null,
        ?int $userId = null,
        bool $auto = false,
        bool $requirePendingReview = true,
    ): DataQualityIssue {
        $appliedRatio = $ratio ?? (float) ($issue->suggested_ratio ?? 0);
        if ($appliedRatio <= 0) {
            throw new \InvalidArgumentException('Applied ratio must be greater than zero.');
        }

        return DB::transaction(function () use ($issue, $appliedRatio, $notes, $userId, $auto, $requirePendingReview) {
            $issue = DataQualityIssue::query()->lockForUpdate()->findOrFail($issue->id);

            if ($requirePendingReview && $issue->issue_status !== DataQualityIssue::STATUS_PENDING_REVIEW) {
                throw new DomainException(
                    'Issue is no longer pending review.',
                    'DATA_QUALITY_STALE_RESOLUTION',
                    409,
                );
            }

            $isReversal = $issue->latest_resolution_id !== null && $issue->issue_status !== DataQualityIssue::STATUS_PENDING_REVIEW;
            $resolutionType = $auto
                ? DataQualityIssueResolution::TYPE_AUTO_ACCEPTED
                : ($this->isSuggestedRatio($issue, $appliedRatio)
                    ? DataQualityIssueResolution::TYPE_ACCEPTED
                    : DataQualityIssueResolution::TYPE_MODIFIED_ACCEPTED);

            $resolution = DataQualityIssueResolution::query()->create([
                'issue_id' => $issue->id,
                'resolution_type' => $resolutionType,
                'resolution_status' => DataQualityIssue::STATUS_ACCEPTED,
                'applied_ratio' => $appliedRatio,
                'suggested_ratio_snapshot' => $issue->suggested_ratio,
                'is_reversal' => $isReversal,
                'supersedes_resolution_id' => $issue->latest_resolution_id,
                'resolved_by' => $userId,
                'notes' => $notes,
                'metadata' => [
                    'auto_resolved' => $auto,
                    'previous_status' => $issue->issue_status,
                ],
                'resolved_at' => now(),
            ]);

            $this->adjustments->deactivateForIssue($issue);
            $this->adjustments->applyCorporateActionFactor($issue->fresh('stock'), $appliedRatio);

            $issue->update([
                'issue_status' => DataQualityIssue::STATUS_ACCEPTED,
                'resolved_at' => now(),
                'auto_resolved' => $auto,
                'applied_ratio' => $appliedRatio,
                'latest_resolution_id' => $resolution->id,
            ]);

            return $issue->fresh(['stock', 'evidences', 'resolutions.resolver']);
        });
    }

    public function reject(
        DataQualityIssue $issue,
        ?string $notes = null,
        ?int $userId = null,
        bool $requirePendingReview = true,
    ): DataQualityIssue {
        return DB::transaction(function () use ($issue, $notes, $userId, $requirePendingReview) {
            $issue = DataQualityIssue::query()->lockForUpdate()->findOrFail($issue->id);

            if ($requirePendingReview && $issue->issue_status !== DataQualityIssue::STATUS_PENDING_REVIEW) {
                throw new DomainException(
                    'Issue is no longer pending review.',
                    'DATA_QUALITY_STALE_RESOLUTION',
                    409,
                );
            }

            $isReversal = $issue->latest_resolution_id !== null && $issue->issue_status !== DataQualityIssue::STATUS_PENDING_REVIEW;
            $resolution = DataQualityIssueResolution::query()->create([
                'issue_id' => $issue->id,
                'resolution_type' => DataQualityIssueResolution::TYPE_REJECTED,
                'resolution_status' => DataQualityIssue::STATUS_REJECTED,
                'applied_ratio' => null,
                'suggested_ratio_snapshot' => $issue->suggested_ratio,
                'is_reversal' => $isReversal,
                'supersedes_resolution_id' => $issue->latest_resolution_id,
                'resolved_by' => $userId,
                'notes' => $notes,
                'metadata' => [
                    'previous_status' => $issue->issue_status,
                ],
                'resolved_at' => now(),
            ]);

            $this->adjustments->deactivateForIssue($issue);

            $issue->update([
                'issue_status' => DataQualityIssue::STATUS_REJECTED,
                'resolved_at' => now(),
                'applied_ratio' => null,
                'latest_resolution_id' => $resolution->id,
            ]);

            return $issue->fresh(['stock', 'evidences', 'resolutions.resolver']);
        });
    }

    public function autoAcceptStaleIssues(?int $pendingDays = null): int
    {
        $thresholdDays = max(1, $pendingDays ?? (int) config('services.data_quality.auto_accept_days', 15));
        $cutoff = now()->subDays($thresholdDays);

        $issues = DataQualityIssue::query()
            ->where('issue_status', DataQualityIssue::STATUS_PENDING_REVIEW)
            ->where('detected_at', '<=', $cutoff)
            ->where('detection_method', DataQualityIssue::DETECTION_METHOD_EXCHANGE_FEED)
            ->where('exchange_match', true)
            ->whereNotNull('suggested_ratio')
            ->where('suggested_ratio', '>', 0)
            ->where(function ($query) {
                $query->whereNull('confidence')
                    ->orWhere('confidence', '>=', 1.0);
            })
            ->get();

        $count = 0;
        foreach ($issues as $issue) {
            if (! $this->isEligibleForAutoAccept($issue, $thresholdDays)) {
                continue;
            }

            $notes = sprintf(
                'Auto accepted after %d day pending window (detection_method=%s, exchange_match=true, applied_ratio=%s). Policy threshold=%d days.',
                $thresholdDays,
                $issue->detection_method,
                number_format((float) $issue->suggested_ratio, 6, '.', ''),
                $thresholdDays,
            );

            $accepted = $this->accept($issue, null, $notes, null, true, requirePendingReview: true);
            $latest = $accepted->resolutions()->latest('resolved_at')->first();
            if ($latest !== null) {
                $metadata = is_array($latest->metadata) ? $latest->metadata : [];
                $latest->update([
                    'metadata' => array_merge($metadata, [
                        'auto_accept_policy' => [
                            'threshold_days' => $thresholdDays,
                            'detection_method' => $issue->detection_method,
                            'exchange_match' => (bool) $issue->exchange_match,
                            'confidence' => $issue->confidence,
                        ],
                    ]),
                ]);
            }
            $count++;
        }

        return $count;
    }

    protected function isEligibleForAutoAccept(DataQualityIssue $issue, int $thresholdDays): bool
    {
        if ($issue->issue_status !== DataQualityIssue::STATUS_PENDING_REVIEW) {
            return false;
        }

        if ($issue->detection_method !== DataQualityIssue::DETECTION_METHOD_EXCHANGE_FEED) {
            return false;
        }

        if (! $issue->exchange_match) {
            return false;
        }

        $ratio = (float) ($issue->suggested_ratio ?? 0);
        if ($ratio <= 0) {
            return false;
        }

        if ($issue->confidence !== null && (float) $issue->confidence < 1.0) {
            return false;
        }

        if ($issue->detected_at === null || $issue->detected_at->gt(now()->subDays($thresholdDays))) {
            return false;
        }

        return true;
    }

    protected function isSuggestedRatio(DataQualityIssue $issue, float $appliedRatio): bool
    {
        $suggested = (float) ($issue->suggested_ratio ?? 0);
        if ($suggested <= 0) {
            return false;
        }

        return abs($suggested - $appliedRatio) < 0.000001;
    }
}
