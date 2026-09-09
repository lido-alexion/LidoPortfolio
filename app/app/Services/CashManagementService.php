<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashLedgerEntry;
use App\Models\PortfolioProfile;
use App\Models\TradingRecommendation;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
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

        if (trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => ['Adjustment reason is required.']]);
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
            ? Carbon::parse($transaction->transaction_date)->toDateString()
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

    public function balanceAsOf(PortfolioProfile $profile, string $date): float
    {
        return (float) ($this->cashAsOf($profile, $date)['balance'] ?? 0.0);
    }

    /** @return array{balance: ?float, complete: bool, complete_from: ?string, reason: ?string} */
    public function cashAsOf(PortfolioProfile $profile, string $date): array
    {
        $asOf = CarbonImmutable::parse($date)->toDateString();
        $firstRecorded = CashLedgerEntry::query()
            ->where('profile_id', $profile->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if ($firstRecorded === null) {
            $cached = $this->balance($profile);

            return $cached == 0.0
                ? ['balance' => 0.0, 'complete' => true, 'complete_from' => null, 'reason' => null]
                : ['balance' => null, 'complete' => false, 'complete_from' => null, 'reason' => 'opening_balance_unknown'];
        }

        $openingBeforeLedger = round((float) $firstRecorded->balance_after - (float) $firstRecorded->amount, 4);
        if (abs($openingBeforeLedger) > 0.0001) {
            return [
                'balance' => null,
                'complete' => false,
                'complete_from' => optional($firstRecorded->entry_date)?->toDateString(),
                'reason' => 'opening_balance_unknown',
            ];
        }

        return [
            'balance' => round((float) CashLedgerEntry::query()
                ->where('profile_id', $profile->id)
                ->whereDate('entry_date', '<=', $asOf)
                ->sum('amount'), 4),
            'complete' => true,
            'complete_from' => optional($firstRecorded->entry_date)?->toDateString(),
            'reason' => null,
        ];
    }

    /**
     * Effective-date cash statement. Created timestamps remain visible audit evidence,
     * while the running balance follows financial effective-date order.
     *
     * @return array<string, mixed>
     */
    public function statement(
        PortfolioProfile $profile,
        ?string $from = null,
        ?string $to = null,
        int $page = 1,
        int $perPage = 50,
        ?string $type = null,
    ): array {
        $fromDate = $from ? CarbonImmutable::parse($from)->toDateString() : null;
        $toDate = $to ? CarbonImmutable::parse($to)->toDateString() : now()->toDateString();
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $cashState = $this->cashAsOf($profile, $toDate);

        $opening = $cashState['complete'] && $fromDate
            ? round((float) CashLedgerEntry::query()
                ->where('profile_id', $profile->id)
                ->whereDate('entry_date', '<', $fromDate)
                ->sum('amount'), 4)
            : ($cashState['complete'] ? 0.0 : null);

        $query = CashLedgerEntry::query()
            ->where('profile_id', $profile->id)
            ->when($fromDate, fn ($q) => $q->whereDate('entry_date', '>=', $fromDate))
            ->whereDate('entry_date', '<=', $toDate)
            ->when($type, fn ($q) => $q->where('entry_type', $type))
            ->orderBy('entry_date')
            ->orderBy('created_at')
            ->orderBy('id');

        $total = (clone $query)->count();
        $entries = $query->forPage($page, $perPage)->get()->map(function (CashLedgerEntry $entry) use ($profile, $cashState) {
            $running = $cashState['complete'] ? round((float) CashLedgerEntry::query()
                ->where('profile_id', $profile->id)
                ->where(function ($query) use ($entry) {
                    $query->whereDate('entry_date', '<', $entry->entry_date)
                        ->orWhere(function ($sameDate) use ($entry) {
                            $sameDate->whereDate('entry_date', $entry->entry_date)
                                ->where(function ($ordered) use ($entry) {
                                    $ordered->where('created_at', '<', $entry->created_at)
                                        ->orWhere(function ($sameTimestamp) use ($entry) {
                                            $sameTimestamp->where('created_at', $entry->created_at)
                                                ->where('id', '<=', $entry->id);
                                        });
                                });
                        });
                })
                ->sum('amount'), 4) : null;

            return [
                'id' => $entry->id,
                'entry_type' => $entry->entry_type,
                'category' => match ($entry->entry_type) {
                    CashLedgerEntry::TYPE_DEPOSIT, CashLedgerEntry::TYPE_WITHDRAWAL => 'external_flow',
                    CashLedgerEntry::TYPE_ADJUSTMENT => 'adjustment',
                    CashLedgerEntry::TYPE_BUY, CashLedgerEntry::TYPE_SELL => 'trade',
                    default => 'internal',
                },
                'amount' => (float) $entry->amount,
                'running_balance' => $running,
                'reason' => $entry->reason,
                'entry_date' => optional($entry->entry_date)?->toDateString(),
                'transaction_id' => $entry->transaction_id,
                'recommendation_id' => $entry->recommendation_id,
                'user_id' => $entry->user_id,
                'created_at' => optional($entry->created_at)?->toIso8601String(),
            ];
        })->values()->all();

        return [
            'opening_balance' => $opening,
            'closing_balance' => $cashState['balance'],
            'complete' => $cashState['complete'],
            'incomplete_reason' => $cashState['reason'],
            'from' => $fromDate,
            'to' => $toDate,
            'entries' => $entries,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    public function rebuildBalance(PortfolioProfile $profile): float
    {
        return DB::transaction(function () use ($profile) {
            $account = $this->ensureAccount($profile);
            $account = CashAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $balance = round((float) CashLedgerEntry::query()
                ->where('profile_id', $profile->id)
                ->sum('amount'), 4);
            $account->forceFill(['balance' => $balance])->save();

            return $balance;
        });
    }

    public function reverseCashMovement(
        PortfolioProfile $profile,
        int $entryId,
        string $reason,
        ?User $user = null,
        ?string $entryDate = null,
    ): CashLedgerEntry {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Reversal reason is required.']]);
        }

        $entry = CashLedgerEntry::query()->where('profile_id', $profile->id)->find($entryId);
        if ($entry === null) {
            throw ValidationException::withMessages(['entry' => ['Cash ledger entry was not found for this Portfolio.']]);
        }
        if (! in_array($entry->entry_type, [
            CashLedgerEntry::TYPE_DEPOSIT,
            CashLedgerEntry::TYPE_WITHDRAWAL,
            CashLedgerEntry::TYPE_ADJUSTMENT,
        ], true)) {
            throw ValidationException::withMessages(['entry' => ['Trade and internal cash effects must be corrected through their source workflow.']]);
        }
        if (CashLedgerEntry::query()->where('reversal_of_entry_id', $entry->id)->exists()) {
            throw ValidationException::withMessages(['entry' => ['This cash ledger entry has already been reversed.']]);
        }

        return $this->post(
            $profile,
            CashLedgerEntry::TYPE_ADJUSTMENT,
            -1 * (float) $entry->amount,
            'Reversal of cash entry #'.$entry->id.': '.$reason,
            $user,
            null,
            null,
            $entryDate,
            $entry->id,
        );
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
        ?int $reversalOfEntryId = null,
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
            $reversalOfEntryId,
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
                ? Carbon::parse($entryDate)->toDateString()
                : now()->toDateString();

            $trimmedReason = $reason !== null ? trim($reason) : '';

            return CashLedgerEntry::query()->create([
                'profile_id' => $profile->id,
                'entry_type' => $type,
                'amount' => $signedAmount,
                'balance_after' => (float) $account->balance,
                'reason' => $trimmedReason !== '' ? $trimmedReason : null,
                'reversal_of_entry_id' => $reversalOfEntryId,
                'entry_date' => $resolvedDate,
                'transaction_id' => $transactionId,
                'recommendation_id' => $recommendationId,
                'user_id' => $user?->id,
                'created_at' => now(),
            ]);
        });
    }
}
