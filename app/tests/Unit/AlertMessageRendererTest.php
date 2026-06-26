<?php

namespace Tests\Unit;

use App\Services\Alerts\AlertMessageRenderer;
use App\Services\Alerts\FormulaEvaluator;
use PHPUnit\Framework\TestCase;

class AlertMessageRendererTest extends TestCase
{
  /** @var array<string, float|null> */
    private array $numeric = [
        'symbol' => null,
        'latest_close' => 10000.0,
        'trailing_stop_price' => 9000.0,
        'quantity' => 10.0,
    ];

  /** @var array<string, string> */
    private array $display = [
        'symbol' => 'TEST',
        'latest_close' => '10000',
        'trailing_stop_price' => '9000',
        'quantity' => '10',
    ];

    private AlertMessageRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new AlertMessageRenderer(new FormulaEvaluator);
    }

    public function test_formats_number_block_with_column_tag(): void
    {
        $result = $this->renderer->render(
            'Price is [[ {{latest_close}} ]]',
            $this->numeric,
            $this->display,
        );

        $this->assertSame('Price is 10,000.00', $result);
    }

    public function test_evaluates_math_block_with_column_tag(): void
    {
        $result = $this->renderer->render(
            'Current stoploss is <<{{latest_close}} * 0.9>>',
            $this->numeric,
            $this->display,
        );

        $this->assertSame('Current stoploss is 9000', $result);
    }

    public function test_nested_math_inside_number_block(): void
    {
        $result = $this->renderer->render(
            'Current stoploss is [[<<latest_close * 0.9>>]]',
            $this->numeric,
            $this->display,
        );

        $this->assertSame('Current stoploss is 9,000.00', $result);
    }

    public function test_plain_column_tags_use_display_values(): void
    {
        $result = $this->renderer->render(
            '{{symbol}} at {{latest_close}}',
            $this->numeric,
            $this->display,
        );

        $this->assertSame('TEST at 10000', $result);
    }

    public function test_small_number_formats_with_two_decimals(): void
    {
        $numeric = array_merge($this->numeric, ['latest_close' => 1000.0]);
        $display = array_merge($this->display, ['latest_close' => '1000']);

        $result = $this->renderer->render(
            '[[{{latest_close}}]]',
            $numeric,
            $display,
        );

        $this->assertSame('1,000.00', $result);
    }

    public function test_format_block_evaluates_math_expression(): void
    {
        $result = $this->renderer->render(
            '[[{{highest_close_since_buy}} * 0.95]]',
            array_merge($this->numeric, ['highest_close_since_buy' => 2000.0]),
            $this->display,
        );

        $this->assertSame('1,900.00', $result);
    }

    public function test_math_wrapping_formatted_expression_resolves_without_loop(): void
    {
        $numeric = array_merge($this->numeric, ['highest_close_since_buy' => 2000.0]);

        $result = $this->renderer->render(
            '= <<[[{{highest_close_since_buy}} * 0.95]]>>',
            $numeric,
            $this->display,
        );

        $this->assertSame('= 1900', $result);
    }

    public function test_full_stoploss_style_message(): void
    {
        $numeric = [
            'latest_close' => 1800.0,
            'highest_close_since_buy' => 2000.0,
        ];
        $display = [
            'latest_close' => '1800',
            'highest_close_since_buy' => '2000',
        ];

        $template = 'LTP (₹ [[{{latest_close}}]]) is less than trailling stoploss at 95% of highest close since bought i.e. 95% of ₹ [[{{highest_close_since_buy}}]] = <<[[{{highest_close_since_buy}} * 0.95]]>>';

        $result = $this->renderer->render($template, $numeric, $display);

        $this->assertSame(
            'LTP (₹ 1,800.00) is less than trailling stoploss at 95% of highest close since bought i.e. 95% of ₹ 2,000.00 = 1900',
            $result,
        );
    }
}
