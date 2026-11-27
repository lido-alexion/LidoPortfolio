<?php

namespace App\Services;

use App\Models\DataQualityIssue;
use App\Models\DataQualityIssueEvidence;
use App\Services\Fundamentals\AI\FundamentalAiUsageService;
use App\Services\Fundamentals\AI\FundamentalInsightsAiOrchestrator;
use Illuminate\Support\Facades\Http;

/** AI may suggest official URLs; only our bounded fetch and hash become evidence. */
class DataQualityCorporateActionAiDiscovery
{
    public function __construct(private FundamentalAiUsageService $usage, private FundamentalInsightsAiOrchestrator $providers) {}

    /** Supplement deterministic discovery. Any failure is deliberately fail-open. */
    public function discover(array $events, ?string $detectionRunId = null): array
    {
        $mode = (string) config('services.data_quality.corporate_actions_ai_mode', 'always');
        if (! config('services.data_quality.corporate_actions_ai_enabled', true)
            || ! in_array($mode, ['always', 'off'], true) || $mode === 'off' || $events === []) {
            return ['status' => 'disabled', 'verified' => 0];
        }
        if (! config('fundamentals_ai.enabled', false) || ! $this->usage->allowInvocation(null)) {
            return ['status' => 'unavailable', 'verified' => 0];
        }

        $started = microtime(true);
        try {
            $result = $this->providers->discoverCorporateActionSources($events);
            $provider = (string) ($result['provider'] ?? '');
            $telemetry = is_array($result['telemetry'] ?? null) ? $result['telemetry'] : [];
            if (! ($result['ok'] ?? false)) throw new \RuntimeException((string) ($result['error_code'] ?? 'provider_unavailable'));
            $verified = $this->verifyAndStage($result['candidates'] ?? [], $events, $provider, (string) ($telemetry['model'] ?? ''), $detectionRunId);
            $telemetry['provider_role'] = 'primary';
            $this->usage->record(null, null, ['ai' => ['status' => 'ok', 'provider' => $provider, 'failover_from' => $result['failover_from'] ?? []]], (int) ((microtime(true) - $started) * 1000), $telemetry, 'corporate_action_evidence_discovery');
            return ['status' => 'ok', 'verified' => $verified];
        } catch (\Throwable $e) {
            try {
                $this->usage->record(null, null, ['ai' => ['status' => 'unavailable', 'provider' => null]], (int) ((microtime(true) - $started) * 1000), ['provider_role' => 'primary', 'error_code' => 'discovery_failed'], 'corporate_action_evidence_discovery');
            } catch (\Throwable) { /* AI telemetry cannot interrupt deterministic sync. */ }
            return ['status' => 'unavailable', 'verified' => 0];
        }
    }

    private function verifyAndStage(array $candidates, array $events, string $provider, string $model, ?string $detectionRunId): int
    {
        $allowed = (array) config('services.data_quality.corporate_actions_ai_allowed_hosts', []);
        $verified = 0;
        $seen = [];
        foreach (array_slice($candidates, 0, 30) as $candidate) {
            if (! is_array($candidate)) continue;
            $symbol = strtoupper(trim((string) ($candidate['symbol'] ?? '')));
            $date = (string) ($candidate['ex_date'] ?? '');
            $url = (string) ($candidate['url'] ?? '');
            $parts = parse_url($url);
            if (! in_array($symbol.'|'.$date, array_map(fn ($e) => strtoupper((string) ($e['symbol'] ?? '')).'|'.(string) ($e['ex_date'] ?? ''), $events), true)
                || ! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! in_array(strtolower((string) ($parts['host'] ?? '')), $allowed, true)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($seen[$url])) continue;
            $seen[$url] = true;
            $issue = DataQualityIssue::query()->where('symbol', $symbol)->whereDate('ex_date', $date)->where('issue_type', DataQualityIssue::TYPE_CORPORATE_ACTION)->where('issue_status', DataQualityIssue::STATUS_PENDING_REVIEW)->first();
            if (! $issue) continue;
            $duplicate = $issue->evidences()->where('evidence_key', 'ai_official_proposal')->where('evidence_payload->url', $url)->exists();
            if ($duplicate) continue;
            $response = Http::timeout(12)->withoutRedirecting()->withHeaders(['Accept' => 'application/pdf,application/json,text/html'])->get($url);
            if (! $response->successful() || strlen($response->body()) === 0 || strlen($response->body()) > 5_000_000) continue;
            $sha = hash('sha256', $response->body());
            DataQualityIssueEvidence::query()->create([
                'issue_id' => $issue->id, 'evidence_key' => 'ai_official_proposal', 'evidence_label' => 'AI suggested official source; independently fetched',
                'evidence_value' => $url, 'evidence_payload' => ['source' => 'ai_untrusted_proposal', 'url' => $url, 'sha256' => $sha, 'bytes' => strlen($response->body()), 'http_status' => $response->status(), 'content_type' => $response->header('Content-Type'), 'captured_at' => now()->toIso8601String(), 'provider' => $provider, 'model' => $model, 'detection_run_id' => $detectionRunId, 'ai_authority' => false], 'captured_at' => now(),
            ]);
            $verified++;
        }
        return $verified;
    }
}
