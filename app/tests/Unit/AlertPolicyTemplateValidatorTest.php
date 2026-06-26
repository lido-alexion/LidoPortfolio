<?php

namespace Tests\Unit;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Alerts\AlertPolicyTemplateValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AlertPolicyTemplateValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_unclosed_format_block(): void
    {
        $validator = app(AlertPolicyTemplateValidator::class);

        $this->expectException(ValidationException::class);
        $validator->validateForProfile($this->profileWithHolding(), [
            'message_template' => 'Price [[{{latest_close}}',
            'compare_type' => 'constant',
        ]);
    }

    public function test_rejects_unresolvable_message_on_dry_run(): void
    {
        $validator = app(AlertPolicyTemplateValidator::class);

        try {
            $validator->validateForProfile($this->profileWithHolding(), [
                'message_template' => 'Broken <<{{avg_buy_price}} * >>',
                'compare_type' => 'constant',
            ]);
            $this->fail('Expected validation exception');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('message_template', $e->errors());
        }
    }

    public function test_accepts_valid_message_and_derived_formula(): void
    {
        $validator = app(AlertPolicyTemplateValidator::class);
        $profile = $this->profileWithHolding();

        $validator->validateForProfile($profile, [
            'message_template' => '{{symbol}} qty [[{{quantity}}]]',
            'compare_type' => 'derived',
            'compare_formula' => '{{avg_buy_price}} * 0.95',
        ]);

        $this->assertTrue(true);
    }

    public function test_rejects_invalid_derived_formula(): void
    {
        $validator = app(AlertPolicyTemplateValidator::class);

        try {
            $validator->validateForProfile($this->profileWithHolding(), [
                'message_template' => '{{symbol}} alert',
                'compare_type' => 'derived',
                'compare_formula' => '{{latest_close}} * abc',
            ]);
            $this->fail('Expected validation exception');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('compare_formula', $e->errors());
        }
    }

    public function test_requires_holding_for_dry_run(): void
    {
        $user = User::query()->create([
            'name' => 'Empty Portfolio',
            'email' => 'empty-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $validator = app(AlertPolicyTemplateValidator::class);

        try {
            $validator->validateForProfile($profile, [
                'message_template' => '{{symbol}} ok',
                'compare_type' => 'constant',
            ]);
            $this->fail('Expected validation exception');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('message_template', $e->errors());
            $this->assertStringContainsString('open holding', $e->errors()['message_template'][0]);
        }
    }

    protected function profileWithHolding(): PortfolioProfile
    {
        $user = User::query()->create([
            'name' => 'Validator User',
            'email' => 'val-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'VAL1',
            'exchange' => 'NSE',
            'name' => 'Validator Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-01-01',
        ]);

        return $profile->fresh();
    }
}
