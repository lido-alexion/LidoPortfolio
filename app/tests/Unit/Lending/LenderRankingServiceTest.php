<?php

namespace Tests\Unit\Lending;

use App\Services\Lending\LenderRankingService;
use PHPUnit\Framework\TestCase;

class LenderRankingServiceTest extends TestCase
{
    private LenderRankingService $ranking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ranking = new LenderRankingService;
    }

    public function test_primary_order_is_available_for_lending_percentage_descending(): void
    {
        $ordered = $this->ranking->rank([
            $this->lender(1, 10_000, 100_000),
            $this->lender(2, 20_000, 100_000),
        ]);

        $this->assertSame([2, 1], array_column($ordered, 'strategy_id'));
    }

    public function test_secondary_order_is_available_for_lending_amount_descending(): void
    {
        $ordered = $this->ranking->rank([
            $this->lender(1, 5_000, 50_000),
            $this->lender(2, 10_000, 100_000),
        ]);

        $this->assertEqualsWithDelta(0.1, $this->ranking->lendablePercentage($ordered[0]), 0.0000001);
        $this->assertEqualsWithDelta(0.1, $this->ranking->lendablePercentage($ordered[1]), 0.0000001);
        $this->assertSame([2, 1], array_column($ordered, 'strategy_id'));
    }

    public function test_exact_tie_is_deterministic_by_strategy_id_ascending(): void
    {
        $ordered = $this->ranking->rank([
            $this->lender(9, 10_000, 100_000),
            $this->lender(3, 10_000, 100_000),
            $this->lender(6, 10_000, 100_000),
        ]);

        $this->assertSame([3, 6, 9], array_column($ordered, 'strategy_id'));
        $this->assertSame(
            [3, 6, 9],
            array_column($this->ranking->rank(array_reverse($ordered)), 'strategy_id')
        );
    }

    public function test_zero_allocated_capital_uses_zero_percentage(): void
    {
        $ordered = $this->ranking->rank([
            $this->lender(1, 50_000, 0),
            $this->lender(2, 10_000, 100_000),
        ]);

        $this->assertSame([2, 1], array_column($ordered, 'strategy_id'));
    }

    public function test_ranking_does_not_exclude_borrower_identity(): void
    {
        $borrowerId = 4;
        $ordered = $this->ranking->rank([
            $this->lender($borrowerId, 20_000, 100_000),
            $this->lender(5, 10_000, 100_000),
        ]);

        $this->assertSame([$borrowerId, 5], array_column($ordered, 'strategy_id'));
    }

    /**
     * @return array{strategy_id: int, available_for_lending: float, strategy_capital_allocation: float}
     */
    private function lender(int $id, float $afl, float $allocated): array
    {
        return [
            'strategy_id' => $id,
            'available_for_lending' => $afl,
            'strategy_capital_allocation' => $allocated,
        ];
    }
}
