<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiCapability;
use App\Models\LogErrorTriage;
use App\Services\Operations\LogErrorTriageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LogErrorTriageAdminController extends Controller
{
    public function index(Request $request, LogErrorTriageService $service)
    {
        $filters = $request->validate(['classification' => ['sometimes','string','in:code_bug,external_dependency,configuration_or_environment,expected_operational_condition,data_quality_or_input,security_or_abuse_signal,uncertain'], 'min_confidence' => ['sometimes','numeric','between:0,1'], 'page' => ['sometimes','integer','min:1']]);
        $query = LogErrorTriage::query()->orderByDesc('last_seen_at');
        if (isset($filters['classification'])) $query->where('classification', $filters['classification']);
        if (isset($filters['min_confidence'])) $query->where('confidence', '>=', $filters['min_confidence']);
        $capability = AiCapability::where('capability_id', 'ops.log_error_triage')->first();
        $queueConnection = (string) config('log_error_triage.queue_connection');
        $githubConnection = (string) config('api_failure_reporting.queue_connection');
        $queueReady = fn (string $connection): bool => in_array(config("queue.connections.$connection.driver"), ['database','redis','sqs','beanstalkd'], true);
        $apiCounts = DB::table('portfolio_api_failure_incidents')->selectRaw('sync_status, COUNT(*) AS records')->groupBy('sync_status')->pluck('records', 'sync_status');
        return response()->json([
            'enabled' => $service->enabled(),
            'capability' => ['id' => 'ops.log_error_triage', 'enabled' => (bool) $capability?->enabled, 'configured' => count($capability?->path_order ?: []) > 0, 'runtime_enabled' => (bool) config('ai_runtime.enabled')],
            'confidence_threshold' => $service->confidenceThreshold(),
            'debounce_seconds' => (int) config('log_error_triage.debounce_seconds'),
            'decision_ttl_seconds' => (int) config('log_error_triage.decision_ttl_seconds'),
            'counts' => LogErrorTriage::where('last_seen_at', '>=', now()->subDays(7))->selectRaw('classification, status, COUNT(*) AS records, SUM(occurrence_count) AS occurrences')->groupBy('classification','status')->get(),
            'github' => ['enabled' => (bool) config('api_failure_reporting.enabled'), 'state' => DB::table('stox_github_reporter_state')->where('id', 1)->first()],
            'reporting' => [
                'api' => [
                    'enabled' => (bool) config('api_failure_reporting.enabled'),
                    'token_configured' => filled(config('api_failure_reporting.token')),
                    'repository_configured' => filled(config('api_failure_reporting.repository')),
                    'repository_matches_expected' => strtolower((string) config('api_failure_reporting.repository')) === 'lido-alexion/lidoportfolio',
                    'records_by_sync_status' => $apiCounts,
                    'queue_uses_log_triage' => $githubConnection === 'log-triage' && (string) config('api_failure_reporting.queue') === 'log-triage',
                    'queue_is_async' => $queueReady($githubConnection),
                    'worker_configuration_status' => 'unknown_needs_operator_verification',
                ],
                'log_triage' => [
                    'enabled' => $service->enabled(),
                    'queue_uses_log_triage' => $queueConnection === 'log-triage' && (string) config('log_error_triage.queue') === 'log-triage',
                    'queue_is_async' => $queueReady($queueConnection),
                    'worker_configuration_status' => 'unknown_needs_operator_verification',
                ],
            ],
            'triages' => $query->paginate(30),
        ]);
    }

    public function show(int $triage)
    {
        $row = LogErrorTriage::findOrFail($triage);
        return response()->json(['triage' => $row,
            'decisions' => DB::table('stox_log_triage_decisions')->where('triage_id', $row->id)->orderByDesc('id')->paginate(30),
            'github_binding' => $row->fingerprint ? DB::table('stox_github_issue_bindings')->where('marker', '<!-- stox-log-bug:'.$row->fingerprint.' -->')->first() : null,
        ]);
    }
}
