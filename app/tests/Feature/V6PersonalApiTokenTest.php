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

    public function test_scoped_personal_token_preserves_portfolio_ownership(): void
    {
        $owner = User::factory()->create();
        $ownerProfile = $this->defaultPortfolioFor($owner);
        $foreignUser = User::factory()->create();
        $foreignProfile = $this->defaultPortfolioFor($foreignUser);
        $ownerToken = $owner->createToken('Portfolio reader', ['portfolio:read'])->plainTextToken;

        $this->flushHeaders()->withToken($ownerToken)
            ->withHeader('X-Profile-Id', (string) $ownerProfile->id)
            ->getJson('/api/portfolios/'.$ownerProfile->id)
            ->assertOk()
            ->assertJsonPath('data.id', $ownerProfile->id);

        $this->flushHeaders()->withToken($ownerToken)
            ->withHeader('X-Profile-Id', (string) $ownerProfile->id)
            ->getJson('/api/portfolios/'.$foreignProfile->id)
            ->assertNotFound();

    }

    public function test_personal_token_missing_read_scope_is_rejected_for_owned_portfolio_member_route(): void
    {
        $owner = User::factory()->create();
        $ownerProfile = $this->defaultPortfolioFor($owner);
        $writeToken = $owner->createToken('Portfolio writer', ['portfolio:write'])->plainTextToken;

        $this->flushHeaders()->withToken($writeToken)
            ->withHeader('X-Profile-Id', (string) $ownerProfile->id)
            ->getJson('/api/portfolios/'.$ownerProfile->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'This API token is missing the required scope.');
    }

    public function test_admin_personal_token_does_not_grant_investor_portfolio_ownership(): void
    {
        $owner = User::factory()->create();
        $ownerProfile = $this->defaultPortfolioFor($owner);
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $adminToken = $admin->createToken('Admin reader', ['portfolio:read'])->plainTextToken;
        $this->flushHeaders()->withToken($adminToken)
            ->withHeader('X-Profile-Id', (string) $ownerProfile->id)
            ->getJson('/api/portfolios/'.$ownerProfile->id)
            ->assertNotFound();

        $this->assertDatabaseMissing('portfolio_profiles', ['user_id' => $admin->id]);
    }
}
