<?php

namespace App\Services;

use App\Models\DataQualityIssue;
use App\Models\DataQualityIssueEvidence;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DataQualityIssueService
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $evidences
     */
    public function createIssue(array $attributes, array $evidences = [], ?string $detectionRunId = null): DataQualityIssue
    {
        $issue = DataQualityIssue::query()->create(array_merge([
            'issue_status' => DataQualityIssue::STATUS_PENDING_REVIEW,
            'detected_at' => now(),
        ], $attributes));

        $this->attachEvidence(
            $issue,
            $evidences,
            $detectionRunId,
            (string) ($attributes['detection_method'] ?? ''),
        );

        return $issue->fresh(['stock', 'evidences']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $evidences
     */
    public function createOrRefreshPendingIssueForStock(
        Stock $stock,
        string $issueType,
        string $detectionMethod,
        array $attributes,
        array $evidences = [],
        ?string $detectionRunId = null,
    ): DataQualityIssue {
        return DB::transaction(function () use ($stock, $issueType, $detectionMethod, $attributes, $evidences, $detectionRunId) {
            $base = [
                'stock_id' => $stock->id,
                'symbol' => $stock->symbol,
                'issue_type' => $issueType,
                'detection_method' => $detectionMethod,
            ];

            $exDate = $attributes['ex_date'] ?? null;
            $query = DataQualityIssue::query()
                ->where('stock_id', $stock->id)
                ->where('issue_type', $issueType)
                ->where('detection_method', $detectionMethod)
                ->where('issue_status', DataQualityIssue::STATUS_PENDING_REVIEW);

            if ($exDate) {
                $query->whereDate('ex_date', $exDate);
            }

            $existing = $query->lockForUpdate()->first();
            if ($existing !== null) {
                $this->attachEvidence($existing, $evidences, $detectionRunId, $detectionMethod);

                $updates = [];
                if (array_key_exists('latest_suggested_ratio', $attributes)) {
                    $updates['latest_suggested_ratio'] = $attributes['latest_suggested_ratio'];
                }
                if ($updates !== []) {
                    $existing->update($updates);
                }

                return $existing->fresh(['stock', 'evidences']);
            }

            return $this->createIssue(array_merge($base, $attributes), $evidences, $detectionRunId);
        });
    }

    public static function newDetectionRunId(string $command): string
    {
        return $command.':'.Str::uuid()->toString();
    }

    /**
     * @param  list<array<string, mixed>>  $evidences
     */
    public function attachEvidence(
        DataQualityIssue $issue,
        array $evidences,
        ?string $detectionRunId = null,
        ?string $detectionMethod = null,
    ): void {
        foreach ($evidences as $evidence) {
            $payload = is_array($evidence['evidence_payload'] ?? null) ? $evidence['evidence_payload'] : [];
            if ($detectionRunId !== null) {
                $payload['detection_run_id'] = $detectionRunId;
            }
            if ($detectionMethod !== null && $detectionMethod !== '') {
                $payload['detection_method'] = $detectionMethod;
            }

            DataQualityIssueEvidence::query()->create([
                'issue_id' => $issue->id,
                'evidence_key' => (string) ($evidence['evidence_key'] ?? 'detail'),
                'evidence_label' => isset($evidence['evidence_label']) ? (string) $evidence['evidence_label'] : null,
                'evidence_value' => isset($evidence['evidence_value']) ? (string) $evidence['evidence_value'] : null,
                'evidence_payload' => $payload !== [] ? $payload : null,
                'captured_at' => $evidence['captured_at'] ?? now(),
            ]);
        }
    }
}
