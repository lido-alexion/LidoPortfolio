<?php

namespace Tests\Unit\Export;

use App\Models\PortfolioProfile;
use App\Services\Export\ExportDatasetProvider;
use App\Services\Export\ExportDatasetRegistry;
use App\Services\Export\PortfolioSnapshotExportProvider;
use App\Services\Export\PortfolioAnalyticsExportProvider;
use App\Services\Analytics\PortfolioAnalyticsService;
use App\Services\Export\DashboardSummaryExportProvider;
use App\Services\PortfolioCalculationService;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ExportDatasetRegistryTest extends TestCase
{
    public function test_catalog_exposes_only_supported_scopes_and_canonical_field_descriptions(): void
    {
        $registry = app(ExportDatasetRegistry::class);
        $snapshot = collect($registry->catalog())->firstWhere('id', 'portfolio-snapshots');
        $datasetIds = array_column($registry->catalog(), 'id');
        $this->assertContains('portfolio-analytics', $datasetIds);
        $this->assertContains('portfolio-fundamental-facts', $datasetIds);

        $this->assertSame(['current', 'full', 'selected'], $snapshot['scopes']);
        $this->assertSame('stored amount', $snapshot['field_metadata']['portfolio_value']['canonical']);
        $this->assertTrue($registry->supportsScope('portfolio-snapshots', 'current'));
        $this->assertSame(['current', 'full'], collect($registry->catalog())->firstWhere('id', 'portfolio-growth')['scopes']);
    }

    public function test_registry_accepts_an_independently_implemented_dataset_provider(): void
    {
        $provider = new class implements ExportDatasetProvider {
            public function catalog(): array
            {
                return [['id' => 'independent-test', 'label' => 'Independent test', 'fields' => ['value'], 'field_metadata' => [], 'scopes' => ['full'], 'formats' => ['csv']]];
            }

            public function resolve(string $dataset, PortfolioProfile $profile, array $filters = []): array
            {
                return ['columns' => ['value'], 'rows' => [['value' => 42]], 'identities' => ['row-1'], 'metadata' => []];
            }

            public function assertAuthorized(string $dataset, PortfolioProfile $profile, int $userId): void {}

            public function supportsScope(string $dataset, string $scope): bool
            {
                return $dataset === 'independent-test' && $scope === 'full';
            }

            public function estimate(string $dataset, PortfolioProfile $profile, array $filters = []): array
            {
                return ['rows' => 1, 'exact' => true];
            }
        };
        $registry = new ExportDatasetRegistry([$provider]);

        $this->assertSame(['independent-test'], array_column($registry->catalog(), 'id'));
        $this->assertSame([['value' => 42]], $registry->resolve('independent-test', new PortfolioProfile)['rows']);
        $this->assertSame(['rows' => 1, 'exact' => true], $registry->estimate('independent-test', new PortfolioProfile));
        $this->assertTrue($registry->supportsScope('independent-test', 'full'));
    }

    public function test_registry_rejects_duplicate_dataset_ids_across_providers(): void
    {
        $provider = app(PortfolioSnapshotExportProvider::class);

        try {
            new ExportDatasetRegistry([$provider, $provider]);
            $this->fail('Expected duplicate dataset IDs to be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('present and unique', $exception->getMessage());
        }
    }

    public function test_portfolio_analytics_provider_exports_only_allowlisted_metrics(): void
    {
        $profile = new PortfolioProfile(['id' => 71, 'user_id' => 8]);
        $service = $this->createMock(PortfolioAnalyticsService::class);
        $service->expects($this->once())
            ->method('forProfile')
            ->with($profile, false)
            ->willReturn([
                'portfolio_value' => 123.45,
                'number_of_positions' => 3,
                'allocation' => [['symbol' => 'SECRET', 'market_value' => 42]],
                'market_context' => ['market_phase' => 'private-detail'],
                'computed_at' => '2026-10-08T00:00:00Z',
            ]);

        $provider = new PortfolioAnalyticsExportProvider($service);
        $resolved = $provider->resolve('portfolio-analytics', $profile);

        $this->assertSame(['metric_key', 'metric', 'value'], $resolved['columns']);
        $this->assertSame([
            ['metric_key' => 'portfolio_value', 'metric' => 'Portfolio value', 'value' => 123.45],
            ['metric_key' => 'number_of_positions', 'metric' => 'Number of positions', 'value' => 3],
        ], $resolved['rows']);
        $this->assertSame(['portfolio_value', 'number_of_positions'], $resolved['identities']);
        $this->assertSame(['full'], $provider->catalog()[0]['scopes']);
    }

    public function test_dashboard_summary_keeps_metric_keys_and_exports_readable_labels(): void
    {
        $profile = new PortfolioProfile(['id' => 72, 'user_id' => 8]);
        $service = $this->createMock(PortfolioCalculationService::class);
        $service->expects($this->once())->method('calculateForProfile')->with($profile)->willReturn([
            'portfolio_value' => 150.0,
            'invested_value' => 100.0,
            'total_gain_loss' => 50.0,
            'unrelated_private_value' => 999,
        ]);

        $resolved = (new DashboardSummaryExportProvider($service))->resolve('dashboard-summary', $profile);

        $this->assertSame(['field_key', 'field', 'value'], $resolved['columns']);
        $this->assertSame([
            ['field_key' => 'portfolio_value', 'field' => 'Portfolio value', 'value' => 150.0],
            ['field_key' => 'invested_value', 'field' => 'Invested value', 'value' => 100.0],
            ['field_key' => 'total_gain_loss', 'field' => 'Total gain/loss', 'value' => 50.0],
        ], $resolved['rows']);
        $this->assertSame(['portfolio_value', 'invested_value', 'total_gain_loss'], $resolved['identities']);
    }

    public function test_provider_authorization_rejects_a_profile_owned_by_another_account(): void
    {
        $registry = app(ExportDatasetRegistry::class);
        $profile = new PortfolioProfile(['user_id' => 91]);

        try {
            $registry->assertAuthorized('portfolio-snapshots', $profile, 92);
            $this->fail('Expected cross-account export authorization to fail.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
