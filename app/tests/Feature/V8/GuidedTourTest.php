<?php

namespace Tests\Feature\V8;

use App\Models\User;
use App\Models\UserOnboardingState;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class GuidedTourTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost',
        ]);
        config(['guided_tour.max_auto_prompts' => 3]);
    }

    protected function makeUser(array $overrides = []): User
    {
        $isAdmin = (bool) ($overrides['is_admin'] ?? false);
        unset($overrides['is_admin']);

        $user = User::query()->create(array_merge([
            'name' => 'Test User',
            'email' => 'user-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ], $overrides));

        if ($isAdmin) {
            $user->is_admin = true;
            $user->save();
        }

        return $user->fresh();
    }

    protected function actingAsUser(User $user): self
    {
        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        return $this;
    }

    public function test_investor_sees_welcome_prompt_until_cap(): void
    {
        $user = $this->makeUser();

        $this->actingAsUser($user)
            ->getJson('/api/guided-tour')
            ->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.show_welcome_prompt', true);

        $this->putJson('/api/guided-tour', ['action' => 'skip_prompt'])->assertOk();

        $this->getJson('/api/guided-tour')
            ->assertJsonPath('data.welcome_prompt_count', 1)
            ->assertJsonPath('data.show_welcome_prompt', true);
    }

    public function test_dismiss_forever_suppresses_auto_prompt_but_allows_manual_relaunch(): void
    {
        $user = $this->makeUser();
        $this->actingAsUser($user);

        $this->putJson('/api/guided-tour', ['action' => 'dismiss_forever'])->assertOk();

        $this->getJson('/api/guided-tour')
            ->assertJsonPath('data.show_welcome_prompt', false)
            ->assertJsonPath('data.can_manual_relaunch', true);
    }

    public function test_complete_tour_persists_state(): void
    {
        $user = $this->makeUser();
        $this->actingAsUser($user);

        $this->putJson('/api/guided-tour', [
            'action' => 'begin',
            'step_id' => 'navigation',
        ])->assertOk()
            ->assertJsonPath('data.tour_in_progress', true);

        $this->putJson('/api/guided-tour', ['action' => 'complete'])->assertOk()
            ->assertJsonPath('data.completed', true)
            ->assertJsonPath('data.show_welcome_prompt', false);

        $this->assertDatabaseHas('stox_user_onboarding_state', [
            'user_id' => $user->id,
            'tour_in_progress' => false,
        ]);

        $this->assertNotNull(UserOnboardingState::query()->where('user_id', $user->id)->value('completed_at'));
    }

    public function test_interrupted_tour_resumes_from_last_step(): void
    {
        $user = $this->makeUser();
        $this->actingAsUser($user);

        $this->putJson('/api/guided-tour', ['action' => 'begin', 'step_id' => 'navigation'])->assertOk();
        $this->putJson('/api/guided-tour', ['action' => 'update_step', 'step_id' => 'holdings'])->assertOk();

        $this->getJson('/api/guided-tour')
            ->assertJsonPath('data.tour_in_progress', true)
            ->assertJsonPath('data.current_step_id', 'holdings');

        $this->putJson('/api/guided-tour', ['action' => 'begin'])->assertOk()
            ->assertJsonPath('data.current_step_id', 'holdings');
    }

    public function test_admin_cannot_use_investor_guided_tour_api(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $this->actingAsUser($admin);

        $this->getJson('/api/guided-tour')->assertForbidden();

        $this->putJson('/api/guided-tour', ['action' => 'begin', 'step_id' => 'navigation'])
            ->assertForbidden();
    }
}
