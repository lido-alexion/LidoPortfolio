<?php

namespace Tests\Feature;

use App\Jobs\TriageLogErrorJob;
use App\Models\LogErrorTriage;
use App\Models\User;
use App\Services\AI\AiRuntimeClient;
use App\Services\Operations\GitHubIssueReporter;
use App\Services\Operations\LogErrorSanitizer;
use App\Services\Operations\LogErrorTriageService;
use App\Services\Operations\LogTriageGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class V9LogErrorTriageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        config(['log_error_triage.enabled' => true, 'log_error_triage.environments' => ['testing'], 'log_error_triage.build_sha' => 'abcdef0123456',
            'api_failure_reporting.enabled' => true, 'api_failure_reporting.environments' => ['testing'], 'api_failure_reporting.token' => 'fake-test-token',
            'ai_runtime.enabled' => true, 'ai_runtime.base_url' => 'http://ai.test', 'app.key' => 'local-test-key']);
    }

    private function exception(string $message = 'Undefined null value', int $line = 123): \Throwable
    {
        $exception = new \RuntimeException($message);
        (new \ReflectionProperty(\Exception::class, 'file'))->setValue($exception, app_path('Services/PortfolioService.php'));
        (new \ReflectionProperty(\Exception::class, 'line'))->setValue($exception, $line);
        return $exception;
    }

    private function observe(string $message = 'Undefined null value', array $context = [], string $level = 'error', int $line = 123): ?LogErrorTriage
    {
        app(LogErrorTriageService::class)->observeLog(new MessageLogged($level, $message, ['exception' => $this->exception($message, $line), ...$context]));
        return LogErrorTriage::latest('id')->first();
    }

    private function decision(LogErrorTriage $row, array $overrides = []): array
    {
        return [...[
            'classification' => 'code_bug', 'confidence' => .94, 'summary' => 'Null error',
            'evidence' => app(LogErrorTriageService::class)->input($row)['evidence_candidates'],
            'suspected_component' => $row->component, 'bug_kind' => 'null_handling',
            'actionability' => 'actionable', 'safe_issue_title' => 'Null handling', 'security_sensitive' => false,
        ], ...$overrides];
    }

    private function fake(array $decision, bool $open = false): void
    {
        Http::fake(function ($request) use ($decision, $open) {
            if (str_contains($request->url(), '/internal/v1/inference')) return Http::response(['data' => ['status' => 'success', 'structured' => $decision, 'prompt' => ['version' => 2], 'routing_trace' => [['state' => 'selected','path_id' => 'test-path']]]]);
            if (str_contains($request->url(), '/search/issues')) {
                preg_match('/<!-- stox-log-bug:[a-f0-9]{64} -->/', $request['q'], $match);
                return Http::response(['items' => $open ? [['number' => 42, 'state' => 'open', 'body' => $match[0] ?? '']] : []]);
            }
            if ($request->method() === 'POST') return Http::response(['number' => 43, 'state' => 'open'], 201);
            return Http::response(['number' => 43,'state' => 'open']);
        });
    }

    private function runJob(LogErrorTriage $row): void
    {
        $this->travel(301)->seconds();
        app()->call([new TriageLogErrorJob($row->id), 'handle']);
    }

    public function test_disabled_environment_and_non_error_levels_do_not_observe(): void
    {
        config(['log_error_triage.enabled' => false]); $this->observe();
        config(['log_error_triage.enabled' => true, 'log_error_triage.environments' => ['production']]); $this->observe();
        config(['log_error_triage.environments' => ['testing']]);
        foreach (['warning','info','notice','debug'] as $level) $this->observe(level: $level);
        $this->assertDatabaseCount('stox_log_error_triages', 0);
        Bus::assertNothingDispatched(); Http::assertNothingSent();
    }

    public function test_central_log_event_preserves_original_log_and_only_queues(): void
    {
        $seen = [];
        \Illuminate\Support\Facades\Event::listen(MessageLogged::class, function ($event) use (&$seen) { $seen[] = $event->message; });
        Log::error('Application failure', ['exception' => $this->exception()]);
        self::assertContains('Application failure', $seen);
        self::assertSame(1, LogErrorTriage::count());
        Bus::assertDispatched(TriageLogErrorJob::class, fn ($job) => $job->connection === 'log-triage' && $job->queue === 'log-triage' && $job->delay->isFuture());
        Http::assertNothingSent();
    }

    public function test_plain_application_error_without_exception_is_centrally_observed(): void
    {
        app(\App\Services\PortfolioLoggerService::class)->api('error', 'Null access');
        $row = LogErrorTriage::sole();
        self::assertNull($row->exception_class);
        self::assertNotEmpty($row->safe_context['frames']);
        Bus::assertDispatchedTimes(TriageLogErrorJob::class, 1); Http::assertNothingSent();
    }

    public function test_operational_endpoints_and_explicit_expected_conditions_are_prefiltered(): void
    {
        foreach (['api/telemetry/otlp/v1/traces', 'api/telemetry/route-view', 'api/logs/frontend', 'api/internal/v1/ai-runtime/reservations', 'api/internal/v1/ai-runtime/settlements'] as $uri) {
            request()->setRouteResolver(fn () => new \Illuminate\Routing\Route('POST', $uri, fn () => null));
            $this->observe();
        }
        request()->setRouteResolver(fn () => null);
        config(['log_error_triage.ignored_messages' => ['Expected reconnect']]);
        $this->observe('Expected reconnect');
        app(LogErrorTriageService::class)->observe(new \Illuminate\Auth\AuthenticationException());
        self::assertSame(0, LogErrorTriage::count()); Bus::assertNothingDispatched(); Http::assertNothingSent();
    }

    public function test_privacy_allowlist_is_exactly_the_inference_and_issue_boundary(): void
    {
        $secrets = ['secret-token','password-value','session-cookie','person@example.com','private-account','private-holdings','private-content','private-trace'];
        $row = $this->observe('Null value for person@example.com private-account private-holdings', [
            'Authorization' => 'Bearer secret-token', 'password' => 'password-value', 'Cookie' => 'session-cookie',
            'user_id' => 'private-account', 'portfolio' => 'private-holdings', 'body' => 'private-content',
            'component' => 'private-content', 'route' => '/users/private-account', 'trace_id' => 'private-trace',
        ]);
        $input = app(LogErrorTriageService::class)->input($row);
        $this->fake($this->decision($row, ['summary' => 'password-value person@example.com', 'safe_issue_title' => 'private-content']));
        $this->runJob($row);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/inference') && $request['input'] === $input
            && $request['capability_id'] === 'ops.log_error_triage' && $request['trace_id'] === '' && $request['context'] === ['user_id' => null,'account_id' => null]);
        $persisted = json_encode([$row->fresh()->toArray(), DB::table('stox_log_triage_decisions')->get()]);
        foreach ($secrets as $secret) self::assertStringNotContainsString($secret, $persisted);
        Http::assertSent(function ($request) use ($secrets, $row) {
            if (! str_contains($request->url(), 'github.com/repos') || $request->method() !== 'POST') return false;
            foreach ($secrets as $secret) self::assertStringNotContainsString($secret, $request->body());
            self::assertStringContainsString('<!-- stox-log-bug:'.$row->fresh()->fingerprint.' -->', $request['body']);
            self::assertStringContainsString($row->fresh()->evidence, $request['body']);
            return true;
        });
    }

    public function test_tokens_passwords_cookies_and_signed_urls_in_message_are_never_retained(): void
    {
        $row = $this->observe('password="p a s s" Authorization: Bearer xyz Cookie: a=b; token=xyz https://private.test?signature=secret person@example.com');
        self::assertSame('Application error', $row->safe_message);
        self::assertTrue($row->safe_context['security_sensitive']);
        $this->fake($this->decision($row)); $this->runJob($row);
        self::assertSame('security_or_abuse_signal', $row->fresh()->classification);
        Http::assertSentCount(1);
    }

    public function test_burst_and_six_hour_cache_aggregate_and_deploy_changes_invalidate(): void
    {
        $row = $this->observe();
        for ($i = 0; $i < 20; $i++) $this->observe();
        self::assertSame(21, $row->fresh()->occurrence_count);
        Bus::assertDispatchedTimes(TriageLogErrorJob::class, 1);
        $this->fake($this->decision($row, ['classification' => 'external_dependency'])); $this->runJob($row);
        $this->travel(2)->hours(); $this->observe();
        Bus::assertDispatchedTimes(TriageLogErrorJob::class, 1);
        self::assertSame(22, $row->fresh()->occurrence_count);
        $this->travel(5)->hours(); $this->observe();
        Bus::assertDispatchedTimes(TriageLogErrorJob::class, 2);
        config(['log_error_triage.build_sha' => '1234567']); $this->observe();
        self::assertSame(2, LogErrorTriage::count());
        Http::assertSentCount(1);
    }

    public function test_distinct_errors_and_locations_are_not_collapsed(): void
    {
        $this->observe('First invariant failed'); $this->observe('Second invariant failed');
        $this->observe('First invariant failed', line: 124);
        self::assertSame(3, LogErrorTriage::count());
    }

    public static function blockedDecisions(): array
    {
        return [
            'low confidence' => [['confidence' => .84]], 'rounding boundary' => [['confidence' => .84999]], 'nonactionable' => [['actionability' => 'not_actionable']],
            'insufficient' => [['actionability' => 'insufficient_evidence']], 'no evidence' => [['evidence' => []]],
            'security flag' => [['security_sensitive' => true]], 'unknown identity' => [['bug_kind' => 'unknown']],
            ...array_combine(['external','configuration','expected','data','security','uncertain'], array_map(fn ($value) => [['classification' => $value]], ['external_dependency','configuration_or_environment','expected_operational_condition','data_quality_or_input','security_or_abuse_signal','uncertain'])),
        ];
    }

    #[DataProvider('blockedDecisions')]
    public function test_all_issue_gates_are_required(array $overrides): void
    {
        $row = $this->observe(); $this->fake($this->decision($row, $overrides)); $this->runJob($row);
        self::assertSame('classified', $row->fresh()->status);
        self::assertNull($row->fresh()->github_issue_number); Http::assertSentCount(1);
    }

    public static function malformedDecisions(): array
    {
        return [['classification', 'invented'], ['confidence', 1.2], ['confidence', '0.99'], ['evidence', 'not an array'], ['evidence', ['person@example.com']], ['suspected_component', 'private-user'], ['actionability', 'yes']];
    }

    #[DataProvider('malformedDecisions')]
    public function test_malformed_or_ungrounded_result_fails_safe(string $field, mixed $value): void
    {
        $row = $this->observe(); $this->fake($this->decision($row, [$field => $value])); $this->runJob($row);
        self::assertSame('failed', $row->fresh()->status); self::assertNull($row->fresh()->github_issue_number); Http::assertSentCount(1);
        self::assertStringNotContainsString('person@example.com', json_encode($row->fresh()->toArray()));
    }

    public function test_actionable_code_bug_creates_and_audits_governed_metadata(): void
    {
        $row = $this->observe(); $this->fake($this->decision($row)); $this->runJob($row);
        $row->refresh(); self::assertSame(43, $row->github_issue_number); self::assertSame('linked', $row->report_status);
        self::assertSame(2, $row->prompt_version); self::assertSame('test-path', $row->provider_path);
        self::assertSame(1, DB::table('stox_log_triage_decisions')->count());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row->fingerprint);
    }

    public function test_duplicate_open_issue_reused_and_worker_lease_blocks_parallel_inference(): void
    {
        $row = $this->observe(); $this->fake($this->decision($row), true);
        $row->update(['lease_until' => now()->addHour()]); $this->runJob($row); Http::assertNothingSent();
        $row->update(['lease_until' => null]); $this->runJob($row);
        self::assertSame(42, $row->fresh()->github_issue_number);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), 'github.com'));
    }

    public function test_ai_failure_and_recursive_errors_preserve_original_operation(): void
    {
        $row = $this->observe();
        $ai = \Mockery::mock(AiRuntimeClient::class);
        $ai->shouldReceive('infer')->once()->andReturnUsing(function () {
            Log::error('Runtime failed', ['exception' => $this->exception()]);
            throw new \RuntimeException('password=private');
        });
        $this->app->instance(AiRuntimeClient::class, $ai); $this->runJob($row);
        self::assertSame(1, LogErrorTriage::count()); self::assertSame('failed', $row->fresh()->status);
        self::assertFalse(LogTriageGuard::active());
        foreach ([['skip_log_error_triage' => true], ['skip_api_failure_reporting' => true], ['capability_id' => 'ops.log_error_triage']] as $context) $this->observe('recursive', $context);
        self::assertSame(1, LogErrorTriage::count()); Http::assertNothingSent();
    }

    public function test_queue_and_persistence_failure_are_fail_open(): void
    {
        config(['log_error_triage.queue_connection' => 'sync']); $row = $this->observe();
        self::assertSame('queue_failed', $row->fresh()->failure_reason); Http::assertNothingSent();
        DB::shouldReceive('transaction')->once()->andThrow(new \RuntimeException('persistence unavailable'));
        $this->observeWithoutLookup(); self::assertFalse(LogTriageGuard::active());
    }

    private function observeWithoutLookup(): void
    {
        app(LogErrorTriageService::class)->observe($this->exception());
    }

    public function test_fingerprint_ignores_volatile_values_but_signature_changes_with_deploy(): void
    {
        $one = $this->observe('Null record 123 request 123e4567-e89b-12d3-a456-426614174000');
        $two = $this->observe('Null record 456 request 987e4567-e89b-12d3-a456-426614174999');
        self::assertSame($one->id, $two->id);
        app(LogErrorTriageService::class)->applyClassification($one, $this->decision($one));
        config(['log_error_triage.build_sha' => '7654321']);
        $three = $this->observe('Null record 789 request 000e4567-e89b-12d3-a456-426614174111');
        app(LogErrorTriageService::class)->applyClassification($three, $this->decision($three));
        self::assertNotSame($one->signature, $three->signature);
        self::assertSame($one->fresh()->fingerprint, $three->fresh()->fingerprint);
    }

    public function test_generic_diagnostic_without_concrete_frame_evidence_never_reports(): void
    {
        $row = $this->observe();
        $this->fake($this->decision($row, ['evidence' => ['Diagnostic: Null access']])); $this->runJob($row);
        self::assertNull($row->fresh()->github_issue_number); Http::assertSentCount(1);
    }

    public function test_invalid_threshold_configuration_cannot_silently_lower_the_gate(): void
    {
        config(['log_error_triage.confidence_threshold' => 'invalid']);
        $row = $this->observe(); $this->fake($this->decision($row, ['confidence' => .84])); $this->runJob($row);
        self::assertNull($row->fresh()->github_issue_number); Http::assertSentCount(1);
    }

    public function test_missing_schema_fields_and_failed_runtime_status_do_not_report(): void
    {
        $row = $this->observe(); $decision = $this->decision($row); unset($decision['actionability']);
        $this->fake($decision); $this->runJob($row);
        self::assertSame('failed', $row->fresh()->status);
        self::assertSame('invalid_classification', $row->fresh()->failure_reason);
    }

    public function test_retention_preserves_open_issues_and_prunes_after_closure_plus_ninety_days(): void
    {
        $row = $this->observe(); $this->fake($this->decision($row)); $this->runJob($row);
        $this->travel(91)->days();
        config(['log_error_triage.enabled' => false]);
        $this->artisan('ops:maintain-log-triages')->assertSuccessful();
        self::assertSame(1, LogErrorTriage::count());
        DB::table('stox_github_issue_bindings')->update(['state' => 'closed', 'closed_at' => now()->subDays(89)]);
        $this->artisan('ops:maintain-log-triages')->assertSuccessful(); self::assertSame(1, LogErrorTriage::count());
        $this->travel(2)->days();
        $this->artisan('ops:maintain-log-triages')->assertSuccessful(); self::assertSame(0, LogErrorTriage::count());
        self::assertSame(0, DB::table('stox_log_triage_decisions')->count());
    }

    public function test_admin_lists_and_details_are_safe_and_authorized(): void
    {
        $row = $this->observe('Null person@example.com'); $this->fake($this->decision($row)); $this->runJob($row);
        $this->getJson('/api/admin/log-error-triages')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/admin/log-error-triages')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $response = $this->getJson('/api/admin/log-error-triages?classification=code_bug&min_confidence=0.9')->assertOk()->assertJsonPath('triages.total', 1)->assertJsonPath('decision_ttl_seconds', 21600);
        self::assertStringNotContainsString('person@example.com', $response->getContent());
        self::assertStringNotContainsString('fake-test-token', $response->getContent());
        $this->getJson('/api/admin/log-error-triages/'.$row->id)->assertOk()->assertJsonPath('triage.github_issue_number', 43)->assertJsonPath('decisions.total', 1);
    }
}
