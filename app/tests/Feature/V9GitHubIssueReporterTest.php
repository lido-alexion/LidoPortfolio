<?php

namespace Tests\Feature;

use App\Jobs\CreateOrLinkGitHubIssueJob;
use App\Models\ApiFailureIncident;
use App\Services\Operations\ApiFailureReporter;
use App\Services\Operations\GitHubIssueClient;
use App\Services\Operations\GitHubIssueReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class V9GitHubIssueReporterTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake(); Http::preventStrayRequests();
        $this->marker = '<!-- stox-log-bug:'.str_repeat('a', 64).' -->';
        config(['api_failure_reporting.enabled' => true, 'api_failure_reporting.environments' => ['testing'], 'api_failure_reporting.token' => 'shared-fake-token']);
    }

    private function report(?string $marker = null): array
    {
        return app(GitHubIssueReporter::class)->report($marker ?? $this->marker, 'Safe title', 'Safe evidence '.($marker ?? $this->marker), [], now());
    }

    public function test_exact_marker_search_rejects_title_similarity_and_wrong_marker(): void
    {
        Http::fake(['*search/issues*' => Http::response(['items' => [
            ['number' => 1, 'state' => 'open', 'body' => 'similar title '.str_replace('aaa', 'bbb', $this->marker)],
            ['number' => 2, 'state' => 'open', 'body' => 'exact '.$this->marker],
        ]])]);
        self::assertSame(2, $this->report()['number']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer shared-fake-token') && $request->hasHeader('X-StoX-Skip-Api-Failure-Reporting', '1'));
    }

    public function test_known_open_binding_survives_search_index_lag_without_duplicate_creation(): void
    {
        Http::fake(fn ($r) => str_contains($r->url(), '/search/issues') ? Http::response(['items' => []]) : Http::response(['number' => 9, 'state' => 'open'], $r->method() === 'POST' ? 201 : 200));
        self::assertSame(9, $this->report()['number']);
        self::assertSame(9, $this->report()['number']);
        self::assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
        self::assertSame(1, DB::table('stox_github_issue_bindings')->count());
    }

    public function test_closed_recurrence_uses_closure_time_and_references_prior_without_reopening(): void
    {
        $closed = now()->subHours(23)->toIso8601String();
        Http::fake(function ($request) use ($closed) {
            if (str_contains($request->url(), '/search/issues')) return Http::response(['items' => str_contains($request['q'], 'is:open') ? [] : [['number' => 10,'state' => 'closed','closed_at' => $closed,'body' => $this->marker]]]);
            if ($request->method() === 'GET') return Http::response(['number' => 10,'state' => 'closed','closed_at' => $closed,'body' => $this->marker]);
            return Http::response(['number' => 11,'state' => 'open'], 201);
        });
        self::assertSame('cooldown', $this->report()['status']);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
        $this->travel(2)->hours();
        $result = $this->report(); self::assertSame(11, $result['number']);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r['body'], 'Recurrence of #10') && str_contains($r['body'], $this->marker));
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
        self::assertSame(10, json_decode(DB::table('stox_github_issue_bindings')->sole()->history, true)[0]['prior_number']);
    }

    public function test_missing_closure_time_is_anchored_once_not_extended_by_every_recurrence(): void
    {
        Http::fake(function ($r) {
            if (str_contains($r->url(), '/search/issues')) return Http::response(['items' => str_contains($r['q'], 'is:open') ? [] : [['number' => 10,'state' => 'closed','body' => $this->marker]]]);
            return Http::response(['number' => $r->method() === 'POST' ? 11 : 10,'state' => $r->method() === 'POST' ? 'open' : 'closed']);
        });
        self::assertSame('cooldown', $this->report()['status']); $this->travel(12)->hours();
        self::assertSame('cooldown', $this->report()['status']); $this->travel(13)->hours();
        self::assertSame('linked', $this->report()['status']);
    }

    public function test_global_ceiling_is_shared_with_ops002_and_resets_after_an_hour(): void
    {
        config(['api_failure_reporting.max_new_per_hour' => 1]);
        Http::fake(fn ($r) => str_contains($r->url(), '/search/issues') ? Http::response(['items' => []]) : Http::response(['number' => 12,'state' => 'open'], 201));
        $incident = app(ApiFailureReporter::class)->observe(['direction' => 'outbound_external','component' => 'test-provider','method' => 'GET','endpoint' => '/technical','http_status' => 500]);
        app()->call([new CreateOrLinkGitHubIssueJob($incident->id), 'handle']);
        self::assertSame('linked', $incident->fresh()->sync_status);
        self::assertSame('rate_limited', $this->report()['status']);
        self::assertSame(1, DB::table('stox_github_reporter_state')->value('created_count'));
        $this->travel(61)->minutes(); self::assertSame('linked', $this->report()['status']);
    }

    public function test_github_failure_opens_shared_circuit_and_fails_open(): void
    {
        Http::fake(['*' => Http::response(['error' => 'token=never-persist'], 503)]);
        for ($i=0; $i<3; $i++) self::assertSame('failed', $this->report()['status']);
        self::assertSame('circuit_open', $this->report()['status']); Http::assertSentCount(3);
        self::assertStringNotContainsString('never-persist', json_encode(DB::table('stox_github_issue_bindings')->get()));
        $this->travel(301)->seconds(); self::assertSame('failed', $this->report()['status']); Http::assertSentCount(4);
    }

    public function test_ambiguous_create_does_not_blindly_repeat_post(): void
    {
        Http::fake(fn ($r) => $r->method() === 'GET' ? Http::response(['items' => []]) : Http::response([], 503));
        self::assertSame('failed', $this->report()['status']);
        self::assertSame('creation_unknown', DB::table('stox_github_issue_bindings')->sole()->state);
        self::assertSame('reconciliation_required', $this->report()['status']);
        self::assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
    }

    public function test_ambiguous_recurrence_keeps_its_hold_across_repeated_reconciliation(): void
    {
        Http::fake(function ($r) {
            if (str_contains($r->url(), '/search/issues')) return Http::response(['items' => str_contains($r['q'], 'is:open') ? [] : [['number' => 10, 'state' => 'closed', 'closed_at' => now()->subDays(2)->toIso8601String(), 'body' => $this->marker]]]);
            if ($r->method() === 'GET') return Http::response(['number' => 10, 'state' => 'closed', 'closed_at' => now()->subDays(2)->toIso8601String()]);
            return Http::response([], 503);
        });
        self::assertSame('failed', $this->report()['status']);
        for ($i=0; $i<3; $i++) self::assertSame('reconciliation_required', $this->report()['status']);
        self::assertSame('creation_unknown', DB::table('stox_github_issue_bindings')->sole()->state);
        self::assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
    }

    public function test_reconciled_ambiguous_recurrence_advances_generation_once(): void
    {
        $visible = false;
        Http::fake(function ($r) use (&$visible) {
            if (str_contains($r->url(), '/search/issues')) {
                $issue = $visible
                    ? ['number' => 11, 'state' => 'open', 'body' => $this->marker]
                    : ['number' => 10, 'state' => 'closed', 'closed_at' => now()->subDays(2)->toIso8601String(), 'body' => $this->marker];
                return Http::response(['items' => ! $visible && str_contains($r['q'], 'is:open') ? [] : [$issue]]);
            }
            return Http::response([], 503);
        });
        self::assertSame('failed', $this->report()['status']);
        $visible = true;
        $result = $this->report();
        self::assertSame(11, $result['number']);
        self::assertSame(2, $result['generation']);
        self::assertSame(2, $this->report()['generation']);
        $history = json_decode(DB::table('stox_github_issue_bindings')->sole()->history, true);
        self::assertCount(1, $history);
        self::assertSame(10, $history[0]['prior_number']);
        self::assertSame(11, $history[0]['number']);
        self::assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
    }

    public function test_global_worker_lock_prevents_concurrent_creation(): void
    {
        $lock = Cache::store('database')->lock('stox-github-report-global', 180); self::assertTrue($lock->get());
        try { self::assertSame('busy', $this->report()['status']); Http::assertNothingSent(); }
        finally { $lock->release(); }
    }

    public function test_missing_label_retries_without_labels_and_no_new_transport(): void
    {
        Http::fakeSequence()->push(['errors' => [['field' => 'labels']]], 422)->push(['number' => 22, 'state' => 'open'], 201);
        self::assertSame(22, app(GitHubIssueClient::class)->create('Safe title', 'safe body', ['missing'])['number']);
        $requests = Http::recorded(); self::assertSame([], $requests[1][0]['labels']);
    }

    public function test_disabled_environment_and_incomplete_search_never_create(): void
    {
        config(['api_failure_reporting.environments' => ['production']]);
        self::assertSame('disabled', $this->report()['status']); Http::assertNothingSent();
        config(['api_failure_reporting.environments' => ['testing']]);
        Http::fake(['*' => Http::response(['items' => [], 'incomplete_results' => true])]);
        self::assertSame('failed', $this->report()['status']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }
}
