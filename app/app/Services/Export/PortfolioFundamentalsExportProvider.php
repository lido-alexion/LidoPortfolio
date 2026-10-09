<?php

namespace App\Services\Export;

use App\Models\PortfolioProfile;
use InvalidArgumentException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PortfolioFundamentalsExportProvider implements StreamingExportDatasetProvider
{
    private const DATASET = 'portfolio-fundamental-facts';

    private const COLUMNS = [
        'symbol',
        'exchange',
        'statement_type',
        'cadence',
        'statement_basis',
        'fact_key',
        'fact_label',
        'period_start',
        'period_end',
        'reported_period',
        'value',
        'currency',
        'availability_date',
        'source_provider',
    ];

    public function catalog(): array
    {
        $labels = [
            'symbol' => ['label' => 'Symbol', 'canonical' => 'stock symbol'],
            'exchange' => ['label' => 'Exchange', 'canonical' => 'exchange code'],
            'statement_type' => ['label' => 'Statement', 'canonical' => 'statement type'],
            'cadence' => ['label' => 'Cadence', 'canonical' => 'quarterly or annual'],
            'statement_basis' => ['label' => 'Basis', 'canonical' => 'standalone or consolidated'],
            'fact_key' => ['label' => 'Metric key', 'canonical' => 'fundamental fact key'],
            'fact_label' => ['label' => 'Metric', 'canonical' => 'human-readable fundamental fact label'],
            'period_start' => ['label' => 'Period start', 'canonical' => 'YYYY-MM-DD'],
            'period_end' => ['label' => 'Period end', 'canonical' => 'YYYY-MM-DD'],
            'reported_period' => ['label' => 'Reported period', 'canonical' => 'YYYY-MM-DD'],
            'value' => ['label' => 'Value', 'canonical' => 'stored decimal'],
            'currency' => ['label' => 'Currency', 'canonical' => 'currency code'],
            'availability_date' => ['label' => 'Available as of', 'canonical' => 'YYYY-MM-DD'],
            'source_provider' => ['label' => 'Source provider', 'canonical' => 'provider name'],
        ];

        return [[
            'id' => self::DATASET,
            'label' => 'Fundamental facts for current holdings',
            'fields' => self::COLUMNS,
            'field_metadata' => $labels,
            'scopes' => ['full'],
            'formats' => ['csv', 'xlsx'],
            'estimate' => ['kind' => 'query_count'],
        ]];
    }

    public function resolve(string $dataset, PortfolioProfile $profile, array $filters = []): array
    {
        $this->assertDataset($dataset);
        $facts = $this->query($profile)->orderBy('stocks.symbol')
            ->orderBy('facts.period_end')
            ->orderBy('facts.fact_key')
            ->get();

        return [
            'columns' => self::COLUMNS,
            'rows' => $facts->map(fn ($fact) => $this->row($fact))->all(),
            'identities' => $facts->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'metadata' => $this->metadata($profile),
        ];
    }

    public function stream(string $dataset, PortfolioProfile $profile, array $filters = [], array $selectedIds = []): array
    {
        $this->assertDataset($dataset);
        if ($selectedIds !== []) {
            throw new InvalidArgumentException('Fundamental fact exports support full scope only.');
        }

        $query = $this->query($profile);
        $estimatedRows = (clone $query)->count();
        $rows = $query->orderBy('stocks.symbol')
            ->orderBy('facts.period_end')
            ->orderBy('facts.fact_key')
            ->cursor()
            ->map(fn ($fact) => $this->row($fact));

        return [
            'columns' => self::COLUMNS,
            'rows' => $rows,
            'estimated_rows' => $estimatedRows,
            'metadata' => $this->metadata($profile),
        ];
    }

    public function assertAuthorized(string $dataset, PortfolioProfile $profile, int $userId): void
    {
        $this->assertDataset($dataset);
        abort_unless((int) $profile->user_id === $userId, 403);
    }

    public function supportsScope(string $dataset, string $scope): bool
    {
        return $dataset === self::DATASET && $scope === 'full';
    }

    public function estimate(string $dataset, PortfolioProfile $profile, array $filters = []): array
    {
        $this->assertDataset($dataset);

        return ['rows' => $this->query($profile)->count(), 'exact' => true];
    }

    private function query(PortfolioProfile $profile): Builder
    {
        $heldStockIds = DB::table('portfolio_holdings')
            ->select('stock_id')
            ->where('profile_id', $profile->id)
            ->where('quantity', '>', 0);

        return DB::table('stox_fundamental_facts as facts')
            ->join('portfolio_stocks as stocks', 'stocks.id', '=', 'facts.stock_id')
            ->whereIn('facts.stock_id', $heldStockIds)
            ->where('facts.is_current', true)
            ->whereDate('facts.availability_date', '<=', now()->toDateString())
            ->select([
                'facts.id',
                'stocks.symbol',
                'stocks.exchange',
                'facts.statement_type',
                'facts.cadence',
                'facts.statement_basis',
                'facts.fact_key',
                'facts.period_start',
                'facts.period_end',
                'facts.reported_period',
                'facts.value',
                'facts.currency',
                'facts.availability_date',
                'facts.provider',
            ]);
    }

    private function row(object $fact): array
    {
        return [
            'symbol' => $fact->symbol,
            'exchange' => $fact->exchange,
            'statement_type' => $fact->statement_type,
            'cadence' => $fact->cadence,
            'statement_basis' => $fact->statement_basis,
            'fact_key' => $fact->fact_key,
            'fact_label' => \Illuminate\Support\Str::headline($fact->fact_key),
            'period_start' => $fact->period_start,
            'period_end' => $fact->period_end,
            'reported_period' => $fact->reported_period,
            'value' => $fact->value,
            'currency' => $fact->currency,
            'availability_date' => $fact->availability_date,
            'source_provider' => $fact->provider,
        ];
    }

    private function metadata(PortfolioProfile $profile): array
    {
        return [
            'dataset' => self::DATASET,
            'profile_id' => $profile->id,
            'source' => 'current point-in-time fundamental fact revisions',
            'as_of_date' => now()->toDateString(),
            'field_labels' => array_map(fn (array $field) => $field['label'], $this->fieldMetadata()),
        ];
    }

    private function fieldMetadata(): array
    {
        $definition = $this->catalog()[0];

        return $definition['field_metadata'];
    }

    private function assertDataset(string $dataset): void
    {
        if ($dataset !== self::DATASET) {
            throw new \InvalidArgumentException('This dataset is not available from this provider.');
        }
    }
}
