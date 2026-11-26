<?php

namespace App\Services;

use App\Models\DataQualityIssue;
use App\Models\DataQualityIssueResolution;
use App\Exceptions\DomainException;
use App\Models\Stock;
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
            $this->lockIssueStock($issue);
            $this->assertNoCompletedPriceRepair($issue);

            if ($requirePendingReview && $issue->issue_status !== DataQualityIssue::STATUS_PENDING_REVIEW) {
                throw new DomainException(
                    'Issue is no longer pending review.',
                    'DATA_QUALITY_STALE_RESOLUTION',
                    409,
                );
            }

            app(BnagrochemF042EvidenceValidator::class)->assertReviewedEvidence($issue->loadMissing(['stock', 'evidences']), $appliedRatio, $auto);

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
            $this->lockIssueStock($issue);
            $this->assertNoCompletedPriceRepair($issue);

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

    /** Exact F042 price restoration and linked issue reversal in one transaction. */
    public function rollbackCompletedRepair(DataQualityIssue $issue, string $notes, ?int $userId): DataQualityIssue
    {
        return DB::transaction(function () use ($issue, $notes, $userId) {
            $issue = DataQualityIssue::query()->lockForUpdate()->findOrFail($issue->id);
            $stock = Stock::query()->whereKey($issue->stock_id)->lockForUpdate()->firstOrFail();
            $factors = \App\Models\PriceAdjustmentFactor::query()->where('issue_id', $issue->id)->orderBy('id')->lockForUpdate()->get();
            $completed = $factors->filter(fn ($factor) => (($factor->metadata ?? [])['ohlcv_repair_status'] ?? null) === \App\Models\PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED)->values();
            if ($completed->isEmpty()) {
                if ($factors->contains(fn ($f) => (($f->metadata ?? [])['ohlcv_repair_status'] ?? null) === \App\Models\PriceAdjustmentFactor::REPAIR_STATUS_ROLLED_BACK)) return $issue->fresh(['stock', 'evidences', 'resolutions.resolver']);
                throw new DomainException('No completed repair is available to roll back.', 'DATA_QUALITY_PRICE_REPAIR_ROLLBACK_REQUIRED', 409);
            }
            if ($completed->count() !== 1) throw new DomainException('Multiple completed factors overlap this issue.', 'DATA_QUALITY_PRICE_REPAIR_ROLLBACK_REQUIRED', 409);
            $factor = $completed->first();
            // Any later event may have rewritten rows in this factor's historical
            // interval. Same-date checks alone miss composed adjustments.
            $snapMinDate = \Illuminate\Support\Facades\DB::table('portfolio_price_adjustment_repair_snapshots as s')
                ->join('portfolio_stock_prices as p', 'p.id', '=', 's.stock_price_id')
                ->where('s.factor_id', $factor->id)->min('p.price_date');
            $overlap = \App\Models\PriceAdjustmentFactor::query()->where('stock_id', $stock->id)->where('id', '!=', $factor->id)
                ->where('metadata->ohlcv_repair_status', \App\Models\PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED)
                ->whereDate('effective_ex_date', '>=', $snapMinDate)->exists();
            $actionOverlap = \App\Models\CorporateAction::query()->where('stock_id', $stock->id)->whereNotNull('applied_at')
                ->whereDate('ex_date', '>=', $snapMinDate)->exists();
            if ($overlap || $actionOverlap) throw new DomainException('Overlapping factor or corporate-action event prevents exact rollback.', 'DATA_QUALITY_PRICE_REPAIR_ROLLBACK_REQUIRED', 409);
            $restored = app(CorporateActionPriceRepairService::class)->restoreCompletedFactor($factor);
            $priorResolution = $issue->latest_resolution_id;
            $resolution = DataQualityIssueResolution::query()->create([
                'issue_id' => $issue->id, 'resolution_type' => DataQualityIssueResolution::TYPE_REJECTED,
                'resolution_status' => DataQualityIssue::STATUS_REJECTED, 'suggested_ratio_snapshot' => $issue->suggested_ratio,
                'is_reversal' => $priorResolution !== null, 'supersedes_resolution_id' => $priorResolution,
                'resolved_by' => $userId, 'notes' => $notes, 'resolved_at' => now(),
                'metadata' => ['previous_status' => $issue->issue_status, 'price_repair_rollback' => $restored, 'factor_id' => $factor->id],
            ]);
            $metadata = $factor->metadata ?? [];
            $metadata['ohlcv_repair_status'] = \App\Models\PriceAdjustmentFactor::REPAIR_STATUS_ROLLED_BACK;
            $metadata['ohlcv_rollback'] = ['resolution_id' => $resolution->id, 'rolled_back_at' => now()->toIso8601String(), 'resolved_by' => $userId, 'rows_restored' => $restored['rows_restored']];
            $factor->update(['metadata' => $metadata, 'is_active' => false, 'reversed_at' => now()]);
            $issue->update(['issue_status' => DataQualityIssue::STATUS_REJECTED, 'resolved_at' => now(), 'applied_ratio' => null, 'latest_resolution_id' => $resolution->id]);
            app(MetricsUpdateService::class)->updateStock($stock);
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

        // The #383 BNAGROCHEM factor requires a named human review of its exact source bundle.
        if (app(BnagrochemF042EvidenceValidator::class)->appliesTo($issue)) {
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

    /** Serialize factor activation/deactivation with stock-global F020/F042 OHLCV writers. */
    private function lockIssueStock(DataQualityIssue $issue): void
    {
        Stock::query()->whereKey($issue->stock_id)->lockForUpdate()->first();
    }

    /**
     * A metadata-only reversal cannot restore OHLCV already rewritten by F043.
     * Lock factors after stock (the writer lock order) so an accept/reject cannot
     * race a repair that is committing its completed marker.
     */
    private function assertNoCompletedPriceRepair(DataQualityIssue $issue): void
    {
        $completed = \App\Models\PriceAdjustmentFactor::query()
            ->where('issue_id', $issue->id)
            ->where('metadata->ohlcv_repair_status', \App\Models\PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED)
            ->lockForUpdate()
            ->exists();

        if ($completed) {
            throw new DomainException(
                'Completed OHLCV repair cannot be re-resolved until its exact price snapshot has been restored and verified.',
                'DATA_QUALITY_PRICE_REPAIR_ROLLBACK_REQUIRED',
                409,
            );
        }
    }
}
