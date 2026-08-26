<?php

namespace Tests\Unit\Evaluation;

use App\Engines\Evaluation\MarketRegimeScoreMapper;
use Tests\TestCase;

class MarketRegimeScoreMapperTest extends TestCase
{
    public function test_maps_bullish_neutral_bearish_only(): void
    {
        $mapper = new MarketRegimeScoreMapper;

        $this->assertSame(100.0, $mapper->score('Bullish'));
        $this->assertSame(50.0, $mapper->score('Neutral'));
        $this->assertSame(0.0, $mapper->score('Bearish'));
    }

    public function test_does_not_score_market_phases(): void
    {
        $mapper = new MarketRegimeScoreMapper;

        $this->assertSame(50.0, $mapper->score('Strong Bull'));
        $this->assertSame(50.0, $mapper->score('Recovery'));
        $this->assertSame(50.0, $mapper->score('Bear'));
        $this->assertSame(50.0, $mapper->score('Capitulation'));
        $this->assertSame(50.0, $mapper->score(null));
    }
}
