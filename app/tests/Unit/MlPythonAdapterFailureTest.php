<?php

namespace Tests\Unit;

use App\Services\ML\MlPythonAdapter;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MlPythonAdapterFailureTest extends TestCase
{
    private string $script;

    protected function setUp(): void
    {
        parent::setUp();

        $directory = storage_path('framework/testing/ml-adapter');
        File::ensureDirectoryExists($directory);
        $this->script = $directory.'/failure-fixture.php';
        File::put($this->script, <<<'PHP'
<?php
$request = json_decode(stream_get_contents(STDIN), true) ?: [];
switch ($request['mode'] ?? '') {
    case 'warning_then_failure':
        fwrite(STDERR, "OptimizeWarning: warning\nUserWarning: another warning\nml adapter failed: actual error\n");
        exit(1);
    case 'multiple_failures':
        fwrite(STDERR, "warning one\nml adapter failed: stale error\nwarning two\nml adapter failed: actual error\n");
        exit(1);
    case 'unprefixed_failure':
        fwrite(STDERR, "warning\nuseful final failure\n");
        exit(1);
    default:
        echo json_encode(['schema_version' => 1, 'score' => 0.7, 'confidence' => 0.7, 'contributions' => []]);
}
PHP);
    }

    protected function tearDown(): void
    {
        File::delete($this->script);
        parent::tearDown();
    }

    public function test_adapter_failure_after_warning_surfaces_actual_error(): void
    {
        $this->assertAdapterFailure('warning_then_failure', 'actual error');
    }

    public function test_last_adapter_failure_wins_over_earlier_failure_and_warnings(): void
    {
        $this->assertAdapterFailure('multiple_failures', 'actual error');
    }

    public function test_unprefixed_failure_uses_last_meaningful_stderr_line(): void
    {
        $this->assertAdapterFailure('unprefixed_failure', 'useful final failure');
    }

    public function test_successful_adapter_response_remains_accepted(): void
    {
        config(['ml.python' => PHP_BINARY, 'ml.adapter_script' => $this->script]);

        $result = app(MlPythonAdapter::class)->run('predict', []);

        $this->assertSame(0.7, $result['score']);
        $this->assertSame(1, $result['schema_version']);
    }

    private function assertAdapterFailure(string $mode, string $expected): void
    {
        config(['ml.python' => PHP_BINARY, 'ml.adapter_script' => $this->script]);

        $this->expectExceptionMessage('ML adapter failed: '.$expected);
        app(MlPythonAdapter::class)->run('predict', ['mode' => $mode]);
    }
}
