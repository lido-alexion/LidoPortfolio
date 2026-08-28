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
            ->forProfile($profile)
            ->pendingExecution()
            ->withCashReservation()
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
     *     available_investable_cash: float,
     *     reservations: list<array<string, mixed>>
     * }
     */
    public function summary(PortfolioProfile $profile, bool $includeReservations = false): array
    {
        $balance = $this->balance($profile);
        $reserved = $this->reservedCash($profile);

        $payload = [
            'cash_balance' => $balance,
            'reserved_cash' => $reserved,
            'available_investable_cash' => round(max(0.0, $balance - $reserved), 4),
            'available_physical_cash' => round(max(0.0, $balance - $reserved), 4),
        ];

        if ($includeReservations) {
            $payload['reservations'] = $this->reservationDetails($profile);
        }

        return $payload;
    }

    /**
     * Active cash reservations (approved buys awaiting execution).
     *
     * @return list<array{
     *     recommendation_id: int,
     *     symbol: ?string,
     *     name: ?string,
     *     portfolio_action: ?string,
     *     ui_label: ?string,
     *     reserved_amount: float,
     *     suggested_quantity: ?float,
     *     reference_price: ?float,
     *     reserved_at: ?string,
     *     approved_at: ?string,
     *     status: string
     * }>
     */
    public function reservationDetails(PortfolioProfile $profile): array
    {
        $rows = TradingRecommendation::query()
            ->with('security')
            ->forProfile($profile)
            ->pendingExecution()
            ->withCashReservation()
            ->whereNotNull('reserved_amount')
            ->where('reserved_amount', '>', 0)
            ->orderByDesc('reserved_at')
            ->orderByDesc('id')
            ->get();

        return $rows->map(function (TradingRecommendation $r) {
            return [
                'recommendation_id' => $r->id,
                'symbol' => $r->security?->symbol,
                'name' => $r->security?->name,
                'portfolio_action' => method_exists($r, 'portfolioAction') ? $r->portfolioAction() : $r->recommendation_type,
                'ui_label' => method_exists($r, 'uiLabel') ? $r->uiLabel() : $r->recommendation_type,
                'reserved_amount' => round((float) $r->reserved_amount, 4),
                'suggested_quantity' => method_exists($r, 'suggestedQuantity') ? $r->suggestedQuantity() : null,
                'reference_price' => $r->reference_price !== null ? (float) $r->reference_price : null,
                'reserved_at' => optional($r->reserved_at)?->toIso8601String(),
                'approved_at' => optional($r->approved_at)?->toIso8601String(),
                'status' => $r->status,
            ];
        })->values()->all();
    }

    public function deposit(
        PortfolioProfile $profile,
        float $amount,
        ?string $reason = null,
        ?User $user = null,
        ?string $entryDate = null,
    ): CashLedgerEntry {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Deposit amount must be positive.']]);
        }

        return $this->post(
            $profile,
            CashLedgerEntry::TYPE_DEPOSIT,
            $amount,
            $reason,
            $user,
            null,
            null,
            $entryDate,
        );
    }

    public function withdraw(
        PortfolioProfile $profile,
        float $amount,
        ?string $reason = null,
        ?User $user = null,
        ?string $entryDate = null,
    ): CashLedgerEntry {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Withdrawal amount must be positive.']]);
        }

        $available = $this->availableInvestableCash($profile);
        if ($amount > $available + 0.0001) {
            throw ValidationException::withMessages([
                'amount' => ['Withdrawal cannot exceed available cash (₹'.number_format($available, 0, '.', ',').').'],
            ]);
        }

        return $this->post(
            $profile,
            CashLedgerEntry::TYPE_WITHDRAWAL,
            -abs($amount),
            $reason,
            $user,
            null,
            null,
            $entryDate,
        );
    }

    public function adjust(
        PortfolioProfile $profile,
        float $amount,
        ?string $reason = null,
        ?User $user = null,
        ?string $entryDate = null,
    ): CashLedgerEntry {
        if ($amount == 0.0) {
            throw ValidationException::withMessages(['amount' => ['Adjustment amount cannot be zero.']]);
        }

        return $this->post(
            $profile,
            CashLedgerEntry::TYPE_ADJUSTMENT,
            $amount,
            $reason,
            $user,
            null,
            null,
            $entryDate,
        );
    }

    /**
     * V4-SPEC-004: signed LOAN / RECALL / BRIDGE. Positive enters trading cash; negative leaves.
     * Optional reason is human context only. Does not invent directional *_IN / *_OUT types.
     */
    public function postLoan(
        PortfolioProfile $profile,
        float $signedAmount,
        ?string $reason = null,
        ?User $user = null,
        ?string $entryDate = null,
        ?int $transactionId = null,
        ?int $recommendationId = null,
    ): CashLedgerEntry {
        return $this->postSpecialMovement(
            $profile,
            CashLedgerEntry::TYPE_LOAN,
            $signedAmount,
            $reason,
            $user,
            $entryDate,
            $transactionId,
            $recommendationId,
        );
    }

    public function postRecall(
        PortfolioProfile $profile,
        float $signedAmount,
        ?string $reason = null,
        ?User $user = null,
        ?string $entryDate = null,
        ?int $transactionId = null,
        ?int $recommendationId = null,
    ): CashLedgerEntry {
        return $this->postSpecialMovement(
            $profile,
            CashLedgerEntry::TYPE_RECALL,
            $signedAmount,
            $reason,
            $user,
            $entryDate,
            $transactionId,
            $recommendationId,
        );
    }

    public function postBridge(
        PortfolioProfile $profile,
        float $signedAmount,
        ?string $reason = null,
        ?User $user = null,
        ?string $entryDate = null,
        ?int $transactionId = null,
        ?int $recommendationId = null,
    ): CashLedgerEntry {
        return $this->postSpecialMovement(
            $profile,
            CashLedgerEntry::TYPE_BRIDGE,
            $signedAmount,
            $reason,
            $user,
            $entryDate,
            $transactionId,
            $recommendationId,
        );
    }

    public function postSpecialMovement(
        PortfolioProfile $profile,
        string $type,
        float $signedAmount,
        ?string $reason = null,
        ?User $user = null,
        ?string $entryDate = null,
        ?int $transactionId = null,
        ?int $recommendationId = null,
    ): CashLedgerEntry {
        if (! in_array($type, CashLedgerEntry::SPECIAL_TYPES, true)) {
            throw ValidationException::withMessages([
                'entry_type' => ['Special cash movement type must be loan, recall, or bridge.'],
            ]);
        }
        if (round($signedAmount, 4) == 0.0) {
            throw ValidationException::withMessages([
                'amount' => ['Special cash movement amount cannot be zero.'],
            ]);
        }

        return $this->post(
            $profile,
            $type,
            round($signedAmount, 4),
            $reason,
            $user,
            $transactionId,
            $recommendationId,
            $entryDate,
        );
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
        $entryDate = $transaction->transaction_date
            ? \Carbon\Carbon::parse($transaction->transaction_date)->toDateString()
            : null;

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
                $entryDate,
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
                $entryDate,
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
            now()->toDateString(),
        );
    }

    /**
     * @return list<CashLedgerEntry>
     */
    public function recentEntries(PortfolioProfile $profile, int $limit = 50): array
    {
        return CashLedgerEntry::query()
            ->where('profile_id', $profile->id)
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    protected function post(
        PortfolioProfile $profile,
        string $type,
        float $signedAmount,
        ?string $reason = null,
        ?User $user = null,
        ?int $transactionId = null,
        ?int $recommendationId = null,
        ?string $entryDate = null,
    ): CashLedgerEntry {
        return DB::transaction(function () use (
            $profile,
            $type,
            $signedAmount,
            $reason,
            $user,
            $transactionId,
            $recommendationId,
            $entryDate,
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

            $resolvedDate = $entryDate
                ? \Carbon\Carbon::parse($entryDate)->toDateString()
                : now()->toDateString();

            $trimmedReason = $reason !== null ? trim($reason) : '';

            return CashLedgerEntry::query()->create([
                'profile_id' => $profile->id,
                'entry_type' => $type,
                'amount' => $signedAmount,
                'balance_after' => (float) $account->balance,
                'reason' => $trimmedReason !== '' ? $trimmedReason : null,
                'entry_date' => $resolvedDate,
                'transaction_id' => $transactionId,
                'recommendation_id' => $recommendationId,
                'user_id' => $user?->id,
                'created_at' => now(),
            ]);
        });
    }
}
