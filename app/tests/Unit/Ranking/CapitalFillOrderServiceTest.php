<?php

namespace Tests\Unit\Ranking;

use App\Services\Ranking\CapitalFillOrderService;
use App\Services\Ranking\ReturnQualityRankingService;
use Tests\TestCase;

class CapitalFillOrderServiceTest extends TestCase
{
    private CapitalFillOrderService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new CapitalFillOrderService;
    }

    private function candidate(?float $target, ?float $fit, string $symbol, array $extra = []): array
    {
        return array_merge([
            'target_amount' => $target,
            'fit_score' => $fit,
            'symbol' => $symbol,
        ], $extra);
    }

    private function symbols(array $ordered): array
    {
        return array_column($ordered, 'symbol');
    }

    // ═══ PRIMARY KEY: target amount ═══

    public function test_target_amount_is_primary_sort_key(): void
    {
        $result = $this->svc->order([
            $this->candidate(100_000, 95.0, 'AAA'),
            $this->candidate(120_000, 70.0, 'BBB'),
        ]);

        $this->assertEquals(['BBB', 'AAA'], $this->symbols($result));
    }

    public function test_higher_target_before_lower_regardless_of_fit(): void
    {
        $result = $this->svc->order([
            $this->candidate(100_000, 95.0, 'HIGH_FIT'),
            $this->candidate(120_000, 70.0, 'HIGH_TARGET'),
        ]);

        $this->assertEquals('HIGH_TARGET', $result[0]['symbol']);
    }

    // ═══ SECONDARY KEY: fit ═══

    public function test_fit_is_secondary_when_target_equal(): void
    {
        $result = $this->svc->order([
            $this->candidate(100_000, 80.0, 'BBB'),
            $this->candidate(100_000, 90.0, 'AAA'),
        ]);

        $this->assertEquals(['AAA', 'BBB'], $this->symbols($result));
    }

    // ═══ TERTIARY KEY: symbol ═══

    public function test_symbol_is_final_deterministic_tiebreak(): void
    {
        $result = $this->svc->order([
            $this->candidate(100_000, 80.0, 'ZZZ'),
            $this->candidate(100_000, 80.0, 'AAA'),
        ]);

        $this->assertEquals(['AAA', 'ZZZ'], $this->symbols($result));
    }

    // ═══ MULTI-CANDIDATE ORDERING ═══

    public function test_exact_ordering_with_multiple_candidates(): void
    {
        $result = $this->svc->order([
            $this->candidate(100_000, 80.0, 'ZZZ'),
            $this->candidate(120_000, 70.0, 'BBB'),
            $this->candidate(100_000, 80.0, 'AAA'),
            $this->candidate(100_000, 90.0, 'CCC'),
            $this->candidate(80_000, 99.0, 'DDD'),
        ]);

        $this->assertEquals(
            ['BBB', 'CCC', 'AAA', 'ZZZ', 'DDD'],
            $this->symbols($result)
        );
    }

    // ═══ DETERMINISM ═══

    public function test_input_order_does_not_affect_output(): void
    {
        $a = $this->candidate(100_000, 80.0, 'AAA');
        $b = $this->candidate(100_000, 90.0, 'BBB');
        $c = $this->candidate(120_000, 70.0, 'CCC');

        $order1 = $this->svc->order([$a, $b, $c]);
        $order2 = $this->svc->order([$c, $a, $b]);
        $order3 = $this->svc->order([$b, $c, $a]);

        $this->assertEquals($this->symbols($order1), $this->symbols($order2));
        $this->assertEquals($this->symbols($order1), $this->symbols($order3));
    }

    // ═══ NO CONVICTION SUB-SCORE ═══

    public function test_no_conviction_sub_score_consulted(): void
    {
        $a = $this->candidate(100_000, 80.0, 'AAA', ['conviction_sub_score' => 99.0]);
        $b = $this->candidate(100_000, 80.0, 'BBB', ['conviction_sub_score' => 1.0]);

        $result = $this->svc->order([$a, $b]);

        $this->assertEquals(['AAA', 'BBB'], $this->symbols($result));
    }

    // ═══ IMMUTABILITY ═══

    public function test_service_does_not_mutate_candidate_data(): void
    {
        $original = [
            $this->candidate(100_000, 80.0, 'BBB', ['extra' => 'keep']),
            $this->candidate(120_000, 70.0, 'AAA', ['extra' => 'also']),
        ];

        $snapshot = json_encode($original);
        $this->svc->order($original);

        $this->assertEquals($snapshot, json_encode($original));
    }

    // ═══ EDGE CASES ═══

    public function test_empty_list(): void
    {
        $this->assertEquals([], $this->svc->order([]));
    }

    public function test_single_candidate(): void
    {
        $c = $this->candidate(50_000, 75.0, 'ONLY');
        $result = $this->svc->order([$c]);

        $this->assertCount(1, $result);
        $this->assertEquals('ONLY', $result[0]['symbol']);
    }

    public function test_zero_target(): void
    {
        $result = $this->svc->order([
            $this->candidate(0.0, 80.0, 'ZERO'),
            $this->candidate(50_000, 60.0, 'NONZERO'),
        ]);

        $this->assertEquals(['NONZERO', 'ZERO'], $this->symbols($result));
    }

    public function test_null_target_treated_as_zero(): void
    {
        $result = $this->svc->order([
            $this->candidate(null, 80.0, 'NULL_T'),
            $this->candidate(50_000, 60.0, 'REAL'),
        ]);

        $this->assertEquals(['REAL', 'NULL_T'], $this->symbols($result));
    }

    public function test_null_fit_treated_as_lowest(): void
    {
        $result = $this->svc->order([
            $this->candidate(100_000, null, 'NULL_F'),
            $this->candidate(100_000, 50.0, 'REAL'),
        ]);

        $this->assertEquals(['REAL', 'NULL_F'], $this->symbols($result));
    }

    public function test_extra_data_preserved_in_output(): void
    {
        $c = $this->candidate(100_000, 80.0, 'AAA', ['security_id' => 42, 'action' => 'OPEN']);
        $result = $this->svc->order([$c]);

        $this->assertEquals(42, $result[0]['security_id']);
        $this->assertEquals('OPEN', $result[0]['action']);
    }

    // ═══ RELATIONSHIP TO RETURN-QUALITY RANKING ═══

    public function test_fill_order_is_distinct_from_ranking(): void
    {
        $fillService = new CapitalFillOrderService;
        $rankingService = new ReturnQualityRankingService;

        $this->assertNotEquals(
            get_class($fillService),
            get_class($rankingService),
            'CapitalFillOrderService and ReturnQualityRankingService must be separate services'
        );

        $ordered = $fillService->order([
            $this->candidate(100_000, 80.0, 'A'),
        ]);

        $this->assertArrayNotHasKey('return_quality', $ordered[0]);
        $this->assertArrayNotHasKey('computable', $ordered[0]);
    }
}
