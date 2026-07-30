<?php

namespace App\Services;

use App\Models\CorporateAction;
use App\Models\DataQualityIssue;
use App\Models\DataQualityIssueResolution;
use Illuminate\Support\Facades\DB;

class DataQualityLegacyCorporateActionMigrationService
{
    public function migrateAppliedActions(bool $dryRun = true): array
    {
        $actions = CorporateAction::query()
            ->with('stock')
            ->whereNotNull('applied_at')
            ->orderBy('id')
            ->get();

        $migrated = 0;
        $skipped = 0;
        foreach ($actions as $action) {
            $existing = DataQualityIssue::query()
                ->where('stock_id', $action->stock_id)
                ->where('issue_type', DataQualityIssue::TYPE_CORPORATE_ACTION)
                ->where('detection_method', 'legacy_manual')
                ->whereDate('ex_date', $action->ex_date->toDateString())
                ->first();
            if ($existing) {
                $skipped++;
                continue;
            }
            if ($dryRun) {
                $migrated++;
                continue;
            }

            DB::transaction(function () use ($action) {
                $ratio = round(((float) $action->ratio_to) / max(1.0, (float) $action->ratio_from), 6);
                $issue = DataQualityIssue::query()->create([
                    'stock_id' => $action->stock_id,
                    'symbol' => $action->stock?->symbol,
                    'issue_type' => DataQualityIssue::TYPE_CORPORATE_ACTION,
                    'issue_status' => DataQualityIssue::STATUS_ACCEPTED,
                    'detection_method' => 'legacy_manual',
                    'detection_source' => 'legacy_manual',
                    'suggested_ratio' => $ratio,
                    'latest_suggested_ratio' => $ratio,
                    'confidence' => 1.0,
                    'corporate_action_type' => $action->action_type,
                    'ex_date' => $action->ex_date->toDateString(),
                    'detection_payload' => [
                        'legacy_corporate_action_id' => $action->id,
                    ],
                    'raw_payload' => $action->toArray(),
                    'detected_at' => $action->created_at ?? now(),
                    'resolved_at' => $action->applied_at ?? now(),
                    'auto_resolved' => false,
                    'applied_ratio' => $ratio,
                ]);

                $resolution = DataQualityIssueResolution::query()->create([
                    'issue_id' => $issue->id,
                    'resolution_type' => DataQualityIssueResolution::TYPE_MIGRATED,
                    'resolution_status' => DataQualityIssue::STATUS_ACCEPTED,
                    'applied_ratio' => $ratio,
                    'suggested_ratio_snapshot' => $ratio,
                    'is_reversal' => false,
                    'resolved_by' => $action->created_by,
                    'notes' => 'Migrated from legacy manual corporate action workflow.',
                    'metadata' => [
                        'legacy_corporate_action_id' => $action->id,
                    ],
                    'resolved_at' => $action->applied_at ?? now(),
                ]);

                $issue->update(['latest_resolution_id' => $resolution->id]);
            });
            $migrated++;
        }

        return [
            'scanned' => $actions->count(),
            'migrated' => $migrated,
            'skipped' => $skipped,
            'dry_run' => $dryRun,
        ];
    }
}
