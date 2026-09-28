<?php

namespace App\Services\Fundamentals\AI;

/** FEAT-062 §9 — bound provider output to the investor-safe response contract. */
class FundamentalInsightsResponseValidator
{
    /** @param array<string, mixed> $parsed */
    public function normalize(array $parsed): ?array
    {
        if (! isset($parsed['summary']) || ! is_string($parsed['summary'])) return null;
        $encoded = json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || preg_match('/\b(buy|sell|hold)\b|target[_ -]?price|future[_ -]?price/i', $encoded)) return null;
        $summary = trim($parsed['summary']);
        if ($summary === '' || mb_strlen($summary) > 600) return null;
        $rating = data_get($parsed, 'data_sufficiency.rating', 'medium');
        if (! in_array($rating, ['high', 'medium', 'low'], true)) $rating = 'medium';
        return [
            'summary' => $summary,
            'positive_signals' => $this->items($parsed['positive_signals'] ?? []),
            'risk_signals' => $this->items($parsed['risk_signals'] ?? []),
            'watch_items' => $this->items($parsed['watch_items'] ?? []),
            'follow_up_checks' => $this->checks($parsed['follow_up_checks'] ?? []),
            'data_sufficiency' => [
                'rating' => $rating,
                'missing_information' => $this->checks(data_get($parsed, 'data_sufficiency.missing_information', [])),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function items(mixed $items): array
    {
        if (! is_array($items)) return [];
        $out = [];
        foreach (array_slice($items, 0, 8) as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = ['headline' => mb_substr(trim($item), 0, 300)];
            } elseif (is_array($item) && is_string($item['headline'] ?? null) && trim($item['headline']) !== '') {
                $out[] = [
                    'signal_key' => isset($item['signal_key']) && is_string($item['signal_key']) ? mb_substr($item['signal_key'], 0, 80) : null,
                    'headline' => mb_substr(trim($item['headline']), 0, 300),
                    'metric_values' => is_array($item['metric_values'] ?? null) ? array_slice($item['metric_values'], 0, 12, true) : [],
                ];
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function checks(mixed $items): array
    {
        if (! is_array($items)) return [];
        return array_values(array_filter(array_map(
            fn ($item) => is_string($item) && trim($item) !== '' ? mb_substr(trim($item), 0, 300) : null,
            array_slice($items, 0, 8),
        )));
    }
}
