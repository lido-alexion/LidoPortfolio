<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class V5DividendStatementImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_versioned_preview_then_import_deduplicates_statement_rows(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        Stock::query()->create(['symbol' => 'DIV', 'exchange' => 'NSE', 'name' => 'Dividend']);
        $csv = "date,symbol,amount,reference\n2026-01-02,DIV,125.50,broker-1\n";

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->post('/api/tax/dividends/import', [
                'file' => UploadedFile::fake()->createWithContent('dividends.csv', $csv),
                'definition_version' => 'generic-dividend-csv.v1',
                'dry_run' => true,
            ])->assertOk()->assertJsonPath('data.candidate_rows', 1)->assertJsonPath('data.imported', 0);

        $this->post('/api/tax/dividends/import', [
            'file' => UploadedFile::fake()->createWithContent('dividends.csv', $csv),
            'definition_version' => 'generic-dividend-csv.v1',
            'dry_run' => false,
        ])->assertOk()->assertJsonPath('data.imported', 1);
        $this->post('/api/tax/dividends/import', [
            'file' => UploadedFile::fake()->createWithContent('dividends.csv', $csv),
            'definition_version' => 'generic-dividend-csv.v1',
            'dry_run' => false,
        ])->assertOk()->assertJsonPath('data.duplicates', 1)->assertJsonPath('data.imported', 0);

        $this->assertDatabaseCount('portfolio_dividends', 1);
    }

    public function test_invalid_rows_make_import_atomic(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        Stock::query()->create(['symbol' => 'DIV', 'exchange' => 'NSE', 'name' => 'Dividend']);
        $csv = "date,symbol,amount,reference\n2026-01-02,DIV,125.50,ok\n2026-01-03,UNKNOWN,-1,bad\n";

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->post('/api/tax/dividends/import', [
                'file' => UploadedFile::fake()->createWithContent('dividends.csv', $csv),
                'definition_version' => 'generic-dividend-csv.v1',
                'dry_run' => false,
            ])->assertUnprocessable()->assertJsonPath('data.imported', 0);

        $this->assertDatabaseCount('portfolio_dividends', 0);
    }
}
