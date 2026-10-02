<?php

namespace App\Services\AI;

use App\Models\{AiAgentRun, PortfolioProfile, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class AiAgentService
{
    public function __construct(private AiToolCatalog $tools) {}

    public function create(Request $request, string $objective): array
    {
        $user = $request->user();
        if ($user->is_admin) throw new AiToolFailure('forbidden', 403);
        $profileId = $request->header('X-Profile-Id') ?? $request->header('X-Portfolio-Id');
        $profiles = PortfolioProfile::query()->where('user_id', $user->id);
        $profile = $profileId ? $profiles->findOrFail($profileId) : $profiles->where('is_default', true)->first();
        if (! $profile) throw new AiToolFailure('not_initialized', 409);
        $token = $user->currentAccessToken();
        $scopes = array_values(array_filter(['portfolio:read', 'portfolio:write'], fn ($scope) => ! $token || $token->can($scope)));
        if (! in_array('portfolio:read', $scopes, true)) throw new AiToolFailure('forbidden', 403);
        $delegation = bin2hex(random_bytes(32));
        $run = AiAgentRun::query()->create(['id' => (string) str()->uuid(), 'user_id' => $user->id, 'profile_id' => $profile->id, 'account_id' => $user->id,
            'token_id' => $token instanceof PersonalAccessToken ? $token->id : null, 'scopes' => $scopes,
            'delegation_digest' => hash('sha256', $delegation), 'delegation_expires_at' => now()->addMinutes(15),
            'objective' => $objective, 'status' => 'investigating', 'steps' => [], 'trace' => [], 'correlation_id' => $request->header('X-Request-ID')]);
        return [$run, $delegation];
    }

    /** Re-read the current token, user and profile, never trust Python-supplied identities or roles. */
    public function authorize(AiAgentRun $run, ?string $scope = null): array
    {
        $user = User::query()->findOrFail($run->user_id);
        if ($user->is_admin) throw new AiToolFailure('forbidden', 403);
        $profile = PortfolioProfile::query()->where('user_id', $user->id)->findOrFail($run->profile_id);
        if ($run->token_id) {
            $token = $user->tokens()->lockForUpdate()->find($run->token_id);
            if (! $token || ($token->expires_at && $token->expires_at->isPast()) || (config('sanctum.expiration') && $token->created_at->lte(now()->subMinutes((int) config('sanctum.expiration')))) || ($scope && ! $token->can($scope))) throw new AiToolFailure('delegated_scope_denied', 403);
        }
        if ($scope && ! in_array($scope, $run->scopes, true)) throw new AiToolFailure('delegated_scope_denied', 403);
        return [$user, $profile];
    }

    public function gateway(array $input): array
    {
        try { return $this->dispatch($input); }
        catch (\Throwable $error) {
            // Persist safe denied/failed-call evidence separately from the rolled-back invocation.
            DB::transaction(function () use ($input, $error) {
                $run = AiAgentRun::query()->lockForUpdate()->find($input['run_id']);
                if ($run && hash_equals($run->delegation_digest, hash('sha256', $input['delegation'])) && count($run->trace ?? []) < 8) {
                    $trace = $run->trace ?? [];
                    $trace[] = ['tool' => in_array($input['tool'] ?? '', [...AiToolCatalog::READS, ...AiToolCatalog::MUTATIONS], true) ? $input['tool'] : 'gateway', 'status' => 'failed', 'code' => $error instanceof AiToolFailure ? $error->reason : 'tool_failed', 'at' => now()->toIso8601String()];
                    $run->update(['trace' => $trace]);
                }
            });
            throw $error;
        }
    }

    private function dispatch(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $run = AiAgentRun::query()->lockForUpdate()->findOrFail($input['run_id']);
            if (! hash_equals($run->delegation_digest, hash('sha256', $input['delegation']))) throw new AiToolFailure('delegation_invalid', 403);
            if ($run->delegation_expires_at->isPast()) throw new AiToolFailure('delegation_expired', 403);
            [$user, $profile] = $this->authorize($run, 'portfolio:read');
            if ($input['operation'] === 'catalog') {
                $scopes = $run->scopes;
                if ($run->token_id) $scopes = array_values(array_filter($scopes, fn ($scope) => $user->tokens()->findOrFail($run->token_id)->can($scope)));
                return ['tools' => $this->tools->catalog($scopes)];
            }
            if ($input['operation'] === 'preview') {
                $this->authorize($run, 'portfolio:write');
                return $this->preview($run, $input['plan'] ?? [], $user, $profile);
            }
            if ($input['operation'] === 'complete') {
                if ($run->status !== 'investigating') throw new AiToolFailure('run_state_conflict', 409);
                $run->update(['status' => 'completed', 'answer' => $input['answer'] ?? 'Evidence is unavailable.']);
                return $run->toArray();
            }
            if ($run->status !== 'investigating') throw new AiToolFailure('run_state_conflict', 409);
            if (count($run->trace ?? []) >= 8) throw new AiToolFailure('max_steps_exceeded');
            $tool = $input['tool'] ?? '';
            if (! in_array($tool, AiToolCatalog::READS, true)) throw new AiToolFailure('tool_not_allowed');
            $start = microtime(true);
            $result = $this->tools->read($tool, $input['arguments'] ?? [], $profile, $user);
            $trace = $run->trace ?? [];
            $trace[] = ['tool' => $tool, 'status' => $result['availability'], 'duration_ms' => (int) ((microtime(true) - $start) * 1000), 'at' => now()->toIso8601String()];
            $run->update(['trace' => $trace]);
            return $result;
        });
    }

    private function preview(AiAgentRun $run, array $plan, User $user, PortfolioProfile $profile): array
    {
        if ($run->status !== 'investigating') throw new AiToolFailure('run_state_conflict', 409);
        if (! array_is_list($plan) || count($plan) < 1 || count($plan) > 5) throw new AiToolFailure('invalid_plan');
        $preview = [];
        foreach ($plan as $step) {
            if (! is_array($step) || array_diff(array_keys($step), ['tool', 'arguments', 'reason']) || ! is_string($step['tool'] ?? null) || ! is_array($step['arguments'] ?? null) || ! is_string($step['reason'] ?? null) || mb_strlen($step['reason']) > 300) throw new AiToolFailure('invalid_plan');
            $preview[] = [...$this->tools->preview($step['tool'], $step['arguments'], $profile, $user), 'reason' => $step['reason']];
        }
        $hash = $this->tools->hash(['run_id' => $run->id, 'user_id' => (int) $run->user_id, 'profile_id' => (int) $run->profile_id, 'plan' => $plan, 'preview' => $preview]);
        $run->update(['plan' => $plan, 'plan_hash' => $hash, 'preview' => $preview, 'status' => 'awaiting_approval', 'approval_expires_at' => now()->addMinutes(5)]);
        return $run->toArray();
    }

    public function approveAndExecute(AiAgentRun $ownedRun, string $hash, bool $destructive): AiAgentRun
    {
        return DB::transaction(function () use ($ownedRun, $hash, $destructive) {
            $run = AiAgentRun::query()->lockForUpdate()->findOrFail($ownedRun->id);
            [$user, $profile] = $this->authorize($run, 'portfolio:write');
            if (! $run->plan_hash || ! hash_equals($run->plan_hash, $hash)) throw new AiToolFailure('approval_scope_invalid', 409);
            $currentHash = $this->tools->hash(['run_id' => $run->id, 'user_id' => (int) $run->user_id, 'profile_id' => (int) $run->profile_id, 'plan' => $run->plan, 'preview' => $run->preview]);
            if (! hash_equals($hash, $currentHash)) throw new AiToolFailure('approval_scope_invalid', 409);
            // Duplicate execution returns the stored outcome, including partial failures.
            if ($run->approved_at) return $run;
            if ($run->status !== 'awaiting_approval') throw new AiToolFailure('approval_required', 409);
            if ($run->approval_expires_at->isPast()) { $run->update(['status' => 'expired']); return $run; }
            if (collect($run->preview)->contains(fn ($p) => $p['side_effect'] === 'destructive') && ! $destructive) throw new AiToolFailure('destructive_confirmation_required');
            // Lock the originating profile and target rows through verification. Domain services retain their own policies.
            PortfolioProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            foreach ($run->preview as $step) {
                if (! hash_equals($step['state_hash'], $this->tools->hash($this->tools->snapshot($step['tool'], $step['arguments'], $profile, $user)))) {
                    $run->update(['status' => 'stale']);
                    return $run;
                }
            }
            $run->update(['approved_at' => now(), 'status' => 'executing']);
            $steps = [];
            $status = 'completed';
            $request = request();
            $previousProfile = $request->attributes->get('active_portfolio');
            $request->attributes->set('active_portfolio', $profile);
            try {
                foreach ($run->plan as $index => $step) {
                    $mutated = false;
                    $result = [];
                    try {
                        $this->authorize($run, 'portfolio:write');
                        $result = DB::transaction(fn () => $this->tools->mutate($step['tool'], $step['arguments'], $profile, $user));
                        $mutated = true;
                        $verified = $this->tools->verify($step['tool'], $step['arguments'], $result, $profile, $user);
                        $steps[] = ['index' => $index, 'tool' => $step['tool'], 'status' => $verified ? 'verified' : 'verification_failed', 'affected_object_id' => $result['id'] ?? $result['reusable_artifact_version_id'] ?? $step['arguments']['id'] ?? null, 'at' => now()->toIso8601String()];
                        if (! $verified) { $status = 'verification_failed'; break; }
                    } catch (\Throwable $error) {
                        report($error);
                        $steps[] = ['index' => $index, 'tool' => $step['tool'], 'status' => $mutated ? 'verification_failed' : 'failed', 'affected_object_id' => $result['id'] ?? $result['reusable_artifact_version_id'] ?? $step['arguments']['id'] ?? null, 'code' => $error instanceof AiToolFailure ? $error->reason : 'domain_failure', 'at' => now()->toIso8601String()];
                        $status = $mutated ? 'verification_failed' : ($index === 0 ? 'failed' : 'partial_failure');
                        break;
                    }
                }
            } finally { $request->attributes->set('active_portfolio', $previousProfile); }
            for ($index = count($steps); $index < count($run->plan); $index++) $steps[] = ['index' => $index, 'tool' => $run->plan[$index]['tool'], 'status' => 'not_attempted'];
            $run->update(['steps' => $steps, 'status' => $status, 'answer' => $status === 'completed' ? 'The approved changes were completed and verified.' : 'Execution stopped. Review the action results. Retry builds a fresh plan and requires new approval.']);
            return $run->fresh();
        });
    }
}
