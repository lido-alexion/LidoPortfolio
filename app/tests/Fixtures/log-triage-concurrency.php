<?php
// Isolated MySQL integration-test subprocess. Never contacts AI or GitHub.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['log_error_triage.enabled' => true, 'log_error_triage.environments' => ['testing'], 'app.env' => 'testing', 'api_failure_reporting.enabled' => false, 'app.key' => 'concurrency-fixture-key']);
if (($argv[1] ?? '') === 'observe') {
    for ($i=0; $i<10; $i++) {
        $exception = new RuntimeException('Null invariant failed');
        (new ReflectionProperty(Exception::class, 'file'))->setValue($exception, app_path('Services/PortfolioService.php'));
        (new ReflectionProperty(Exception::class, 'line'))->setValue($exception, 123);
        app(\App\Services\Operations\LogErrorTriageService::class)->observe($exception);
    }
} else {
    $app->instance(\App\Services\AI\AiRuntimeClient::class, new class extends \App\Services\AI\AiRuntimeClient {
        public function infer(string $capability, array $input, array $options = []): array
        {
            usleep(500000);
            return ['status' => 'success', 'prompt' => ['version' => 2], 'routing_trace' => [['state' => 'selected', 'path_id' => 'concurrency-test']], 'structured' => [
                'classification' => 'code_bug', 'confidence' => .94, 'summary' => 'Null invariant',
                'evidence' => $input['evidence_candidates'], 'suspected_component' => $input['component'],
                'bug_kind' => 'null_handling', 'actionability' => 'actionable', 'safe_issue_title' => 'Null invariant', 'security_sensitive' => false,
            ]];
        }
    });
    $app->call([new \App\Jobs\TriageLogErrorJob((int) $argv[2]), 'handle']);
}
