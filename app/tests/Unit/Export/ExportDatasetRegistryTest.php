<?php

namespace Tests\Unit\Export;

use App\Models\PortfolioProfile;
use App\Services\Export\ExportDatasetProvider;
use App\Services\Export\ExportDatasetRegistry;
use App\Services\Export\PortfolioSnapshotExportProvider;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ExportDatasetRegistryTest extends TestCase
{
    public function test_catalog_exposes_only_supported_scopes_and_canonical_field_descriptions(): void
    {
        $registry = app(ExportDatasetRegistry::class);
        $snapshot = collect($registry->catalog())->firstWhere('id', 'portfolio-snapshots');

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
