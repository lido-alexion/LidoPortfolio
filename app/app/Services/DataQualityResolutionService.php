<?php

namespace App\Services;

use App\Models\DataQualityIssue;
use App\Models\DataQualityIssueResolution;
use Illuminate\Support\Facades\DB;

class DataQualityResolutionService
{
    public function __construct(
        protected DataQualityAdjustmentFactorService $adjustments,
    ) {}

    public function accept(DataQualityIssue $issue, ?float $ratio = null, ?string $notes = null, ?int $userId = null, bool $auto = false): DataQualityIssue
    {
        $appliedRatio = $ratio ?? (float) ($issue->suggested_ratio ?? 0);
        if ($appliedRatio <= 0) {
            throw new \InvalidArgumentException('Applied ratio must be greater than zero.');
        }

        return DB::transaction(function () use ($issue, $appliedRatio, $notes, $userId, $auto) {
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

    public function reject(DataQualityIssue $issue, ?string $notes = null, ?int $userId = null): DataQualityIssue
    {
        return DB::transaction(function () use ($issue, $notes, $userId) {
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

    public function autoAcceptStaleIssues(int $pendingDays = 15): int
    {
        $cutoff = now()->subDays($pendingDays);
        $issues = DataQualityIssue::query()
            ->where('issue_status', DataQualityIssue::STATUS_PENDING_REVIEW)
            ->where('detected_at', '<=', $cutoff)
            ->get();

        $count = 0;
        foreach ($issues as $issue) {
            $this->accept($issue, null, 'Auto accepted after pending window elapsed.', null, true);
            $count++;
        }

        return $count;
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
