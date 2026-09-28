<?php

namespace App\Services\Fundamentals;

/**
 * FEAT-054 curated primary-fact and derived-metric catalogue.
 */
class FundamentalMetricCatalog
{
    public function version(): string
    {
        return (string) config('fundamentals_catalog.catalog_version', 'v8-fundamentals-catalog-1');
    }

    /**
     * @return list<string>
     */
    public function primaryFactKeys(): array
    {
        return array_keys((array) config('fundamentals_catalog.primary_facts', []));
    }

    public function primaryFactLabel(string $factKey): string
    {
        $meta = config("fundamentals_catalog.primary_facts.{$factKey}");

        return is_array($meta) ? (string) ($meta['label'] ?? $factKey) : $factKey;
    }

    public function derivedMetricLabel(string $metricId): string
    {
        $meta = config("fundamentals_catalog.derived_metrics.{$metricId}");

        return is_array($meta) ? (string) ($meta['label'] ?? $metricId) : $metricId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function derivedMetric(string $metricId): ?array
    {
        $meta = config("fundamentals_catalog.derived_metrics.{$metricId}");

        return is_array($meta) ? array_merge(['id' => $metricId], $meta) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function derivedMetrics(): array
    {
        $rows = [];
        foreach ((array) config('fundamentals_catalog.derived_metrics', []) as $id => $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $rows[] = array_merge(['id' => (string) $id], $meta);
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public function investorSummaryMetricIds(): array
    {
        return array_values((array) config('fundamentals_catalog.investor_summary_metric_ids', []));
    }

    public function isValuationMetric(string $metricId): bool
    {
        $meta = $this->derivedMetric($metricId);

        return is_array($meta) && ($meta['kind'] ?? '') === 'valuation';
    }

    public function defaultChartFrequency(string $metricId): string
    {
        if ($this->isValuationMetric($metricId)) {
            return (string) config('fundamentals_catalog.chart_defaults.valuation_default_frequency', 'monthly');
        }

        return (string) config('fundamentals_catalog.chart_defaults.flow_default_frequency', 'quarterly');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'catalog_version' => $this->version(),
            'primary_facts' => config('fundamentals_catalog.primary_facts', []),
            'derived_metrics' => $this->derivedMetrics(),
            'investor_summary_metric_ids' => $this->investorSummaryMetricIds(),
            'chart_defaults' => config('fundamentals_catalog.chart_defaults', []),
            'screener_operands' => FundamentalScreenerOperandService::catalogRows(),
        ];
    }
}
