<?php

namespace Tests\Unit\Indicators;

use App\Engines\Strategy\SupportedIndicators;
use App\Services\Indicators\IndicatorCapability;
use App\Services\Indicators\IndicatorCategory;
use App\Services\Indicators\IndicatorConsumer;
use App\Services\Indicators\IndicatorDefinition;
use App\Services\Indicators\IndicatorRegistry;
use App\Services\Indicators\IndicatorRegistryFactory;
use App\Services\Indicators\IndicatorStatus;
use App\Services\Indicators\IndicatorType;
use App\Services\Screener\ScreenerCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class IndicatorRegistryTest extends TestCase
{
    private function registry(): IndicatorRegistry
    {
        return (new IndicatorRegistryFactory)->make();
    }

    public function test_empty_registry_operations(): void
    {
        $registry = new IndicatorRegistry([]);
        $this->assertSame(0, $registry->count());
        $this->assertSame([], $registry->all());
        $this->assertFalse($registry->has('rsi'));
        $this->assertNull($registry->find('rsi'));
        $this->expectException(InvalidArgumentException::class);
        $registry->get('rsi');
    }

    public function test_definition_rejects_invalid_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IndicatorDefinition::make('x', 'not_a_type', IndicatorCategory::PRICE);
    }

    public function test_types_categories_consumers_are_documented(): void
    {
        $this->assertContains(IndicatorType::PRIMARY, IndicatorType::all());
        $this->assertContains(IndicatorType::COMPOSITE, IndicatorType::all());
        $this->assertContains(IndicatorType::METRIC, IndicatorType::all());
        $this->assertTrue(IndicatorCategory::isValid(IndicatorCategory::LIQUIDITY));
        $this->assertTrue(IndicatorConsumer::isValid(IndicatorConsumer::SCREENER));
        $this->assertTrue(IndicatorStatus::isValid(IndicatorStatus::PLANNED));
    }

    public function test_screener_primary_parity(): void
    {
        $registry = $this->registry();
        $screenableIds = array_map(
            fn (IndicatorDefinition $d) => $d->id,
            $registry->filter(['screenable' => true, 'status' => IndicatorStatus::ACTIVE]),
        );
        sort($screenableIds);
        $catalogIds = ScreenerCatalog::indicatorIds();
        sort($catalogIds);
        $this->assertSame($catalogIds, $screenableIds);

        foreach (ScreenerCatalog::indicators() as $row) {
            $def = $registry->get((string) $row['id']);
            $this->assertSame(IndicatorType::PRIMARY, $def->type);
            $this->assertTrue($def->screenable);
            $catalogParamIds = array_column($row['params'] ?? [], 'id');
            $registryParamIds = array_column($def->parameters, 'id');
            $this->assertSame($catalogParamIds, $registryParamIds);
            if (! empty($row['needs_volume'])) {
                $this->assertTrue($def->hasCapability(IndicatorCapability::NEEDS_VOLUME));
            }
        }
    }

    public function test_strategy_composite_parity(): void
    {
        $registry = $this->registry();
        $strategyIds = array_map(
            fn (IndicatorDefinition $d) => $d->id,
            $registry->filter(['strategy_scorable' => true, 'status' => IndicatorStatus::ACTIVE]),
        );
        $stubIds = array_map(
            fn (IndicatorDefinition $d) => $d->id,
            $registry->filter(['strategy_scorable' => true, 'status' => IndicatorStatus::STUB]),
        );
        $scorable = [...$strategyIds, ...$stubIds];
        sort($scorable);
        $expected = SupportedIndicators::keys();
        sort($expected);
        $this->assertSame($expected, $scorable);

        foreach (SupportedIndicators::keys() as $key) {
            $def = $registry->get($key);
            $this->assertSame(IndicatorType::COMPOSITE, $def->type);
            $this->assertTrue($def->hasCapability(IndicatorCapability::STRATEGY_SCORABLE));
            $this->assertTrue($def->hasCapability(IndicatorCapability::EVALUATION_FACT));
            $this->assertIsArray($def->dependsOn);
            $this->assertContains(IndicatorConsumer::STRATEGY, $def->consumers);
        }

        $this->assertSame(IndicatorStatus::ACTIVE, $registry->get(SupportedIndicators::MARKET_REGIME)->status);
        $this->assertSame(IndicatorStatus::STUB, $registry->get(SupportedIndicators::SECTOR_STRENGTH)->status);
        $this->assertSame(['rsi'], $registry->get(SupportedIndicators::MOMENTUM_SCORE)->dependsOn);
        $this->assertNotEmpty($registry->get(SupportedIndicators::MOMENTUM_SCORE)->formulaExplanation);
    }

    public function test_aliases_resolve(): void
    {
        $registry = $this->registry();
        $this->assertSame(SupportedIndicators::MOMENTUM_SCORE, $registry->resolveId('momentum'));
        $this->assertSame(SupportedIndicators::BREAKOUT_SCORE, $registry->resolveId('pattern_bonus'));
        $this->assertNull($registry->resolveId('does_not_exist'));
    }

    public function test_stock_analytics_metrics_are_not_screenable(): void
    {
        $registry = $this->registry();
        $metrics = $registry->byType(IndicatorType::METRIC);
        $this->assertNotEmpty($metrics);
        foreach ($metrics as $metric) {
            $this->assertFalse($metric->screenable);
            $this->assertSame(IndicatorStatus::ACTIVE, $metric->status);
        }
        $this->assertTrue($registry->has('distance_52w_high_pct'));
        $this->assertTrue($registry->has('trend_strength'));
        $this->assertTrue($registry->has('discovery_pattern_count'));
    }

    public function test_liquidity_and_tradability_are_active_not_strategy_scorable(): void
    {
        $registry = $this->registry();
        foreach (['average_turnover', 'relative_turnover', 'average_volume', 'gap_frequency', 'gap_fill_ratio', 'circuit_frequency', 'circuit_risk'] as $id) {
            $def = $registry->get($id);
            $this->assertSame(IndicatorStatus::ACTIVE, $def->status);
            $this->assertSame(IndicatorType::PRIMARY, $def->type);
            $this->assertTrue($def->screenable);
            $this->assertContains(IndicatorConsumer::SCREENER, $def->consumers);
            $this->assertContains(IndicatorConsumer::DISCOVERY, $def->consumers);
            $this->assertContains(IndicatorConsumer::DASHBOARD, $def->consumers);
            $this->assertContains(IndicatorConsumer::STOCK_DETAILS, $def->consumers);
            $this->assertNotContains(IndicatorConsumer::RECOMMENDATION, $def->consumers);
        }

        $liq = $registry->get('liquidity_score');
        $this->assertSame(IndicatorStatus::ACTIVE, $liq->status);
        $this->assertSame(['relative_turnover', 'average_turnover', 'average_volume'], $liq->dependsOn);
        $this->assertFalse($liq->hasCapability(IndicatorCapability::STRATEGY_SCORABLE));
        $this->assertNotEmpty($liq->formulaExplanation);

        $trad = $registry->get('tradability_score');
        $this->assertSame(IndicatorStatus::ACTIVE, $trad->status);
        $this->assertSame(['gap_frequency', 'gap_fill_ratio', 'circuit_frequency', 'circuit_risk'], $trad->dependsOn);
        $this->assertFalse($trad->hasCapability(IndicatorCapability::STRATEGY_SCORABLE));

        $strategyKeys = SupportedIndicators::keys();
        $this->assertNotContains('liquidity_score', $strategyKeys);
        $this->assertNotContains('tradability_score', $strategyKeys);
    }

    public function test_dependency_tree_and_validation(): void
    {
        $registry = $this->registry();
        $tree = $registry->dependencyTree(SupportedIndicators::MOMENTUM_SCORE);
        $this->assertSame(SupportedIndicators::MOMENTUM_SCORE, $tree['id']);
        $this->assertSame('rsi', $tree['depends_on'][0]['id']);

        $issues = $registry->validateDependencies();
        $this->assertSame([], $issues, implode('; ', $issues));
    }

    public function test_duplicate_registration_rejected(): void
    {
        $def = IndicatorDefinition::make('rsi', IndicatorType::PRIMARY, IndicatorCategory::MOMENTUM, [
            'display_name' => 'RSI',
        ]);
        $this->expectException(InvalidArgumentException::class);
        new IndicatorRegistry([$def, $def]);
    }

    public function test_filter_by_consumer(): void
    {
        $registry = $this->registry();
        $forScreener = $registry->filter(['consumer' => IndicatorConsumer::SCREENER]);
        $this->assertNotEmpty($forScreener);
        foreach ($forScreener as $def) {
            $this->assertContains(IndicatorConsumer::SCREENER, $def->consumers);
        }
    }

    public function test_to_array_includes_metadata_fields(): void
    {
        $def = $this->registry()->get('rsi');
        $array = $def->toArray();
        foreach (['id', 'display_name', 'type', 'category', 'version', 'depends_on', 'parameters', 'units', 'precision', 'consumers', 'status', 'capabilities'] as $key) {
            $this->assertArrayHasKey($key, $array);
        }
        $this->assertSame('rsi', $array['id']);
        $this->assertSame(IndicatorType::PRIMARY, $array['type']);
    }
}
