<?php

namespace App\Services;

use App\Models\DataQualityIssue;
use App\Models\DataQualityIssueEvidence;
use App\Models\Stock;

class DataQualityIssueService
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $evidences
     */
    public function createIssue(array $attributes, array $evidences = []): DataQualityIssue
    {
        $issue = DataQualityIssue::query()->create(array_merge([
            'issue_status' => DataQualityIssue::STATUS_PENDING_REVIEW,
            'detected_at' => now(),
        ], $attributes));

        $this->attachEvidence($issue, $evidences);

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
    ): DataQualityIssue {
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

        $existing = $query->first();
        if ($existing !== null) {
            return $existing;
        }

        return $this->createIssue(array_merge($base, $attributes), $evidences);
    }

    /**
     * @param  list<array<string, mixed>>  $evidences
     */
    public function attachEvidence(DataQualityIssue $issue, array $evidences): void
    {
        foreach ($evidences as $evidence) {
            DataQualityIssueEvidence::query()->create([
                'issue_id' => $issue->id,
                'evidence_key' => (string) ($evidence['evidence_key'] ?? 'detail'),
                'evidence_label' => isset($evidence['evidence_label']) ? (string) $evidence['evidence_label'] : null,
                'evidence_value' => isset($evidence['evidence_value']) ? (string) $evidence['evidence_value'] : null,
                'evidence_payload' => is_array($evidence['evidence_payload'] ?? null) ? $evidence['evidence_payload'] : null,
                'captured_at' => $evidence['captured_at'] ?? now(),
            ]);
        }
    }
}
