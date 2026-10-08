<?php

namespace Tests\Unit\Export;

use App\Models\PortfolioProfile;
use App\Services\Export\ExportDatasetRegistry;
use App\Services\PortfolioCalculationService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ExportDatasetRegistryTest extends TestCase
{
    public function test_catalog_exposes_only_supported_scopes_and_canonical_field_descriptions(): void
    {
        $registry = new ExportDatasetRegistry($this->createMock(PortfolioCalculationService::class));
        $snapshot = collect($registry->catalog())->firstWhere('id', 'portfolio-snapshots');

        $this->assertSame(['current', 'full', 'selected'], $snapshot['scopes']);
        $this->assertSame('stored amount', $snapshot['field_metadata']['portfolio_value']['canonical']);
        $this->assertTrue($registry->supportsScope('portfolio-snapshots', 'current'));
        $this->assertSame(['current', 'full'], collect($registry->catalog())->firstWhere('id', 'portfolio-growth')['scopes']);
    }

    public function test_provider_authorization_rejects_a_profile_owned_by_another_account(): void
    {
        $registry = new ExportDatasetRegistry($this->createMock(PortfolioCalculationService::class));
        $profile = new PortfolioProfile(['user_id' => 91]);

        try {
            $registry->assertAuthorized('portfolio-snapshots', $profile, 92);
            $this->fail('Expected cross-account export authorization to fail.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
