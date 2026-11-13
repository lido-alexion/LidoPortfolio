<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashLedgerEntry;
use App\Models\User;
use App\Services\CashManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V5CashStatementTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_cash_statement_uses_effective_dates_and_preserves_audit_time(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $cash = app(CashManagementService::class);

        $cash->deposit($profile, 1000, 'Opening funding', $user, '2026-08-01');
        $cash->withdraw($profile, 200, 'Capital returned', $user, '2026-08-03');
        $cash->adjust($profile, 50, 'Broker cash correction', $user, '2026-08-02');

        $this->assertSame(1050.0, $cash->balanceAsOf($profile, '2026-08-02'));
        $this->assertSame(850.0, $cash->balanceAsOf($profile, '2026-08-03'));

        $response = $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson('/api/cash/statement?from=2026-08-02&to=2026-08-03');

        $response->assertOk()
            ->assertJsonPath('data.opening_balance', 1000)
            ->assertJsonPath('data.closing_balance', 850)
            ->assertJsonPath('data.entries.0.entry_type', 'adjustment')
            ->assertJsonPath('data.entries.0.running_balance', 1050)
            ->assertJsonPath('data.entries.1.entry_type', 'withdrawal')
            ->assertJsonPath('data.entries.1.running_balance', 850)
            ->assertJsonPath('data.entries.0.category', 'adjustment');

        $this->assertNotNull($response->json('data.entries.0.created_at'));
    }

    public function test_adjustment_requires_an_immutable_reason_and_future_as_of_is_rejected(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/cash/adjust', ['amount' => 100])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->getJson('/api/cash/as-of?date='.now()->addDay()->toDateString())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    public function test_cached_balance_can_be_rebuilt_from_the_append_only_ledger(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $cash = app(CashManagementService::class);

        $cash->deposit($profile, 500, 'Funding', $user, '2026-08-01');
        CashAccount::query()->where('profile_id', $profile->id)->update(['balance' => 7]);

        $this->assertSame(500.0, $cash->rebuildBalance($profile));
        $this->assertSame(500.0, $cash->balance($profile));
    }

    public function test_unknown_legacy_opening_balance_is_never_reported_as_zero_or_complete(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        CashAccount::query()->updateOrCreate(['profile_id' => $profile->id], ['balance' => 150]);
        CashLedgerEntry::query()->create([
            'profile_id' => $profile->id,
            'entry_type' => CashLedgerEntry::TYPE_ADJUSTMENT,
            'amount' => 50,
            'balance_after' => 150,
            'reason' => 'Legacy imported correction',
            'entry_date' => '2026-08-01',
            'user_id' => $user->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/cash/as-of?date=2026-08-02');

        $response->assertOk()
            ->assertJsonPath('data.cash_balance', null)
            ->assertJsonPath('data.complete', false)
            ->assertJsonPath('data.incomplete_reason', 'opening_balance_unknown');

        $statement = app(CashManagementService::class)->statement($profile, null, '2026-08-02');
        $this->assertNull($statement['opening_balance']);
        $this->assertNull($statement['closing_balance']);
        $this->assertNull($statement['entries'][0]['running_balance']);
    }

    public function test_cash_corrections_are_append_only_traceable_and_cannot_be_reversed_twice(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $entry = app(CashManagementService::class)->deposit($profile, 500, 'Incorrect deposit', $user, '2026-08-01');

        $response = $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/cash/ledger/'.$entry->id.'/reverse', [
                'reason' => 'Duplicate broker entry',
                'entry_date' => '2026-08-02',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.reversal_of_entry_id', $entry->id)
            ->assertJsonPath('data.amount', -500);
        $this->assertDatabaseHas('portfolio_cash_ledger_entries', ['id' => $entry->id, 'amount' => 500]);
        $this->assertSame(0.0, app(CashManagementService::class)->balance($profile));

        $this->postJson('/api/cash/ledger/'.$entry->id.'/reverse', ['reason' => 'Again'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entry');
    }

    public function test_cash_ui_accepts_four_decimal_places_and_edit_delete_append_replayable_corrections(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/cash/deposit', ['amount' => '100.1250', 'remarks' => 'seed', 'transaction_date' => '2026-08-01'])
            ->assertCreated();
        $entry = CashLedgerEntry::query()->where('profile_id', $profile->id)->firstOrFail();
        $this->assertSame('100.1250', $entry->amount);

        $this->putJson('/api/cash/ledger/'.$entry->id, ['amount' => '120.2501', 'remarks' => 'corrected'])
            ->assertCreated();
        $this->assertSame(120.2501, app(CashManagementService::class)->balance($profile));
        $this->assertDatabaseHas('portfolio_cash_ledger_entries', [
            'profile_id' => $profile->id, 'entry_type' => 'adjustment', 'reversal_of_entry_id' => $entry->id,
        ]);
        $replacement = CashLedgerEntry::query()->where('profile_id', $profile->id)->where('reason', 'corrected')->firstOrFail();
        $this->deleteJson('/api/cash/ledger/'.$replacement->id, ['reason' => 'remove entry'])->assertCreated();
        $this->assertSame(0.0, app(CashManagementService::class)->balance($profile));
        $this->assertSame(0.0, app(CashManagementService::class)->balanceAsOf($profile, '2026-08-01'));
    }

    public function test_manual_entry_mutations_are_profile_scoped_and_trade_entries_stay_owned_by_their_workflow(): void
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        $entry = app(CashManagementService::class)->deposit($profile, '25.5001', 'owner deposit', $owner);
        $otherUser = User::factory()->create();
        $otherProfile = $this->defaultPortfolioFor($otherUser);

        $this->actingAs($otherUser)->withProfileHeader($otherUser, $otherProfile)
            ->deleteJson('/api/cash/ledger/'.$entry->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entry');
        $this->assertSame(25.5001, app(CashManagementService::class)->balance($profile));

        $trade = CashLedgerEntry::query()->create([
            'profile_id' => $profile->id,
            'entry_type' => CashLedgerEntry::TYPE_BUY,
            'amount' => -5,
            'balance_after' => 20.5001,
            'reason' => 'Source transaction cash effect',
            'entry_date' => '2026-08-01',
            'user_id' => $owner->id,
            'created_at' => now(),
        ]);
        $this->actingAs($owner)->withProfileHeader($owner, $profile)
            ->putJson('/api/cash/ledger/'.$trade->id, ['amount' => '8.00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entry');
        $this->assertDatabaseHas('portfolio_cash_ledger_entries', ['id' => $trade->id, 'amount' => -5]);
    }

    public function test_failed_withdrawal_amendment_rolls_back_reversal_and_preserves_old_available_cash(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $service = app(CashManagementService::class);
        $service->deposit($profile, '10.0001', 'seed', $user);
        $withdrawal = $service->withdraw($profile, '6.0001', 'original withdrawal', $user);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->putJson('/api/cash/ledger/'.$withdrawal->id, ['amount' => '11.0000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertSame(4.0, $service->balance($profile));
        $this->assertDatabaseMissing('portfolio_cash_ledger_entries', ['reversal_of_entry_id' => $withdrawal->id]);
        $this->assertSame('-6.0001', $withdrawal->fresh()->amount);
    }

    public function test_withdrawal_amendment_can_increase_amount_within_restored_cash_capacity(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $service = app(CashManagementService::class);
        $service->deposit($profile, '100.0000', 'seed', $user);
        $withdrawal = $service->withdraw($profile, '60.0000', 'original withdrawal', $user);

        $response = $this->actingAs($user)->withProfileHeader($user, $profile)
            ->putJson('/api/cash/ledger/'.$withdrawal->id, ['amount' => '70.0001']);

        $response->assertCreated()->assertJsonPath('data.amount', '-70.0001');
        $this->assertSame(29.9999, $service->balance($profile));
    }

    public function test_cash_reversal_preserves_four_decimal_amount_exactly(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $service = app(CashManagementService::class);
        $entry = $service->deposit($profile, '0.1001', 'precise seed', $user);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->deleteJson('/api/cash/ledger/'.$entry->id)
            ->assertCreated();

        $this->assertDatabaseHas('portfolio_cash_ledger_entries', [
            'profile_id' => $profile->id, 'reversal_of_entry_id' => $entry->id, 'amount' => '-0.1001',
        ]);
        $this->assertSame(0.0, $service->balance($profile));
    }

    public function test_partially_spent_deposit_can_be_amended_when_final_balance_remains_nonnegative(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $service = app(CashManagementService::class);
        $deposit = $service->deposit($profile, '100.0000', 'seed', $user, '2026-08-01');
        $service->adjust($profile, '-80.0000', 'Buy', $user, '2026-08-02');

        $response = $this->actingAs($user)->withProfileHeader($user, $profile)
            ->putJson('/api/cash/ledger/'.$deposit->id, [
                'amount' => '90.0000', 'remarks' => 'corrected', 'entry_date' => '2026-08-03',
            ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('entry_date');
        $this->assertSame(20.0, $service->balance($profile));
        $this->assertSame(20.0, $service->balanceAsOf($profile, '2026-08-02'));
        $this->assertSame(20.0, $service->balanceAsOf($profile, '2026-08-03'));
        $this->assertSame(2, CashLedgerEntry::query()->where('profile_id', $profile->id)->count());
        $this->assertSame('100.0000', $deposit->fresh()->amount);
    }

    public function test_same_date_amount_correction_rejects_negative_intermediate_daily_balance_atomically(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $service = app(CashManagementService::class);
        $deposit = $service->deposit($profile, '100.0000', 'seed', $user, '2026-08-01');
        $service->withdraw($profile, '90.0000', 'spent', $user, '2026-08-02');
        $service->deposit($profile, '100.0000', 'later funding', $user, '2026-08-03');

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->putJson('/api/cash/ledger/'.$deposit->id, ['amount' => '80.0000'])
            ->assertUnprocessable()->assertJsonValidationErrors('entry_date');

        $this->assertSame(110.0, $service->balance($profile));
        $this->assertSame(100.0, $service->balanceAsOf($profile, '2026-08-01'));
        $this->assertSame(10.0, $service->balanceAsOf($profile, '2026-08-02'));
        $this->assertSame(110.0, $service->balanceAsOf($profile, '2026-08-03'));
        $this->assertSame(3, CashLedgerEntry::query()->where('profile_id', $profile->id)->count());
        $this->assertSame('100.0000', $deposit->fresh()->amount);
        $this->assertDatabaseMissing('portfolio_cash_ledger_entries', ['reversal_of_entry_id' => $deposit->id]);
    }

    public function test_same_date_amount_correction_is_allowed_when_daily_balances_remain_nonnegative(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $service = app(CashManagementService::class);
        $deposit = $service->deposit($profile, '100.0000', 'seed', $user, '2026-08-01');
        $service->withdraw($profile, '20.0000', 'spent', $user, '2026-08-02');

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->putJson('/api/cash/ledger/'.$deposit->id, ['amount' => '90.0000'])
            ->assertCreated();

        $this->assertSame(70.0, $service->balance($profile));
        $this->assertSame(90.0, $service->balanceAsOf($profile, '2026-08-01'));
        $this->assertSame(70.0, $service->balanceAsOf($profile, '2026-08-02'));
    }

    public function test_amendment_date_change_removes_original_effect_from_old_date_and_adds_replacement_on_new_date(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $service = app(CashManagementService::class);
        $deposit = $service->deposit($profile, '100.0000', 'seed', $user, '2026-08-01');

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->putJson('/api/cash/ledger/'.$deposit->id, [
                'amount' => '90.0000', 'remarks' => 'corrected', 'entry_date' => '2026-08-03',
            ])->assertCreated();

        $this->assertSame(0.0, $service->balanceAsOf($profile, '2026-08-01'));
        $this->assertSame(0.0, $service->balanceAsOf($profile, '2026-08-02'));
        $this->assertSame(90.0, $service->balanceAsOf($profile, '2026-08-03'));
        $statement = $service->statement($profile, '2026-08-01', '2026-08-03');
        $this->assertSame(90.0, $statement['closing_balance']);
        $this->assertSame('2026-08-01', $statement['entries'][1]['entry_date']);
        $this->assertSame('2026-08-03', $statement['entries'][2]['entry_date']);
        $this->assertSame($deposit->id, $statement['entries'][1]['reversal_of_entry_id']);
    }

    public function test_raw_reversal_api_rejects_reversal_chain_and_duplicate_amendments_cleanly(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $entry = app(CashManagementService::class)->deposit($profile, '25.0000', 'seed', $user);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/cash/ledger/'.$entry->id.'/reverse', ['reason' => 'first'])
            ->assertCreated();
        $reversal = CashLedgerEntry::query()->where('reversal_of_entry_id', $entry->id)->firstOrFail();
        $this->postJson('/api/cash/ledger/'.$reversal->id.'/reverse', ['reason' => 'chain'])
            ->assertUnprocessable()->assertJsonValidationErrors('entry');
        $this->putJson('/api/cash/ledger/'.$entry->id, ['amount' => '30.0000'])
            ->assertUnprocessable()->assertJsonValidationErrors('entry');
        $this->assertSame(2, CashLedgerEntry::query()->where('profile_id', $profile->id)->count());
    }
}
