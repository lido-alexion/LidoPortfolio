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
}
