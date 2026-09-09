<?php

namespace Tests\Feature;

use App\Exceptions\DomainException;
use App\Models\ArtifactBinding;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Screener;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Services\Artifacts\LegacyArtifactBackfillService;
use App\Services\Screener\ScreenerRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreenerArtifactRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_mapped_screener_runs_pinned_definition_and_retains_exact_evidence(): void
    {
        [$profile, $screener, $stock] = $this->fixture();
        $result = app(LegacyArtifactBackfillService::class)->backfill($profile);
        $this->assertSame(0, $result['failed']);
        $screener = $screener->fresh('reusableArtifact');
        $binding = $screener->reusableArtifact->bindings()->where('profile_id', $profile->id)->sole();
        $version = $binding->activeRevision->artifactVersion;

        $screener->forceFill([
            'definition_json' => [
                'root' => [
                    'type' => 'condition',
                    'left' => ['indicator' => 'sma', 'params' => ['period' => 5]],
                    'operator' => 'lt',
                    'right' => ['type' => 'constant', 'value' => 0],
                ],
            ],
        ])->save();

        $run = app(ScreenerRunService::class)->runToCompletion($screener);

        $this->assertSame('completed', $run->status);
        $this->assertSame($version->id, $run->reusable_artifact_version_id);
        $this->assertSame($binding->active_revision_id, $run->artifact_binding_revision_id);
        $this->assertTrue($run->hits()->where('stock_id', $stock->id)->exists());
        $formatted = app(ScreenerRunService::class)->formatRun($run);
        $this->assertSame($version->id, $formatted['reusable_artifact_version_id']);
        $this->assertSame($binding->active_revision_id, $formatted['artifact_binding_revision_id']);
    }

    public function test_mapped_screener_fails_closed_when_binding_is_disabled(): void
    {
        [$profile, $screener] = $this->fixture();
        app(LegacyArtifactBackfillService::class)->backfill($profile);
        $screener = $screener->fresh('reusableArtifact');
        $screener->reusableArtifact->bindings()
            ->where('profile_id', $profile->id)
            ->update(['status' => ArtifactBinding::STATUS_DISABLED]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('immutable artifact binding is unavailable or blocked');

        app(ScreenerRunService::class)->start($screener);
    }

    /** @return array{0:PortfolioProfile,1:Screener,2:Stock} */
    private function fixture(): array
    {
        $profile = $this->defaultPortfolioFor(User::factory()->create());
        $stock = Stock::query()->create([
            'symbol' => 'PINNED',
            'exchange' => 'NSE',
            'name' => 'Pinned Runtime',
            'is_active' => true,
        ]);
        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 1,
            'avg_buy_price' => 100,
            'invested_amount' => 100,
        ]);
        for ($day = 10; $day >= 0; $day--) {
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => now()->subDays($day)->toDateString(),
                'open_price' => 100,
                'high_price' => 101,
                'low_price' => 99,
                'close_price' => 100,
                'adjusted_close_price' => 100,
                'volume' => 10000,
                'data_source' => 'test',
            ]);
        }
        $screener = Screener::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Pinned Screener',
            'slug' => 'pinned_screener',
            'artifact_version' => 1,
            'artifact_status' => 'active',
            'scope' => 'holdings',
            'definition_json' => [
                'root' => [
                    'type' => 'condition',
                    'left' => ['indicator' => 'sma', 'params' => ['period' => 5]],
                    'operator' => 'gt',
                    'right' => ['type' => 'constant', 'value' => 0],
                ],
            ],
            'is_enabled' => true,
            'telegram_enabled' => false,
        ]);

        return [$profile, $screener, $stock];
    }
}
