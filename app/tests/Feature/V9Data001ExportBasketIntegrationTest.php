<?php

namespace Tests\Feature;

use App\Models\ExportArtifact;
use App\Models\PortfolioSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class V9Data001ExportBasketIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_basket_endpoint_creates_one_sheet_per_item_with_item_metadata_and_safe_unique_names(): void
    {
        if (! class_exists(\ZipArchive::class)) $this->markTestSkipped('ZipArchive is unavailable.');
        Storage::fake('local');
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        PortfolioSnapshot::query()->create([
            'profile_id' => $profile->id,
            'snapshot_date' => '2026-10-07',
            'portfolio_value' => '12345.6700',
            'invested_value' => '10000.0000',
        ]);
        $this->actingAs($owner)->putJson('/api/exports/basket', [
            'items' => [
                ['dataset' => 'portfolio-snapshots', 'scope' => 'full', 'fields' => ['snapshot_date', 'portfolio_value'], 'sheet_name' => 'Daily/values'],
                ['dataset' => 'portfolio-growth', 'scope' => 'full', 'fields' => ['snapshot_date', 'invested_value'], 'sheet_name' => 'Daily/values'],
            ],
        ])->assertOk();

        $response = $this->actingAs($owner)->withProfileHeader($owner, $profile)->postJson('/api/exports/basket/export');

        $response->assertOk()->assertJsonPath('data.status', 'ready');
        $artifact = ExportArtifact::query()->where('user_id', $owner->id)->sole();
        $this->assertSame('ready', $artifact->status);
        $path = Storage::disk('local')->path($artifact->path);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        try {
            $workbook = $zip->getFromName('xl/workbook.xml');
            $firstSheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            $secondSheet = $zip->getFromName('xl/worksheets/sheet2.xml');
        } finally {
            $zip->close();
        }

        $this->assertSame(2, substr_count($workbook, '<sheet '));
        $this->assertStringContainsString('Daily-values', $workbook);
        $this->assertStringContainsString('Daily-values (2)', $workbook);
        $this->assertStringContainsString('portfolio-snapshots', $firstSheet);
        $this->assertStringContainsString('12345.6700', $firstSheet);
        $this->assertStringContainsString('snapshot_date', $firstSheet);
        $this->assertStringContainsString('portfolio-growth', $secondSheet);
        $this->assertStringContainsString('10000.0000', $secondSheet);
        $this->assertStringContainsString('invested_value', $secondSheet);
    }
}
