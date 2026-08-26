<?php

namespace Tests\Unit\Indicators;

use App\Engines\Strategy\SupportedIndicators;
use App\Services\Indicators\IndicatorRegistryFactory;
use App\Services\Indicators\IndicatorRegistryValidator;
use App\Services\Indicators\IndicatorStatus;
use App\Services\Indicators\ScreenerMinBars;
use App\Services\Indicators\ScreenerPrimarySeed;
use App\Services\Indicators\StrategyCompositeSeed;
use App\Services\Screener\ScreenerCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Epic 2: catalogues project from Registry without behaviour drift.
 */
class IndicatorRegistryFacadeParityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ScreenerCatalog::clearIndicatorCache();
        SupportedIndicators::clearDefinitionsCache();
    }

    public function test_screener_catalog_ids_match_primary_seed(): void
    {
        $seedIds = ScreenerPrimarySeed::ids();
        $catalogIds = ScreenerCatalog::indicatorIds();
        $this->assertSame($seedIds, $catalogIds);
    }

    public function test_screener_meta_shape_preserved(): void
    {
        $meta = ScreenerCatalog::meta();
        $this->assertArrayHasKey('indicators', $meta);
        $this->assertArrayHasKey('operators', $meta);
        $this->assertCount(count(ScreenerPrimarySeed::ids()), $meta['indicators']);

        $rsi = null;
        foreach ($meta['indicators'] as $row) {
            $this->assertArrayNotHasKey('min_bars_fn', $row);
            if ($row['id'] === 'rsi') {
                $rsi = $row;
            }
        }
        $this->assertNotNull($rsi);
        $this->assertSame('RSI', $rsi['label']);
        $this->assertSame(14, $rsi['params'][0]['default']);
        $this->assertSame(15, $rsi['min_bars']);
    }

    public function test_screener_min_bars_matches_legacy_formulas(): void
    {
        $this->assertSame(15, ScreenerCatalog::minBars('rsi', ['period' => 14]));
        $this->assertSame(15, ScreenerMinBars::compute('rsi', ['period' => 14]));
        $this->assertSame(50, ScreenerCatalog::minBars('sma_spread_pct', ['fast' => 20, 'slow' => 50]));
        $this->assertTrue(ScreenerCatalog::needsVolume('volume_ratio'));
        $this->assertFalse(ScreenerCatalog::needsVolume('close'));
    }

    public function test_supported_indicators_match_strategy_seed(): void
    {
        $seedKeys = array_column(StrategyCompositeSeed::rows(), 'key');
        $this->assertSame($seedKeys, SupportedIndicators::keys());

        foreach (StrategyCompositeSeed::rows() as $seed) {
            $found = null;
            foreach (SupportedIndicators::definitions() as $def) {
                if ($def['key'] === $seed['key']) {
                    $found = $def;
                    break;
                }
            }
            $this->assertNotNull($found, $seed['key']);
            $this->assertSame($seed['display_name'], $found['display_name']);
            $this->assertSame($seed['category'], $found['category']);
            $this->assertSame($seed['default_weight'], $found['default_weight']);
            $this->assertSame($seed['default_minimum'], $found['default_minimum']);
            $this->assertSame($seed['default_maximum'], $found['default_maximum']);
            $this->assertSame($seed['default_enabled'], $found['default_enabled']);
            $this->assertSame($seed['supports_maximum'], $found['supports_maximum']);
            $this->assertSame(array_keys($seed['parameters']), array_keys($found['parameters']));
        }
    }

    public function test_liquidity_tradability_composites_not_in_strategy_catalogue(): void
    {
        $keys = SupportedIndicators::keys();
        $this->assertNotContains('liquidity_score', $keys);
        $this->assertNotContains('tradability_score', $keys);
    }

    public function test_registry_validator_passes_default_graph(): void
    {
        $registry = (new IndicatorRegistryFactory)->make();
        $issues = (new IndicatorRegistryValidator)->validate($registry);
        $this->assertSame([], $issues, implode('; ', $issues));
    }

    public function test_aliases_unchanged(): void
    {
        $this->assertSame(SupportedIndicators::MOMENTUM_SCORE, SupportedIndicators::canonicalizeKey('momentum'));
        $this->assertSame(SupportedIndicators::BREAKOUT_SCORE, SupportedIndicators::canonicalizeKey('pattern_bonus'));
    }

    public function test_by_category_groups_legacy_labels(): void
    {
        $grouped = SupportedIndicators::byCategory();
        $this->assertArrayHasKey(SupportedIndicators::CATEGORY_MOMENTUM, $grouped);
        $this->assertArrayHasKey(SupportedIndicators::CATEGORY_RISK, $grouped);
    }

    public function test_market_regime_is_active_and_sector_strength_remains_stub(): void
    {
        $registry = (new IndicatorRegistryFactory)->make();
        $this->assertSame(IndicatorStatus::ACTIVE, $registry->get(SupportedIndicators::MARKET_REGIME)->status);
        $this->assertSame(IndicatorStatus::STUB, $registry->get(SupportedIndicators::SECTOR_STRENGTH)->status);
        $this->assertContains(SupportedIndicators::MARKET_REGIME, SupportedIndicators::keys());
    }
}
