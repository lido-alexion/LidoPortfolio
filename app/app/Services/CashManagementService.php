<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashLedgerEntry;
use App\Models\PortfolioProfile;
use App\Models\TradingRecommendation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cash balance + reserved cash (SD-026).
 * Balance is ledger-backed; reserved cash is derived from pending-execution buy reservations.
 */
class CashManagementService
{
    public function ensureAccount(PortfolioProfile $profile): CashAccount
    {
        return CashAccount::query()->firstOrCreate(
            ['profile_id' => $profile->id],
            ['balance' => 0],
        );
    }

    public function balance(PortfolioProfile $profile): float
    {
        return (float) $this->ensureAccount($profile)->balance;
    }

    /**
     * Cash committed to approved (pending_execution) buy recommendations.
     */
    public function reservedCash(PortfolioProfile $profile): float
    {
        $sum = TradingRecommendation::query()
            ->where('profile_id', $profile->id)
            ->whereIn('status', [
                TradingRecommendation::STATUS_PENDING_EXECUTION,
                TradingRecommendation::STATUS_ACCEPTED,
            ])
            ->where('reservation_status', TradingRecommendation::RESERVATION_RESERVED)
            ->sum('reserved_amount');

        return round((float) $sum, 4);
    }

    public function availableInvestableCash(PortfolioProfile $profile): float
    {
        return round(max(0.0, $this->balance($profile) - $this->reservedCash($profile)), 4);
    }

    /**
     * @return array{
     *     cash_balance: float,
     *     reserved_cash: float,
     *     available_investable_cash: float
     * }
     */
    public function summary(PortfolioProfile $profile): array
    {
        $balance = $this->balance($profile);
        $reserved = $this->reservedCash($profile);

        return [
            'cash_balance' => $balance,
            'reserved_cash' => $reserved,
            'available_investable_cash' => round(max(0.0, $balance - $reserved), 4),
        ];
    }

    public function deposit(
        PortfolioProfile $profile,
        float $amount,
        string $reason,
        ?User $user = null,
    ): CashLedgerEntry {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Deposit amount must be positive.']]);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => ['Reason is required.']]);
        }

        return $this->post($profile, CashLedgerEntry::TYPE_DEPOSIT, $amount, $reason, $user);
    }

    public function withdraw(
        PortfolioProfile $profile,
        float $amount,
        string $reason,
        ?User $user = null,
    ): CashLedgerEntry {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Withdrawal amount must be positive.']]);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => ['Reason is required.']]);
        }

        return $this->post($profile, CashLedgerEntry::TYPE_WITHDRAWAL, -abs($amount), $reason, $user);
    }

    public function adjust(
        PortfolioProfile $profile,
        float $amount,
        string $reason,
        ?User $user = null,
    ): CashLedgerEntry {
        if ($amount == 0.0) {
            throw ValidationException::withMessages(['amount' => ['Adjustment amount cannot be zero.']]);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => ['Reason is required for cash adjustments.']]);
        }

        return $this->post($profile, CashLedgerEntry::TYPE_ADJUSTMENT, $amount, $reason, $user);
    }

    /**
     * Apply a buy/sell ledger transaction to cash (actual outflow/inflow).
     */
    public function applyTradeTransaction(
        PortfolioProfile $profile,
        Transaction $transaction,
        ?User $user = null,
    ): ?CashLedgerEntry {
        $qty = (float) $transaction->quantity;
        $price = (float) $transaction->price;
        $fees = (float) ($transaction->fees ?? 0);
        $notional = round($qty * $price, 4);

        if (strtolower((string) $transaction->type) === 'buy') {
            $delta = -round($notional + $fees, 4);

            return $this->post(
                $profile,
                CashLedgerEntry::TYPE_BUY,
                $delta,
                'Buy '.$transaction->stock?->symbol.' qty '.$qty,
                $user,
                $transaction->id,
                $transaction->recommendation_id,
            );
        }

        if (strtolower((string) $transaction->type) === 'sell') {
            $delta = round($notional - $fees, 4);

            return $this->post(
                $profile,
                CashLedgerEntry::TYPE_SELL,
                $delta,
                'Sell '.$transaction->stock?->symbol.' qty '.$qty,
                $user,
                $transaction->id,
                $transaction->recommendation_id,
            );
        }

        return null;
    }

    /**
     * Reverse a trade cash effect when a transaction is deleted.
     */
    public function reverseTradeTransaction(
        PortfolioProfile $profile,
        Transaction $transaction,
        ?User $user = null,
    ): ?CashLedgerEntry {
        $existing = CashLedgerEntry::query()
            ->where('profile_id', $profile->id)
            ->where('transaction_id', $transaction->id)
            ->whereIn('entry_type', [CashLedgerEntry::TYPE_BUY, CashLedgerEntry::TYPE_SELL])
            ->orderByDesc('id')
            ->first();

        if (! $existing) {
            return null;
        }

        return $this->post(
            $profile,
            CashLedgerEntry::TYPE_ADJUSTMENT,
            -1 * (float) $existing->amount,
            'Reverse cash for deleted transaction #'.$transaction->id,
            $user,
            $transaction->id,
            $transaction->recommendation_id,
        );
    }

    /**
     * @return list<CashLedgerEntry>
     */
    public function recentEntries(PortfolioProfile $profile, int $limit = 50): array
    {
        return CashLedgerEntry::query()
            ->where('profile_id', $profile->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    protected function post(
        PortfolioProfile $profile,
        string $type,
        float $signedAmount,
        string $reason,
        ?User $user = null,
        ?int $transactionId = null,
        ?int $recommendationId = null,
    ): CashLedgerEntry {
        return DB::transaction(function () use (
            $profile,
            $type,
            $signedAmount,
            $reason,
            $user,
            $transactionId,
            $recommendationId,
        ) {
            $account = CashAccount::query()
                ->where('profile_id', $profile->id)
                ->lockForUpdate()
                ->first();

            if (! $account) {
                $account = CashAccount::query()->create([
                    'profile_id' => $profile->id,
                    'balance' => 0,
                ]);
                $account = CashAccount::query()
                    ->where('profile_id', $profile->id)
                    ->lockForUpdate()
                    ->first();
            }

            $newBalance = round((float) $account->balance + $signedAmount, 4);
            if ($newBalance < -0.0001) {
                throw ValidationException::withMessages([
                    'amount' => ['Insufficient cash balance for this operation.'],
                ]);
            }

            $account->forceFill(['balance' => max(0, $newBalance)])->save();

            return CashLedgerEntry::query()->create([
                'profile_id' => $profile->id,
                'entry_type' => $type,
                'amount' => $signedAmount,
                'balance_after' => (float) $account->balance,
                'reason' => $reason,
                'transaction_id' => $transactionId,
                'recommendation_id' => $recommendationId,
                'user_id' => $user?->id,
                'created_at' => now(),
            ]);
        });
    }
}
