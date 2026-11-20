<?php

namespace Tests\Feature;

use App\Models\CashLedgerEntry;
use App\Models\PortfolioProfile;
use App\Models\User;
use App\Services\CashManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashDerivedOpeningAssumptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_derived_opening_is_classified_and_protected_from_normal_cash_edits(): void
    {
        $user = User::query()->create(['name' => 'Cash owner', 'email' => 'cash-'.Str::random(8).'@example.com', 'password' => 'password123']);
        $profile = $this->defaultPortfolioFor($user);
        $entry = app(CashManagementService::class)->deposit($profile, 100, 'Derived opening assumption from source proof', $user);
        $entry->forceFill(['entry_classification' => 'derived_opening_assumption'])->save();

        try {
            app(CashManagementService::class)->amendCashMovement($profile, $entry->id, '101', 'attempted edit', $user);
            $this->fail('Derived opening assumptions must not be edited through normal cash workflows.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('guarded cleanup or rollback', $error->getMessage());
        }
        try {
            app(CashManagementService::class)->reverseCashMovement($profile, $entry->id, 'attempted delete', $user);
            $this->fail('Derived opening assumptions must not be reversed through normal cash workflows.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('guarded cleanup or rollback', $error->getMessage());
        }

        $this->assertDatabaseHas('portfolio_cash_ledger_entries', ['id' => $entry->id, 'entry_classification' => 'derived_opening_assumption']);
        $this->assertEqualsWithDelta(100, app(CashManagementService::class)->balance($profile), 0.0001);
    }
}
