<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class V6PersonalApiTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_list_and_revoke_scoped_personal_api_tokens(): void
    {
        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);

        $response = $this->actingAs($user)->withProfileHeader($user)
            ->postJson('/api/personal-api-tokens', [
                'name' => 'Local notebook',
                'abilities' => ['portfolio:read', 'execution:read'],
            ])->assertCreated()
            ->assertJsonPath('data.name', 'Local notebook')
            ->assertJsonPath('data.abilities', ['portfolio:read', 'execution:read']);

        $this->assertNotEmpty($response->json('data.token'));
        $tokenId = $response->json('data.id');
        $this->assertSame(1, PersonalAccessToken::query()->count());

        $this->getJson('/api/personal-api-tokens')
            ->assertOk()
            ->assertJsonPath('data.0.id', $tokenId)
            ->assertJsonMissingPath('data.0.token');

        $this->deleteJson('/api/personal-api-tokens/'.$tokenId)
            ->assertOk();
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_personal_api_token_scope_is_enforced_without_bypassing_cookie_auth(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $token = $user->createToken('Read only', ['portfolio:read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Profile-Id', (string) $profile->id)
            ->getJson('/api/portfolios')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson('/api/portfolios', [
                'name' => 'Should Fail',
                'portfolio_type' => 'live',
            ])->assertForbidden()
            ->assertJsonPath('message', 'This API token is missing the required scope.');

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/portfolios', [
                'name' => 'Cookie Works',
                'portfolio_type' => 'live',
            ])->assertCreated();
    }
}
