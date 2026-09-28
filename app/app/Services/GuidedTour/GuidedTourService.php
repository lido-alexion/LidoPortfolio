<?php

namespace App\Services\GuidedTour;

use App\Models\User;
use App\Models\UserOnboardingState;
use Illuminate\Validation\ValidationException;

class GuidedTourService
{
    public function isEligible(User $user): bool
    {
        return ! $user->is_admin;
    }

    public function stateFor(User $user): UserOnboardingState
    {
        $version = (string) config('guided_tour.tour_version', 'investor-core-v1');

        return UserOnboardingState::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['tour_version' => $version],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(User $user): array
    {
        $eligible = $this->isEligible($user);
        $state = $this->stateFor($user);
        $maxPrompts = (int) config('guided_tour.max_auto_prompts', 3);

        $suppressAuto = $state->completed_at !== null
            || $state->permanently_dismissed_at !== null
            || $state->welcome_prompt_count >= $maxPrompts;

        return [
            'eligible' => $eligible,
            'tour_version' => $state->tour_version,
            'welcome_prompt_count' => $state->welcome_prompt_count,
            'max_auto_prompts' => $maxPrompts,
            'target_wait_ms' => (int) config('guided_tour.target_wait_ms', 4000),
            'show_welcome_prompt' => $eligible && ! $suppressAuto && ! $state->tour_in_progress,
            'permanently_dismissed' => $state->permanently_dismissed_at !== null,
            'completed' => $state->completed_at !== null,
            'tour_in_progress' => $state->tour_in_progress,
            'current_step_id' => $state->current_step_id,
            'can_manual_relaunch' => $eligible,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function applyAction(User $user, string $action, array $input = []): array
    {
        if (! $this->isEligible($user)) {
            throw ValidationException::withMessages([
                'action' => ['Guided tour is not available for this account.'],
            ]);
        }

        $state = $this->stateFor($user);

        match ($action) {
            'record_welcome_shown' => $this->recordWelcomeShown($state),
            'skip_prompt' => $this->skipPrompt($state),
            'dismiss_forever' => $this->dismissForever($state),
            'begin' => $this->beginTour($state, (string) ($input['step_id'] ?? ''), (bool) ($input['restart'] ?? false)),
            'update_step' => $this->updateStep($state, (string) ($input['step_id'] ?? '')),
            'close' => $this->closeTour($state, (string) ($input['step_id'] ?? '')),
            'complete' => $this->completeTour($state),
            default => throw ValidationException::withMessages([
                'action' => ['Unknown guided tour action.'],
            ]),
        };

        return $this->toPayload($user);
    }

    protected function recordWelcomeShown(UserOnboardingState $state): void
    {
        $state->welcome_prompt_count = (int) $state->welcome_prompt_count + 1;
        $state->save();
    }

    protected function skipPrompt(UserOnboardingState $state): void
    {
        $state->welcome_prompt_count = (int) $state->welcome_prompt_count + 1;
        $state->save();
    }

    protected function dismissForever(UserOnboardingState $state): void
    {
        $state->permanently_dismissed_at = now();
        $state->tour_in_progress = false;
        $state->save();
    }

    protected function beginTour(UserOnboardingState $state, string $stepId, bool $restart): void
    {
        if ($restart) {
            $state->current_step_id = null;
            $state->completed_at = null;
        }
        if ($stepId !== '') {
            $state->current_step_id = $stepId;
        }
        $state->tour_in_progress = true;
        $state->save();
    }

    protected function updateStep(UserOnboardingState $state, string $stepId): void
    {
        if ($stepId === '') {
            throw ValidationException::withMessages(['step_id' => ['Step id is required.']]);
        }
        $state->current_step_id = $stepId;
        $state->tour_in_progress = true;
        $state->save();
    }

    protected function closeTour(UserOnboardingState $state, string $stepId): void
    {
        if ($stepId !== '') {
            $state->current_step_id = $stepId;
        }
        $state->tour_in_progress = false;
        $state->save();
    }

    protected function completeTour(UserOnboardingState $state): void
    {
        $state->completed_at = now();
        $state->tour_in_progress = false;
        $state->current_step_id = null;
        $state->save();
    }
}
