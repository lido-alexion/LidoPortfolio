<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V5TaxCsvExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_tax_exports_are_private_versioned_and_authorized(): void
    {
        $this->getJson('/api/tax/exports/summary?financial_year=2025-26')->assertUnauthorized();

        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $response = $this->actingAs($user)->withProfileHeader($user, $profile)
            ->get('/api/tax/exports/summary?financial_year=2025-26');

        $response->assertOk();
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString('schema_version,stox.tax-export.v1', $csv);
        $this->assertStringContainsString('dataset,summary', $csv);
        $this->assertStringContainsString('request_cutoff,', $csv);
        $this->assertStringContainsString('estimated_tax', $csv);
    }

    public function test_all_tax_datasets_are_available(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);

        foreach (['realized_gains', 'open_lots', 'dividends', 'losses', 'summary', 'assumptions'] as $dataset) {
            $response = $this->actingAs($user)->withProfileHeader($user, $profile)
                ->get('/api/tax/exports/'.$dataset.'?financial_year=2025-26');
            $response->assertOk();
            $this->assertStringContainsString('dataset,'.$dataset, $response->streamedContent());
        }
    }
}
