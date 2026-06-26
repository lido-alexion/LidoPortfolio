<?php

namespace Tests\Unit;

use App\Services\Alerts\FormulaEvaluator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class FormulaEvaluatorTest extends TestCase
{
    public function test_evaluates_substituted_numeric_expression_with_division(): void
    {
        $evaluator = new FormulaEvaluator;

        $result = $evaluator->evaluate('{{latest_close}} / {{avg_buy_price}}', [
            'latest_close' => 90.0,
            'avg_buy_price' => 100.0,
        ]);

        $this->assertEqualsWithDelta(0.9, $result, 0.0001);
    }

    public function test_evaluates_parenthesized_expression(): void
    {
        $evaluator = new FormulaEvaluator;

        $result = $evaluator->evaluate('({{highest_close_since_buy}} * (100 - {{stoploss_percent}})) / 100', [
            'highest_close_since_buy' => 1000.0,
            'stoploss_percent' => 10.0,
        ]);

        $this->assertEqualsWithDelta(900.0, $result, 0.0001);
    }

    public function test_evaluates_bare_column_identifier(): void
    {
        $evaluator = new FormulaEvaluator;

        $result = $evaluator->evaluate('latest_close * 0.9', [
            'latest_close' => 10000.0,
        ]);

        $this->assertEqualsWithDelta(9000.0, $result, 0.0001);
    }

    public function test_rejects_unknown_characters_after_substitution(): void
    {
        $evaluator = new FormulaEvaluator;

        $this->expectException(InvalidArgumentException::class);
        $evaluator->evaluate('{{latest_close}} + abc', [
            'latest_close' => 100.0,
        ]);
    }
}
