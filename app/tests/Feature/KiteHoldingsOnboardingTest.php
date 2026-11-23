<?php

namespace Tests\Feature;

use App\Models\BrokerConnection;
use App\Models\Stock;
use App\Models\User;
use App\Services\Broker\FakeBrokerGateway;
use App\Services\TransactionWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KiteHoldingsOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_staging_is_persistent_and_verify_is_separate_from_commit(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        Stock::query()->create(['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'name' => 'Onboard', 'is_active' => true, 'is_benchmark' => false]);
        $gateway = app(FakeBrokerGateway::class);
        $gateway->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'quantity' => 5, 'average_price' => 100]], 'positions' => [], 'current_cash' => 0];
        $response = $this->startOnboarding($user, $profile)->assertCreated();
        $item = $response->json('data.stocks.0');
        $this->assertSame('needs_review', $item['status']);
        $this->assertTrue($item['entries'][0]['date_placeholder']);
        $this->getJson('/api/kite/holdings-onboarding')->assertOk()->assertJsonPath('data.stocks.0.id', $item['id']);
        $this->postJson('/api/kite/holdings-onboarding/stocks/'.$item['id'].'/verify')->assertUnprocessable();
        $this->putJson('/api/kite/holdings-onboarding/stocks/'.$item['id'], ['entries' => [['type' => 'buy', 'quantity' => 5, 'price' => 100, 'date' => '2026-11-21', 'date_placeholder' => false, 'provenance' => 'manual']]])->assertOk();
        $this->postJson('/api/kite/holdings-onboarding/stocks/'.$item['id'].'/verify')->assertOk()->assertJsonPath('data.stocks.0.status', 'verified');
        $this->assertDatabaseCount('portfolio_transactions', 0);
    }

    public function test_verify_rejects_uncovered_sales_and_cost_basis_mismatch(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        Stock::query()->create(['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'name' => 'Onboard', 'is_active' => true, 'is_benchmark' => false]);
        $gateway = app(FakeBrokerGateway::class);
        $gateway->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'quantity' => 5, 'average_price' => 100]], 'positions' => [], 'current_cash' => 0];
        $response = $this->startOnboarding($user, $profile)->assertCreated();
        $item = $response->json('data.stocks.0');
        $this->putJson('/api/kite/holdings-onboarding/stocks/'.$item['id'], ['entries' => [['type' => 'buy', 'quantity' => 2, 'price' => 100, 'date' => today()->toDateString(), 'provenance' => 'manual'], ['type' => 'sell', 'quantity' => 3, 'price' => 100, 'date' => today()->toDateString(), 'provenance' => 'manual']]])->assertOk();
        $this->postJson('/api/kite/holdings-onboarding/stocks/'.$item['id'].'/verify')->assertUnprocessable();
    }

    public function test_verify_matches_quantity_and_remaining_fifo_cost_after_multiple_lots_and_a_sale(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        Stock::query()->create(['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'name' => 'Onboard', 'is_active' => true, 'is_benchmark' => false]);
        app(FakeBrokerGateway::class)->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'quantity' => 5, 'average_price' => 120]], 'positions' => [], 'current_cash' => 0];
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $item = $this->startOnboarding($user, $profile)->assertCreated()->json('data.stocks.0');
        $entries = [
            ['type' => 'buy', 'quantity' => 10, 'price' => 90, 'date' => '2026-01-01', 'date_placeholder' => false, 'provenance' => 'console_tradebook'],
            ['type' => 'buy', 'quantity' => 5, 'price' => 120, 'date' => '2026-02-01', 'date_placeholder' => false, 'provenance' => 'console_tradebook'],
            ['type' => 'sell', 'quantity' => 10, 'price' => 130, 'date' => '2026-03-01', 'date_placeholder' => false, 'provenance' => 'console_tradebook'],
        ];
        $this->putJson('/api/kite/holdings-onboarding/stocks/'.$item['id'], ['entries' => $entries])->assertOk();
        $this->postJson('/api/kite/holdings-onboarding/stocks/'.$item['id'].'/verify')->assertOk()->assertJsonPath('data.stocks.0.status', 'verified');

        $entries[1]['price'] = 125;
        $this->putJson('/api/kite/holdings-onboarding/stocks/'.$item['id'], ['entries' => $entries])->assertOk();
        $this->postJson('/api/kite/holdings-onboarding/stocks/'.$item['id'].'/verify')->assertUnprocessable();
    }

    public function test_csv_tradebook_is_strict_and_repeated_imports_are_idempotent(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        Stock::query()->create(['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'name' => 'Onboard', 'is_active' => true, 'is_benchmark' => false]);
        app(FakeBrokerGateway::class)->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'quantity' => 5, 'average_price' => 100]], 'positions' => [], 'current_cash' => 0];
        $this->startOnboarding($user, $profile)->assertCreated();
        $csv = "symbol,trade_type,quantity,price,trade_date,trade_id\nONBOARD,BUY,5,100,2026-11-21,T-1\n";
        $this->post('/api/kite/holdings-onboarding/tradebook', ['tradebook' => UploadedFile::fake()->createWithContent('trades.csv', $csv)])->assertOk()->assertJsonPath('merged_stocks', 1);
        $this->post('/api/kite/holdings-onboarding/tradebook', ['tradebook' => UploadedFile::fake()->createWithContent('trades.csv', $csv)])->assertOk();
        $draft = $this->getJson('/api/kite/holdings-onboarding')->assertOk()->json('data.stocks.0.entries');
        $this->assertCount(1, $draft);
        $invalid = "symbol,trade_type,quantity,price,trade_date\nONBOARD,BUY,not-a-number,100,2026-11-21\n";
        $this->post('/api/kite/holdings-onboarding/tradebook', ['tradebook' => UploadedFile::fake()->createWithContent('bad.csv', $invalid)])->assertUnprocessable();
    }

    public function test_multiple_period_imports_deduplicate_and_preserve_user_dated_opening_entries(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        Stock::query()->create(['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'name' => 'Onboard', 'is_active' => true, 'is_benchmark' => false]);
        app(FakeBrokerGateway::class)->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'quantity' => 5, 'average_price' => 100]], 'positions' => [], 'current_cash' => 0];
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $item = $this->startOnboarding($user, $profile)->assertCreated()->json('data.stocks.0');
        $opening = [['type' => 'buy', 'quantity' => 5, 'price' => 100, 'date' => '2026-01-01', 'date_placeholder' => false, 'provenance' => 'kite_opening_position']];
        $this->putJson('/api/kite/holdings-onboarding/stocks/'.$item['id'], ['entries' => $opening])->assertOk();
        $periodOne = "symbol,trade_type,quantity,price,trade_date\nONBOARD,BUY,2,90,2026-02-01\n";
        $periodTwo = "symbol,trade_type,quantity,price,trade_date\nONBOARD,BUY,2,90,2026-02-01\nONBOARD,SELL,1,110,2026-03-01\n";
        $this->post('/api/kite/holdings-onboarding/tradebook', ['tradebook' => UploadedFile::fake()->createWithContent('period-one.csv', $periodOne)])->assertOk();
        $this->post('/api/kite/holdings-onboarding/tradebook', ['tradebook' => UploadedFile::fake()->createWithContent('period-two.csv', $periodTwo)])->assertOk();
        $entries = $this->getJson('/api/kite/holdings-onboarding')->assertOk()->json('data.stocks.0.entries');
        $this->assertCount(3, $entries);
        $this->assertSame('kite_opening_position', $entries[0]['provenance']);
        $this->assertSame(['buy', 'sell'], array_column(array_slice($entries, 1), 'type'));
    }

    public function test_xlsx_value_only_console_export_is_imported_and_formula_cells_are_rejected(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('Zip extension unavailable.');
        }
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        Stock::query()->create(['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'name' => 'Onboard', 'is_active' => true, 'is_benchmark' => false]);
        app(FakeBrokerGateway::class)->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'quantity' => 5, 'average_price' => 100]], 'positions' => [], 'current_cash' => 0];
        $this->startOnboarding($user, $profile)->assertCreated();
        $this->post('/api/kite/holdings-onboarding/tradebook', ['tradebook' => $this->xlsx(['symbol', 'trade_type', 'quantity', 'price', 'trade_date', 'trade_id'], ['ONBOARD', 'BUY', '5', '100', '2026-11-21', 'X-1'])])->assertOk();
        $this->assertSame('X-1', $this->getJson('/api/kite/holdings-onboarding')->json('data.stocks.0.entries.0.broker_trade_id'));
        $this->post('/api/kite/holdings-onboarding/tradebook', ['tradebook' => $this->xlsx(['symbol', 'trade_type', 'quantity', 'price', 'trade_date'], ['ONBOARD', 'BUY', '5', '100', '2026-11-21'], true)])->assertUnprocessable();
    }

    public function test_excel_2003_xml_xls_tradebook_is_supported(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        Stock::query()->create(['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'name' => 'Onboard', 'is_active' => true, 'is_benchmark' => false]);
        app(FakeBrokerGateway::class)->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'quantity' => 5, 'average_price' => 100]], 'positions' => [], 'current_cash' => 0];
        $this->startOnboarding($user, $profile)->assertCreated();
        $xml = '<?xml version="1.0"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet><Table><Row><Cell><Data>symbol</Data></Cell><Cell><Data>trade_type</Data></Cell><Cell><Data>quantity</Data></Cell><Cell><Data>price</Data></Cell><Cell><Data>trade_date</Data></Cell></Row><Row><Cell><Data>ONBOARD</Data></Cell><Cell><Data>BUY</Data></Cell><Cell><Data>5</Data></Cell><Cell><Data>100</Data></Cell><Cell><Data>2026-11-21</Data></Cell></Row></Table></Worksheet></Workbook>';
        $this->post('/api/kite/holdings-onboarding/tradebook', ['tradebook' => UploadedFile::fake()->createWithContent('trades.xls', $xml)])->assertOk();
        $this->assertSame('console_tradebook', $this->getJson('/api/kite/holdings-onboarding')->json('data.stocks.0.entries.0.provenance'));
        $formulaXml = str_replace('<Cell><Data>5</Data>', '<Cell ss:Formula="=1+1"><Data>5</Data>', $xml);
        $this->post('/api/kite/holdings-onboarding/tradebook', ['tradebook' => UploadedFile::fake()->createWithContent('formula.xls', $formulaXml)])->assertUnprocessable();
    }

    public function test_legacy_binary_xls_is_rejected_with_xlsx_resave_instructions(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user)->withProfileHeader($user, $profile);
        app(FakeBrokerGateway::class)->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [], 'positions' => [], 'current_cash' => 0];
        $this->startOnboarding($user, $profile)->assertCreated();
        $binaryXls = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".'legacy workbook bytes';

        $response = $this->post('/api/kite/holdings-onboarding/tradebook', ['tradebook' => UploadedFile::fake()->createWithContent('legacy.xls', $binaryXls)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tradebook']);
        $this->assertStringContainsString('Save As to create an .xlsx copy', $response->getContent());
    }

    public function test_verified_tradebook_entries_commit_once_and_recheck_kite(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        Stock::query()->create(['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'name' => 'Onboard', 'is_active' => true, 'is_benchmark' => false]);
        $gateway = app(FakeBrokerGateway::class);
        $gateway->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'quantity' => 5, 'average_price' => 100]], 'positions' => [], 'current_cash' => 0];
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $item = $this->startOnboarding($user, $profile)->assertCreated()->json('data.stocks.0');
        $this->putJson('/api/kite/holdings-onboarding/stocks/'.$item['id'], ['entries' => [['type' => 'buy', 'quantity' => 5, 'price' => 100, 'date' => '2026-11-21', 'date_placeholder' => false, 'provenance' => 'console_tradebook', 'broker_trade_id' => 'COMMIT-1']]])->assertOk();
        $this->postJson('/api/kite/holdings-onboarding/stocks/'.$item['id'].'/verify')->assertOk();
        $this->assertDatabaseCount('portfolio_transactions', 0);
        $this->postJson('/api/kite/holdings-onboarding/commit', ['stock_ids' => [$item['id']]])->assertOk()->assertJsonPath('data.stocks.0.status', 'committed');
        $this->assertDatabaseCount('portfolio_transactions', 1);
        $this->postJson('/api/kite/holdings-onboarding/stocks/'.$item['id'].'/verify')->assertStatus(409);
        $this->putJson('/api/kite/holdings-onboarding/stocks/'.$item['id'], ['entries' => [['type' => 'buy', 'quantity' => 5, 'price' => 100, 'date' => '2026-11-21', 'date_placeholder' => false]]])->assertStatus(409);
        $this->postJson('/api/kite/holdings-onboarding/commit', ['stock_ids' => [$item['id']]])->assertStatus(409);
        $this->assertDatabaseCount('portfolio_transactions', 1);
    }

    public function test_commit_rejects_intervening_kite_changes_without_writing(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        Stock::query()->create(['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'name' => 'Onboard', 'is_active' => true, 'is_benchmark' => false]);
        $gateway = app(FakeBrokerGateway::class);
        $gateway->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'quantity' => 5, 'average_price' => 100]], 'positions' => [], 'current_cash' => 0];
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $item = $this->startOnboarding($user, $profile)->assertCreated()->json('data.stocks.0');
        $this->putJson('/api/kite/holdings-onboarding/stocks/'.$item['id'], ['entries' => [['type' => 'buy', 'quantity' => 5, 'price' => 100, 'date' => '2026-11-21', 'date_placeholder' => false, 'provenance' => 'manual']]])->assertOk();
        $this->postJson('/api/kite/holdings-onboarding/stocks/'.$item['id'].'/verify')->assertOk();
        $gateway->reconciliationSnapshot['holdings'][0]['quantity'] = 6;
        $this->postJson('/api/kite/holdings-onboarding/commit', ['stock_ids' => [$item['id']]])->assertStatus(409);
        $this->assertDatabaseCount('portfolio_transactions', 0);
    }

    public function test_commit_rejects_a_different_kite_account_even_when_holdings_match(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        Stock::query()->create(['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'name' => 'Onboard', 'is_active' => true, 'is_benchmark' => false]);
        app(FakeBrokerGateway::class)->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'quantity' => 5, 'average_price' => 100]], 'positions' => [], 'current_cash' => 0];
        $item = $this->startOnboarding($user, $profile)->assertCreated()->json('data.stocks.0');
        $this->putJson('/api/kite/holdings-onboarding/stocks/'.$item['id'], ['entries' => [['type' => 'buy', 'quantity' => 5, 'price' => 100, 'date' => '2026-11-21', 'date_placeholder' => false, 'provenance' => 'manual']]])->assertOk();
        $this->postJson('/api/kite/holdings-onboarding/stocks/'.$item['id'].'/verify')->assertOk();

        BrokerConnection::query()->where('user_id', $user->id)->where('provider', BrokerConnection::PROVIDER_KITE)->update(['broker_user_id' => 'KITE-ACCOUNT-2']);

        $this->postJson('/api/kite/holdings-onboarding/commit', ['stock_ids' => [$item['id']]])
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'Kite account changed since staging. Fetch holdings again and review the draft before committing.']);
        $this->assertDatabaseCount('portfolio_transactions', 0);
        $this->assertDatabaseHas('kite_onboarding_stocks', ['id' => $item['id'], 'status' => 'verified']);
    }

    public function test_legacy_accountless_draft_requires_explicit_recovery_and_preserves_entries(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        Stock::query()->create(['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'name' => 'Onboard', 'is_active' => true, 'is_benchmark' => false]);
        app(FakeBrokerGateway::class)->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'quantity' => 5, 'average_price' => 100]], 'positions' => [], 'current_cash' => 0];
        $item = $this->startOnboarding($user, $profile)->assertCreated()->json('data.stocks.0');
        $manual = [['type' => 'buy', 'quantity' => 5, 'price' => 100, 'date' => '2026-05-04', 'date_placeholder' => false, 'provenance' => 'manual']];
        $this->putJson('/api/kite/holdings-onboarding/stocks/'.$item['id'], ['entries' => $manual])->assertOk();
        $this->postJson('/api/kite/holdings-onboarding/stocks/'.$item['id'].'/verify')->assertOk();
        $draftId = DB::table('kite_onboarding_stocks')->where('id', $item['id'])->value('draft_id');
        DB::table('kite_onboarding_drafts')->where('id', $draftId)->update(['kite_snapshot' => json_encode(['provider' => 'kite'])]);

        $this->actingAs($user)->withProfileHeader($user, $profile)->postJson('/api/kite/holdings-onboarding')->assertStatus(409);
        $this->postJson('/api/kite/holdings-onboarding', ['confirm_legacy_draft' => true])->assertCreated();
        $this->assertSame($manual, $this->getJson('/api/kite/holdings-onboarding')->json('data.stocks.0.entries'));
        $this->assertSame('KITE-ACCOUNT-1', $this->getJson('/api/kite/holdings-onboarding')->json('data.broker_account_id'));
        $this->assertSame('needs_review', $this->getJson('/api/kite/holdings-onboarding')->json('data.stocks.0.status'));
        $this->assertSame('legacy_account_bound_reverification_required', $this->getJson('/api/kite/holdings-onboarding')->json('data.stocks.0.audit.1.event'));
        $this->postJson('/api/kite/holdings-onboarding/commit', ['stock_ids' => [$item['id']]])->assertStatus(409);
        $this->postJson('/api/kite/holdings-onboarding/stocks/'.$item['id'].'/verify')->assertOk();
        $this->postJson('/api/kite/holdings-onboarding/commit', ['stock_ids' => [$item['id']]])->assertOk();
    }

    public function test_same_account_refresh_resumes_without_overwriting_manual_dates_or_partial_status(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        Stock::query()->create(['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'name' => 'Onboard', 'is_active' => true, 'is_benchmark' => false]);
        app(FakeBrokerGateway::class)->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'quantity' => 5, 'average_price' => 100]], 'positions' => [], 'current_cash' => 0];
        $item = $this->startOnboarding($user, $profile)->assertCreated()->json('data.stocks.0');
        $entries = [['type' => 'buy', 'quantity' => 5, 'price' => 100, 'date' => '2026-05-04', 'date_placeholder' => false, 'provenance' => 'manual']];
        $this->putJson('/api/kite/holdings-onboarding/stocks/'.$item['id'], ['entries' => $entries])->assertOk();
        $this->postJson('/api/kite/holdings-onboarding/stocks/'.$item['id'].'/verify')->assertOk();
        $this->startOnboarding($user, $profile)->assertCreated();
        $this->assertSame($entries, $this->getJson('/api/kite/holdings-onboarding')->json('data.stocks.0.entries'));
        $this->assertSame('verified', $this->getJson('/api/kite/holdings-onboarding')->json('data.stocks.0.status'));
    }

    public function test_accountless_legacy_draft_with_committed_history_cannot_be_bound(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        Stock::query()->create(['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'name' => 'Onboard', 'is_active' => true, 'is_benchmark' => false]);
        app(FakeBrokerGateway::class)->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'quantity' => 5, 'average_price' => 100]], 'positions' => [], 'current_cash' => 0];
        $item = $this->startOnboarding($user, $profile)->assertCreated()->json('data.stocks.0');
        $this->putJson('/api/kite/holdings-onboarding/stocks/'.$item['id'], ['entries' => [['type' => 'buy', 'quantity' => 5, 'price' => 100, 'date' => '2026-05-04', 'date_placeholder' => false, 'provenance' => 'manual']]])->assertOk();
        $this->postJson('/api/kite/holdings-onboarding/stocks/'.$item['id'].'/verify')->assertOk();
        $this->postJson('/api/kite/holdings-onboarding/commit', ['stock_ids' => [$item['id']]])->assertOk();
        $draftId = DB::table('kite_onboarding_stocks')->where('id', $item['id'])->value('draft_id');
        DB::table('kite_onboarding_drafts')->where('id', $draftId)->update(['kite_snapshot' => json_encode(['provider' => 'kite'])]);
        $this->postJson('/api/kite/holdings-onboarding', ['confirm_legacy_draft' => true])->assertStatus(409);
        $this->assertSame('committed', $this->getJson('/api/kite/holdings-onboarding')->json('data.stocks.0.status'));
        $this->assertSame(1, DB::table('portfolio_transactions')->count());
    }

    public function test_refresh_rejects_changed_account_without_rebinding_or_mutating_staging(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        Stock::query()->create(['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'name' => 'Onboard', 'is_active' => true, 'is_benchmark' => false]);
        app(FakeBrokerGateway::class)->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'quantity' => 5, 'average_price' => 100]], 'positions' => [], 'current_cash' => 0];
        $item = $this->startOnboarding($user, $profile)->assertCreated()->json('data.stocks.0');
        $entries = [['type' => 'buy', 'quantity' => 5, 'price' => 100, 'date' => '2026-05-04', 'date_placeholder' => false, 'provenance' => 'manual']];
        $this->putJson('/api/kite/holdings-onboarding/stocks/'.$item['id'], ['entries' => $entries])->assertOk();
        BrokerConnection::query()->where('user_id', $user->id)->where('provider', BrokerConnection::PROVIDER_KITE)->update(['broker_user_id' => 'KITE-ACCOUNT-2']);
        $this->actingAs($user)->withProfileHeader($user, $profile)->postJson('/api/kite/holdings-onboarding')->assertStatus(409);
        $this->assertSame($entries, $this->getJson('/api/kite/holdings-onboarding')->json('data.stocks.0.entries'));
        $this->assertSame('KITE-ACCOUNT-1', $this->getJson('/api/kite/holdings-onboarding')->json('data.broker_account_id'));
    }

    public function test_selected_stock_can_be_committed_while_other_stocks_remain_staged(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        foreach (['ONBOARD1', 'ONBOARD2'] as $symbol) {
            Stock::query()->create(['symbol' => $symbol, 'exchange' => 'NSE', 'name' => $symbol, 'is_active' => true, 'is_benchmark' => false]);
        }
        app(FakeBrokerGateway::class)->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [
            ['symbol' => 'ONBOARD1', 'exchange' => 'NSE', 'quantity' => 2, 'average_price' => 50],
            ['symbol' => 'ONBOARD2', 'exchange' => 'NSE', 'quantity' => 3, 'average_price' => 70],
        ], 'positions' => [], 'current_cash' => 0];
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $items = $this->startOnboarding($user, $profile)->assertCreated()->json('data.stocks');
        $selected = collect($items)->firstWhere('symbol', 'ONBOARD1');
        $this->putJson('/api/kite/holdings-onboarding/stocks/'.$selected['id'], ['entries' => [['type' => 'buy', 'quantity' => 2, 'price' => 50, 'date' => '2026-11-21', 'date_placeholder' => false, 'provenance' => 'manual']]])->assertOk();
        $this->postJson('/api/kite/holdings-onboarding/stocks/'.$selected['id'].'/verify')->assertOk();

        $this->postJson('/api/kite/holdings-onboarding/commit', ['stock_ids' => [$selected['id']]])->assertOk();
        $states = $this->getJson('/api/kite/holdings-onboarding')->assertOk()->json('data.stocks');
        $this->assertSame('committed', collect($states)->firstWhere('symbol', 'ONBOARD1')['status']);
        $this->assertSame('needs_review', collect($states)->firstWhere('symbol', 'ONBOARD2')['status']);
        $this->assertDatabaseCount('portfolio_transactions', 1);
    }

    public function test_existing_profile_transaction_history_blocks_onboarding_commit(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create(['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'name' => 'Onboard', 'is_active' => true, 'is_benchmark' => false]);
        app(FakeBrokerGateway::class)->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'quantity' => 5, 'average_price' => 100]], 'positions' => [], 'current_cash' => 0];
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $item = $this->startOnboarding($user, $profile)->assertCreated()->json('data.stocks.0');
        $this->putJson('/api/kite/holdings-onboarding/stocks/'.$item['id'], ['entries' => [['type' => 'buy', 'quantity' => 5, 'price' => 100, 'date' => '2026-11-21', 'date_placeholder' => false, 'provenance' => 'manual']]])->assertOk();
        $this->postJson('/api/kite/holdings-onboarding/stocks/'.$item['id'].'/verify')->assertOk();
        DB::table('portfolio_transactions')->insert(['profile_id' => $profile->id, 'stock_id' => $stock->id, 'execution_exchange' => 'NSE', 'type' => 'buy', 'quantity' => 1, 'price' => 100, 'fees' => 0, 'transaction_date' => '2026-01-01', 'source' => 'manual', 'notes' => 'Existing StoX trade', 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson('/api/kite/holdings-onboarding/commit', ['stock_ids' => [$item['id']]])->assertStatus(409)->assertJsonFragment(['message' => 'ONBOARD already has StoX transaction history. Reconcile it before onboarding; no existing holdings were changed.']);
        $this->assertDatabaseCount('portfolio_transactions', 1);
        $this->assertDatabaseHas('kite_onboarding_stocks', ['id' => $item['id'], 'status' => 'verified']);
    }

    public function test_another_profile_cannot_read_or_edit_this_profiles_draft(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $other = $this->createPortfolioProfile($user, 'Second', false);
        Stock::query()->create(['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'name' => 'Onboard', 'is_active' => true, 'is_benchmark' => false]);
        app(FakeBrokerGateway::class)->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [['symbol' => 'ONBOARD', 'exchange' => 'NSE', 'quantity' => 5, 'average_price' => 100]], 'positions' => [], 'current_cash' => 0];
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $item = $this->startOnboarding($user, $profile)->assertCreated()->json('data.stocks.0');

        $this->withProfileHeader($user, $other)->getJson('/api/kite/holdings-onboarding')->assertOk()->assertJsonPath('data', null);
        $this->putJson('/api/kite/holdings-onboarding/stocks/'.$item['id'], ['entries' => [['type' => 'buy', 'quantity' => 5, 'price' => 100, 'date' => '2026-11-21']]])->assertNotFound();
    }

    public function test_commit_rolls_back_ledger_write_and_status_when_transaction_creation_fails(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        Stock::query()->create(['symbol' => 'ONBOARD1', 'exchange' => 'NSE', 'name' => 'First', 'is_active' => true, 'is_benchmark' => false]);
        $gateway = app(FakeBrokerGateway::class);
        $gateway->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [['symbol' => 'ONBOARD1', 'exchange' => 'NSE', 'quantity' => 2, 'average_price' => 50]], 'positions' => [], 'current_cash' => 0];
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $items = $this->startOnboarding($user, $profile)->assertCreated()->json('data.stocks');
        $this->assertCount(1, $items);
        foreach ($items as $item) {
            $this->putJson('/api/kite/holdings-onboarding/stocks/'.$item['id'], ['entries' => [['type' => 'buy', 'quantity' => $item['kite_quantity'], 'price' => $item['kite_average_price'], 'date' => '2026-11-21', 'date_placeholder' => false, 'provenance' => 'manual']]])->assertOk();
            $this->postJson('/api/kite/holdings-onboarding/stocks/'.$item['id'].'/verify')->assertOk();
        }

        $calls = 0;
        $writes = \Mockery::mock(TransactionWriteService::class);
        $writes->shouldReceive('createFinancialUnit')->once()->andReturnUsing(function ($writeProfile, $stock, $input) use (&$calls) {
            $calls++;
            DB::table('portfolio_transactions')->insert(['profile_id' => $writeProfile->id, 'stock_id' => $stock->id, 'execution_exchange' => 'NSE', 'type' => $input['type'], 'quantity' => $input['quantity'], 'price' => $input['price'], 'fees' => 0, 'transaction_date' => $input['transaction_date'], 'source' => $input['source'], 'notes' => $input['notes'], 'created_at' => now(), 'updated_at' => now()]);
            throw new \RuntimeException('Simulated transaction write failure.');
        });
        $this->app->instance(TransactionWriteService::class, $writes);

        $this->postJson('/api/kite/holdings-onboarding/commit', ['stock_ids' => array_column($items, 'id')])->assertServerError();
        $this->assertSame(1, $calls);
        $this->assertDatabaseCount('portfolio_transactions', 0);
        $this->assertSame(1, DB::table('kite_onboarding_stocks')->where('status', 'verified')->count());
    }

    private function startOnboarding(User $user, $profile): \Illuminate\Testing\TestResponse
    {
        BrokerConnection::query()->updateOrCreate(
            ['user_id' => $user->id, 'provider' => BrokerConnection::PROVIDER_KITE],
            ['broker_user_id' => 'KITE-ACCOUNT-1', 'access_token' => 'test-token'],
        );

        return $this->actingAs($user)->withProfileHeader($user, $profile)->postJson('/api/kite/holdings-onboarding');
    }

    private function xlsx(array $header, array $values, bool $formula = false): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'kite-xlsx-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Trades" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/></numFmts><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="164"/></cellXfs></styleSheet>');
        $cells = function (array $row, int $rowNumber, bool $formulaCell = false): string {
            $out = '';
            foreach ($row as $index => $value) {
                $column = '';
                for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
                    $column = chr(65 + (($n - 1) % 26)).$column;
                }
                $ref = $column.$rowNumber;
                if ($formulaCell && $index === 2) {
                    $out .= '<c r="'.$ref.'"><f>1+1</f><v>2</v></c>';
                } elseif ($rowNumber === 2 && $index === 4) {
                    $serial = (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))->getTimestamp() / 86400 + 25569;
                    $out .= '<c r="'.$ref.'" s="1"><v>'.$serial.'</v></c>';
                } elseif ($rowNumber === 2 && in_array($index, [2, 3], true)) {
                    $out .= '<c r="'.$ref.'"><v>'.htmlspecialchars((string) $value, ENT_XML1).'</v></c>';
                } else {
                    $out .= '<c r="'.$ref.'" t="inlineStr">'.($formulaCell && $index === 2 ? '<f>1+1</f><v>2</v>' : '<is><t>'.htmlspecialchars((string) $value, ENT_XML1).'</t></is>').'</c>';
                }
            }

            return '<row r="'.$rowNumber.'">'.$out.'</row>';
        };
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$cells($header, 1).$cells($values, 2, $formula).'</sheetData></worksheet>');
        $zip->close();

        return new UploadedFile($path, 'console.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
