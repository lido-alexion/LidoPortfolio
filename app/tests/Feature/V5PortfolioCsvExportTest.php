<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CashManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V5PortfolioCsvExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_statement_export_is_private_versioned_and_preserves_audit_fields(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        app(CashManagementService::class)->deposit($profile, 1000, 'Seed, with comma', $user, '2026-08-01');

        $response = $this->actingAs($user)->withProfileHeader($user, $profile)
            ->get('/api/portfolio/exports/cash_statement?from=2026-08-01&to=2026-08-02');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString('schema_version,stox.portfolio-export.v1', $csv);
        $this->assertStringContainsString('request_cutoff,', $csv);
        $this->assertStringContainsString('entry_date,created_at', $csv);
        $this->assertStringContainsString('"Seed, with comma"', $csv);
    }

    public function test_export_requires_authentication_and_dataset_specific_dates(): void
    {
        $this->getJson('/api/portfolio/exports/cash_statement')->assertUnauthorized();

        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/portfolio/exports/historical_holdings')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('as_of');
    }
}
