<?php

namespace App\Services;

use App\Models\ForwardCollectionWork;
use Illuminate\Support\Facades\DB;

class ForwardDataHealthService
{
    public function report(): array
    {
        $rows = ForwardCollectionWork::query()->get();
        $grouped = $rows->groupBy('dataset_key');
        $datasets = [];
        foreach ($grouped as $key => $items) {
            $grace = $items->filter(fn ($row) => $row->state === ForwardDataPlanner::STATE_WAITING_PUBLICATION
                && $row->publication_grace_until !== null
                && $row->publication_grace_until->isFuture());
            $unresolved = $items->where('state', '!=', 'succeeded');
            $alertable = $unresolved->reject(fn ($row) => $grace->contains('id', $row->id));
            $state = $items->contains(fn ($row) => in_array($row->state, ['blocked_configuration', 'blocked_quality', 'exhausted'], true))
                ? 'blocked'
                : ($alertable->isNotEmpty() ? 'degraded' : ($unresolved->isNotEmpty() ? 'grace' : 'ready'));
            $datasets[$key] = [
                'state' => $state,
                'scope' => $items->pluck('scope_key')->unique()->values()->all(),
                'latest_expected_session' => $items->max('session_date'),
                'latest_validated_session' => $items->where('state', 'succeeded')->max('session_date'),
                'last_attempted_fetch' => $items->max('last_attempted_at'),
                'last_successful_check' => $items->max('last_successful_at'),
                'coverage' => $items->isEmpty() ? 0.0 : round($items->where('state', 'succeeded')->count() / $items->count() * 100, 4),
                'unresolved' => $unresolved->count(),
                'alertable_unresolved' => $alertable->count(),
                'publication_grace' => $grace->count(),
                'oldest_overdue_work' => $alertable->min('session_date'),
                'reason_codes' => $unresolved->map(fn ($row) => $row->last_error_code ?: ($grace->contains('id', $row->id) ? 'publication_grace' : 'incomplete'))->countBy()->all(),
            ];
        }

        foreach ($this->configurationDatasets() as $key => $dataset) {
            if (($dataset['configuration_valid'] ?? true) === false) {
                $datasets[$key] = array_merge($datasets[$key] ?? [], $dataset);
            } else {
                $datasets[$key] ??= $dataset;
            }
        }

        $alerts = [];
        foreach ($datasets as $key => $dataset) {
            if (($dataset['state'] ?? null) === 'blocked') {
                $alerts[] = [
                    'key' => 'forward_data_configuration',
                    'severity' => 'critical',
                    'title' => 'Forward-data configuration is incomplete',
                    'message' => "{$key} cannot be treated as covered.",
                    'context' => ['dataset' => $key, 'reason_codes' => $dataset['reason_codes'] ?? []],
                ];
            } elseif (($dataset['alertable_unresolved'] ?? 0) > 0 || in_array($dataset['state'] ?? null, ['degraded', 'unknown'], true)) {
                $alerts[] = [
                    'key' => 'forward_data_incomplete',
                    'severity' => 'critical',
                    'title' => 'Forward-data coverage is incomplete',
                    'message' => "{$key} has unresolved work outside publication grace.",
                    'context' => ['dataset' => $key, 'reason_codes' => $dataset['reason_codes'] ?? []],
                ];
            }
        }

        return ['generated_at' => now()->toIso8601String(), 'datasets' => $datasets, 'alerts' => $alerts];
    }

    /** @return array<string, array<string, mixed>> */
    private function configurationDatasets(): array
    {
        $sourceConfigured = app(ForwardDataPlanner::class)->officialSourceConfigured();
        $startConfigured = trim((string) config('forward_data.start_date', '')) !== '';
        $sectorSource = trim((string) config('forward_data.sector_source', ''));
        $corporateFeed = trim((string) config('services.data_quality.corporate_actions_feed_url', ''));

        return [
            ForwardDataPlanner::DATASET_NSE_MEMBERSHIP => $this->configurationDataset(
                $startConfigured && $sourceConfigured,
                $startConfigured && $sourceConfigured ? [] : array_values(array_filter([
                    $startConfigured ? null : 'forward_start_date_not_configured',
                    $sourceConfigured ? null : 'official_nse_source_not_configured',
                ])),
            ),
            'effective_dated_sector' => $this->configurationDataset(
                $sectorSource !== '',
                $sectorSource !== '' ? [] : ['sector_source_not_configured'],
            ),
            'corporate_actions_feed' => $this->configurationDataset(
                $corporateFeed !== '',
                $corporateFeed !== '' ? [] : ['corporate_actions_feed_not_configured'],
            ),
        ];
    }

    /** @param list<string> $reasonCodes */
    private function configurationDataset(bool $configured, array $reasonCodes): array
    {
        return [
            'state' => $configured ? 'unknown' : 'blocked',
            'coverage' => null,
            'unresolved' => $configured ? 0 : 1,
            'alertable_unresolved' => $configured ? 0 : 1,
            'publication_grace' => 0,
            'reason_codes' => $reasonCodes ?: ['no_validated_observation'],
            'configuration_valid' => $configured,
        ];
    }
}
