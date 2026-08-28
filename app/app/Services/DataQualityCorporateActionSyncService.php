<?php

namespace App\Services;

use App\Models\DataQualityIssue;
use App\Models\Stock;
use Illuminate\Support\Facades\Http;

class DataQualityCorporateActionSyncService
{
    public function __construct(
        protected DataQualityIssueService $issues,
    ) {}

    /**
     * @return array{synced:int, created:int, skipped:int, errors:list<string>}
     */
    public function syncFromExchangeFeed(?string $feedUrl = null, ?string $detectionRunId = null): array
    {
        $detectionRunId ??= DataQualityIssueService::newDetectionRunId('portfolio:sync-corporate-actions');
        $url = $feedUrl ?: (string) config('services.data_quality.corporate_actions_feed_url', '');
        if ($url === '') {
            return [
                'synced' => 0,
                'created' => 0,
                'skipped' => 0,
                'errors' => ['Corporate actions feed URL is not configured.'],
            ];
        }

        $response = Http::timeout(45)->get($url);
        if (! $response->ok()) {
            return [
                'synced' => 0,
                'created' => 0,
                'skipped' => 0,
                'errors' => ['Corporate actions feed request failed with status '.$response->status().'.'],
            ];
        }

        $rows = $response->json();
        if (! is_array($rows)) {
            return [
                'synced' => 0,
                'created' => 0,
                'skipped' => 0,
                'errors' => ['Corporate actions feed payload is not a JSON array.'],
            ];
        }

        $created = 0;
        $skipped = 0;
        $errors = [];
        foreach ($rows as $payload) {
            if (! is_array($payload)) {
                $skipped++;
                continue;
            }
            $mapped = $this->mapPayload($payload);
            if ($mapped === null) {
                $skipped++;
                continue;
            }

            $stock = Stock::query()
                ->where('symbol', $mapped['symbol'])
                ->first();

            if ($stock === null) {
                $errors[] = 'Unknown stock symbol in feed: '.$mapped['symbol'];
                continue;
            }

            $this->issues->createOrRefreshPendingIssueForStock(
                $stock,
                DataQualityIssue::TYPE_CORPORATE_ACTION,
                DataQualityIssue::DETECTION_METHOD_EXCHANGE_FEED,
                [
                    'detection_source' => $mapped['source'],
                    'corporate_action_type' => $mapped['action_type'],
                    'suggested_ratio' => $mapped['suggested_ratio'],
                    'latest_suggested_ratio' => $mapped['suggested_ratio'],
                    'ex_date' => $mapped['ex_date'],
                    'record_date' => $mapped['record_date'],
                    'raw_payload' => $payload,
                    'detection_payload' => [
                        'detector' => 'exchange_feed',
                        'action_type' => $mapped['action_type'],
                        'ratio_label' => $mapped['ratio_label'],
                    ],
                    'exchange_match' => true,
                    'confidence' => 1.0,
                    'detected_at' => now(),
                ],
                [
                    [
                        'evidence_key' => 'exchange_ratio',
                        'evidence_label' => 'Exchange ratio',
                        'evidence_value' => $mapped['ratio_label'],
                        'evidence_payload' => $payload,
                        'captured_at' => now(),
                    ],
                ],
                $detectionRunId,
            );
            $created++;
        }

        return [
            'synced' => count($rows),
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
            'detection_run_id' => $detectionRunId,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    protected function mapPayload(array $payload): ?array
    {
        $symbol = strtoupper(trim((string) ($payload['symbol'] ?? $payload['security'] ?? '')));
        $typeRaw = strtolower(trim((string) ($payload['action_type'] ?? $payload['type'] ?? '')));
        $ratioLabel = trim((string) ($payload['ratio'] ?? $payload['ratio_label'] ?? ''));
        $source = (string) ($payload['source'] ?? 'exchange_feed');
        $exDate = $payload['ex_date'] ?? $payload['effective_date'] ?? null;
        $recordDate = $payload['record_date'] ?? null;

        if ($symbol === '' || $typeRaw === '' || $ratioLabel === '' || ! $exDate) {
            return null;
        }

        $normalizedType = match (true) {
            // V4-SPEC-002: rights are not a corporate-action type and must not
            // enter the data-quality CA queue as split/bonus.
            str_contains($typeRaw, 'right') => null,
            str_contains($typeRaw, 'bonus') => 'bonus',
            str_contains($typeRaw, 'face') => 'face_value_split',
            str_contains($typeRaw, 'split') => 'split',
            default => null,
        };
        if ($normalizedType === null) {
            return null;
        }

        [$from, $to] = $this->parseRatio($ratioLabel);
        if ($from <= 0 || $to <= 0) {
            return null;
        }

        return [
            'symbol' => $symbol,
            'action_type' => $normalizedType,
            'suggested_ratio' => round($to / $from, 6),
            'ratio_label' => "{$from}:{$to}",
            'ex_date' => date('Y-m-d', strtotime((string) $exDate)),
            'record_date' => $recordDate ? date('Y-m-d', strtotime((string) $recordDate)) : null,
            'source' => $source,
        ];
    }

    /**
     * @return array{0:float,1:float}
     */
    protected function parseRatio(string $ratio): array
    {
        $clean = preg_replace('/\s+/', '', $ratio) ?? '';
        $parts = preg_split('/[:\/-]/', $clean) ?: [];
        if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            return [0.0, 0.0];
        }

        return [(float) $parts[0], (float) $parts[1]];
    }
}
