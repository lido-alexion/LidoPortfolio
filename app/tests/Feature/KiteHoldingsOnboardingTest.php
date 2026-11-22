<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\User;
use App\Services\Broker\FakeBrokerGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KiteHoldingsOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_staging_is_persistent_and_verify_is_separate_from_commit(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        Stock::query()->create(['symbol'=>'ONBOARD','exchange'=>'NSE','name'=>'Onboard','is_active'=>true,'is_benchmark'=>false]);
        $gateway = app(FakeBrokerGateway::class);
        $gateway->reconciliationSnapshot = ['provider'=>'kite','holdings'=>[['symbol'=>'ONBOARD','exchange'=>'NSE','quantity'=>5,'average_price'=>100]],'positions'=>[],'current_cash'=>0];
        $response = $this->actingAs($user)->withProfileHeader($user,$profile)->postJson('/api/kite/holdings-onboarding')->assertCreated();
        $item = $response->json('data.stocks.0');
        $this->assertSame('needs_review',$item['status']);
        $this->assertTrue($item['entries'][0]['date_placeholder']);
        $this->getJson('/api/kite/holdings-onboarding')->assertOk()->assertJsonPath('data.stocks.0.id',$item['id']);
        $this->postJson('/api/kite/holdings-onboarding/stocks/'.$item['id'].'/verify')->assertOk()->assertJsonPath('data.stocks.0.status','verified');
        $this->assertDatabaseCount('portfolio_transactions',0);
    }

    public function test_verify_rejects_uncovered_sales_and_cost_basis_mismatch(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        Stock::query()->create(['symbol'=>'ONBOARD','exchange'=>'NSE','name'=>'Onboard','is_active'=>true,'is_benchmark'=>false]);
        $gateway = app(FakeBrokerGateway::class);
        $gateway->reconciliationSnapshot = ['provider'=>'kite','holdings'=>[['symbol'=>'ONBOARD','exchange'=>'NSE','quantity'=>5,'average_price'=>100]],'positions'=>[],'current_cash'=>0];
        $response = $this->actingAs($user)->withProfileHeader($user,$profile)->postJson('/api/kite/holdings-onboarding')->assertCreated();
        $item = $response->json('data.stocks.0');
        $this->putJson('/api/kite/holdings-onboarding/stocks/'.$item['id'], ['entries'=>[['type'=>'buy','quantity'=>2,'price'=>100,'date'=>today()->toDateString(),'provenance'=>'manual'],['type'=>'sell','quantity'=>3,'price'=>100,'date'=>today()->toDateString(),'provenance'=>'manual']]])->assertOk();
        $this->postJson('/api/kite/holdings-onboarding/stocks/'.$item['id'].'/verify')->assertUnprocessable();
    }
}
