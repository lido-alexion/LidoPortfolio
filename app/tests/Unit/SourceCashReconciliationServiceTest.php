<?php

namespace Tests\Unit;

use App\Services\SourceCashReconciliationService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SourceCashReconciliationServiceTest extends TestCase
{
    public function test_reconstructs_trade_and_bank_cash_with_one_labelled_residual_opening(): void
    {
        $source = [
            'profile_id' => 7,
            'transactions' => [
                ['id' => 1, 'profile_id' => 7, 'date' => '2026-06-19', 'type' => 'buy', 'quantity' => 10, 'price' => 100, 'fees' => 15.34],
                ['id' => 2, 'profile_id' => 7, 'date' => '2026-06-20', 'type' => 'sell', 'quantity' => 2, 'price' => 120, 'fees' => 15.34],
            ],
            'bank_flows' => [
                ['id' => 3, 'profile_id' => 7, 'date' => '2026-06-18', 'type' => 'deposit', 'amount' => 1000],
            ],
            'broker_flows' => [
                ['id' => 4, 'profile_id' => 7, 'date' => '2026-06-19', 'kind' => 'trade_link', 'transaction_id' => 1, 'cash_delta' => -1015.34],
                ['id' => 5, 'profile_id' => 7, 'date' => '2026-06-20', 'kind' => 'dp_charge', 'amount' => 15.34],
            ],
            'closing_balance_snapshot' => ['id' => 'snapshot-a', 'date' => '2026-06-20', 'balance' => 500],
        ];

        $result = (new SourceCashReconciliationService)->reconcile($source);

        $this->assertSame(-790.68, $result['transaction_cash']['total']);
        $this->assertSame(1000.0, $result['bank_cash']['total']);
        $this->assertSame(15.34, $result['broker_dp_evidence']['rows'][0]['amount']);
        $this->assertSame(290.68, $result['opening_balance']['amount']);
        $this->assertSame('derived residual; not an observed bank deposit or broker cash movement', $result['opening_balance']['provenance']);
        $this->assertSame(500.0, $result['closing_balance']);
        $this->assertFalse($result['writes_performed']);
    }

    public function test_genuine_amc_and_ddpi_charges_reduce_cash_once_without_double_charging_dp(): void
    {
        $source = [
            'profile_id' => 3,
            'transactions' => [['id' => 9, 'profile_id' => 3, 'date' => '2026-11-16', 'type' => 'sell', 'quantity' => 1, 'price' => 100, 'fees' => 15.34]],
            'account_charges' => [
                ['id' => 'amc-1', 'profile_id' => 3, 'date' => '2026-10-01', 'kind' => 'amc', 'amount' => 88.50],
                ['id' => 'ddpi-1', 'profile_id' => 3, 'date' => '2026-10-01', 'kind' => 'ddpi', 'amount' => 118],
            ],
            'broker_flows' => [['id' => 'dp-9', 'profile_id' => 3, 'date' => '2026-11-16', 'kind' => 'dp_charge', 'amount' => 15.34]],
            'closing_balance_snapshot' => ['id' => 'balance-1', 'date' => '2026-11-16', 'balance' => 500],
        ];
        $result = (new SourceCashReconciliationService)->reconcile($source);
        $this->assertSame(84.66, $result['transaction_cash']['total']);
        $this->assertSame(-206.50, $result['account_charges']['total']);
        $this->assertSame(2, $result['account_charges']['count']);
        $this->assertSame(621.84, $result['opening_balance']['amount']);
        $this->assertSame(15.34, $result['broker_dp_evidence']['rows'][0]['amount']);
        $this->assertSame(3, $result['cash_event_count']);
    }

    public function test_rejects_cross_profile_data_and_duplicate_trade_links(): void
    {
        $service = new SourceCashReconciliationService;
        $source = ['profile_id' => 1, 'transactions' => [['id' => 1, 'profile_id' => 2, 'date' => '2026-01-01', 'type' => 'buy', 'quantity' => 1, 'price' => 1, 'fees' => 0]]];
        try {
            $service->reconcile($source);
            $this->fail('Cross-profile source rows must fail closed.');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('outside the selected profile', $error->getMessage());
        }

        $source['transactions'][0]['profile_id'] = 1;
        $source['broker_flows'] = [
            ['id' => 2, 'profile_id' => 1, 'date' => '2026-01-01', 'kind' => 'trade_link', 'transaction_id' => 1, 'cash_delta' => -1],
            ['id' => 3, 'profile_id' => 1, 'date' => '2026-01-01', 'kind' => 'trade_link', 'transaction_id' => 1, 'cash_delta' => -1],
        ];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('inconsistent duplicate broker trade links');
        $service->reconcile($source);
    }

    public function test_rejects_unmatched_broker_trade_cash_and_duplicate_ids(): void
    {
        $source = [
            'profile_id' => 1,
            'transactions' => [['id' => 1, 'profile_id' => 1, 'date' => '2026-01-01', 'type' => 'buy', 'quantity' => 2, 'price' => 10, 'fees' => 1]],
            'broker_flows' => [['id' => 2, 'profile_id' => 1, 'date' => '2026-01-01', 'kind' => 'trade_link', 'transaction_id' => 1, 'cash_delta' => -20]],
        ];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match transaction');
        (new SourceCashReconciliationService)->reconcile($source);
    }
}
