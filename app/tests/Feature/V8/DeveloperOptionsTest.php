<?php

namespace Tests\Feature\V8;

use App\Models\User;
use App\Models\UserOnboardingState;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeveloperOptionsTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/developer-options/guided-tour/reset';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function progressedState(User $user): UserOnboardingState
    {
        return UserOnboardingState::query()->create([
            'user_id' => $user->id,
            'tour_version' => 'old-version',
            'welcome_prompt_count' => 3,
            'permanently_dismissed_at' => now(),
            'completed_at' => now(),
            'current_step_id' => 'holdings',
            'tour_in_progress' => true,
        ])->refresh();
    }

    public function test_authentication_is_required(): void
    {
        $this->postJson(self::ENDPOINT)->assertUnauthorized();
        $this->assertDatabaseCount('stox_user_onboarding_state', 0);
    }

    public function test_reset_returns_fresh_defaults_and_leaves_other_users_untouched(): void
    {
        $user = User::factory()->create();
        $old = $this->progressedState($user);
        $other = $this->progressedState(User::factory()->create());
        $otherAttributes = $other->getAttributes();

        $response = $this->actingAs($user)->postJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.tour_version', config('guided_tour.tour_version'))
            ->assertJsonPath('data.welcome_prompt_count', 0)
            ->assertJsonPath('data.permanently_dismissed', false)
            ->assertJsonPath('data.completed', false)
            ->assertJsonPath('data.current_step_id', null)
            ->assertJsonPath('data.tour_in_progress', false)
            ->assertJsonPath('data.show_welcome_prompt', true)
            ->assertJsonPath('data.can_manual_relaunch', true);

        $this->assertDatabaseMissing('stox_user_onboarding_state', ['id' => $old->id]);
        $this->assertDatabaseHas('stox_user_onboarding_state', [
            'user_id' => $user->id,
            'welcome_prompt_count' => 0,
            'permanently_dismissed_at' => null,
            'completed_at' => null,
            'current_step_id' => null,
            'tour_in_progress' => false,
        ]);
        $this->assertSame($otherAttributes, $other->fresh()->getAttributes());
        $this->getJson('/api/guided-tour')->assertOk()->assertExactJson($response->json());
    }

    public function test_reset_works_without_existing_state_and_can_be_repeated(): void
    {
        $this->actingAs(User::factory()->create());
        $first = $this->postJson(self::ENDPOINT)->assertOk()
            ->assertJsonPath('data.welcome_prompt_count', 0)
            ->assertJsonPath('data.tour_in_progress', false);
        $this->postJson(self::ENDPOINT)->assertOk()->assertExactJson($first->json());
        $this->assertDatabaseCount('stox_user_onboarding_state', 1);
    }

    public function test_target_identities_are_rejected_in_body_or_query(): void
    {
        $user = User::factory()->create();
        $own = $this->progressedState($user);
        $other = $this->progressedState(User::factory()->create());
        $ownAttributes = $own->getAttributes();
        $otherAttributes = $other->getAttributes();
        $this->actingAs($user);

        foreach (['user_id', 'account_id', 'id', 'user', 'account', 'target_user_id', 'userId', 'email'] as $field) {
            $this->postJson(self::ENDPOINT, [$field => $other->user_id])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->postJson(self::ENDPOINT.'?'.$field.'='.$other->user_id)
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->assertSame($ownAttributes, $own->fresh()->getAttributes());
        $this->assertSame($otherAttributes, $other->fresh()->getAttributes());
    }

    public function test_admin_reset_is_forbidden_and_does_not_modify_existing_state(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $state = $this->progressedState($admin);
        $attributes = $state->getAttributes();

        $this->actingAs($admin)->postJson(self::ENDPOINT)->assertForbidden();
        $this->assertSame($attributes, $state->fresh()->getAttributes());
        $this->getJson('/api/guided-tour')->assertForbidden();
        $this->putJson('/api/guided-tour', ['action' => 'begin'])->assertForbidden();
    }

    public function test_admin_reset_does_not_create_onboarding_state(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->postJson(self::ENDPOINT)->assertForbidden();
        $this->assertDatabaseCount('stox_user_onboarding_state', 0);
    }
}
