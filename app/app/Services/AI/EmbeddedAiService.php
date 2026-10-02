<?php

namespace App\Services\AI;

use App\Models\AiCapability;
use App\Models\AiInsightCache;
use App\Models\AiPrompt;

class EmbeddedAiService
{
    public function __construct(private AiRuntimeClient $runtime) {}

    public function execute(string $capability, array $context, int $actor, bool $refresh = false, bool $lookupOnly = false): array
    {
        $schema = EmbeddedAiContract::schema($capability);
        $prompt = AiPrompt::query()->where('prompt_id', $capability)->where('active', true)->orderByDesc('version')->first();
        $identity = ['capability_id' => $capability, 'capability_version' => 1, 'schema_version' => 1, 'prompt_version' => $prompt?->version ?? 0, 'schema' => $schema, 'prompt_template' => $prompt?->template, 'scope' => $context['scope'], 'stock_id' => $context['stock_id'] ?? null, 'user_id' => $context['user_id'] ?? null, 'profile_id' => $context['profile_id'] ?? null, 'input' => $context['input']];
        if ($context['scope'] === 'global_stock' && ($identity['user_id'] !== null || $identity['profile_id'] !== null || array_key_exists('holding', $context['input']))) {
            throw new \LogicException('Private global evidence rejected');
        }
        $fingerprint = EmbeddedAiContract::hash($identity);
        $cached = AiInsightCache::query()->where('fingerprint', $fingerprint)->first();
        if ($cached && ! $refresh) {
            return $this->project($cached, false, $fingerprint);
        }
        if ($lookupOnly) {
            return $this->project(null, false, $fingerprint);
        }
        try {
            if (! $prompt || ! AiCapability::query()->where('capability_id', $capability)->where('enabled', true)->exists()) {
                throw new \RuntimeException('Capability unavailable');
            }
            $requestId = (string) str()->uuid();
            $input = $context['input'] + ['response_schema' => $schema];
            if ($capability === 'strategy_designer') {
                $input['authoring_contract'] = $this->authoringContract();
            }
            $result = $this->runtime->infer($capability, $input, ['request_id' => $requestId, 'user_id' => $actor, 'account_id' => $actor, 'output_schema' => $schema, 'stream' => true, 'timeout_seconds' => 55]);
            if (($result['status'] ?? '') !== 'success' || ($result['degraded'] ?? false) || ! is_array($result['structured'] ?? null) || (int) ($result['prompt']['version'] ?? 0) !== (int) $prompt->version) {
                throw new \RuntimeException('Invalid final response');
            }
            $response = EmbeddedAiContract::validate($capability, $result['structured']);
            if ($capability === 'stock_analysis_insight' && $context['input']['data_limitations']) {
                $response['data_limitations'] .= "\n".implode('; ', $context['input']['data_limitations']);
                $response = EmbeddedAiContract::validate($capability, $response);
            }
            // JSON object key order is not stable across supported databases.
            // Canonicalize the validated contract so a fresh response and its
            // cached form are identical on SQLite and MySQL alike.
            /** @var array<string,mixed> $response */
            $response = EmbeddedAiContract::normalize($response);
            $cached = AiInsightCache::query()->updateOrCreate(['fingerprint' => $fingerprint], ['capability_id' => $capability, 'scope' => $context['scope'], 'stock_id' => $identity['stock_id'], 'user_id' => $identity['user_id'], 'profile_id' => $identity['profile_id'], 'capability_version' => 1, 'prompt_version' => $prompt->version, 'schema_version' => 1, 'response' => $response, 'data_as_of' => $context['data_as_of'] ?? [], 'request_id' => $requestId, 'generated_at' => now(), 'refresh_failed_at' => null]);

            return $this->project($cached, false, $fingerprint);
        } catch (\Throwable $error) {
            report($error);
            if ($cached) {
                $cached->update(['refresh_failed_at' => now()]);
            }

            return $this->project($cached, true, $fingerprint);
        }
    }

    private function authoringContract(): string
    {
        return StrategyDesignerInput::authoringEvidence(file_get_contents(base_path('../docs/current/stox-trading-artifacts-ai-guide.md')));
    }

    public function authoringContractVersion(): string
    {
        return hash('sha256', $this->authoringContract());
    }

    private function project(?AiInsightCache $cache, bool $degraded, string $fingerprint): array
    {
        return ['fingerprint' => $fingerprint, 'response' => $cache?->response, 'data_as_of' => $cache?->data_as_of, 'generated_at' => $cache?->generated_at?->toIso8601String(), 'degraded' => $degraded, 'status' => $degraded ? ($cache ? 'cached_degraded' : 'unavailable') : ($cache ? 'ready' : 'missing')];
    }
}
