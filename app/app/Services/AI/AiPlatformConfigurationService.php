<?php

namespace App\Services\AI;

use App\Models\AiBudgetLimit;
use App\Models\AiCapability;
use App\Models\AiPrompt;
use App\Models\AiProviderPath;

class AiPlatformConfigurationService
{
    /** Projection only: the Python runtime never receives database access. */
    public function projection(): array
    {
        app(AiBudgetReservationService::class)->refreshProjection();

        $paths = AiProviderPath::query()->orderBy('priority')->orderBy('path_id')->get()->map(fn ($path) => [
            'path_id' => $path->path_id, 'provider' => $path->provider, 'model' => $path->model,
            'priority' => $path->priority, 'enabled' => $path->enabled, 'config' => $path->config ?: [],
        ])->all();
        $prompts = AiPrompt::query()->where('active', true)->get()->mapWithKeys(fn ($prompt) => [$prompt->prompt_id => [
            'id' => $prompt->prompt_id, 'version' => $prompt->version, 'template' => $prompt->template,
            'input_schema' => $prompt->input_schema, 'output_schema' => $prompt->output_schema,
        ]])->all();

        return [
            'version' => (string) now('UTC')->format('Uu'),
            'global_max_concurrency' => max(1, (int) (\App\Models\Setting::query()->where('setting_key', 'ai_global_max_concurrency')->value('setting_value') ?? config('ai_runtime.global_max_concurrency', 16))),
            'capabilities' => AiCapability::query()->orderBy('capability_id')->get()->map(fn ($capability) => [
                'capability_id' => $capability->capability_id, 'owner' => $capability->owner,
                'enabled' => $capability->enabled, 'path_order' => $capability->path_order ?: [],
                'output_schema' => $capability->output_schema, 'prompt_id' => $capability->capability_id,
                'streaming' => in_array($capability->capability_id, ['documentation_chat', ...EmbeddedAiContract::CAPABILITIES], true),
                'max_concurrency' => max(1, (int) $capability->max_concurrency),
                'service_class' => $capability->capability_id === 'ops.log_error_triage' ? 'background' : 'interactive',
            ])->all(),
            'provider_paths' => $paths, 'prompts' => $prompts,
            'budgets' => AiBudgetLimit::query()->whereNotNull('hard_limit')->get()->map(fn ($budget) => ['scope' => $budget->scope, 'hard_limit' => (float) $budget->hard_limit, 'spent' => (float) $budget->spent])->all(),
        ];
    }
}
