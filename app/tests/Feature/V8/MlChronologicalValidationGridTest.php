<?php

namespace Tests\Feature\V8;

use App\Services\ML\MlChronologicalValidationGridService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MlChronologicalValidationGridTest extends TestCase
{
    public function test_summarize_builds_monthly_windows_and_stability(): void
    {
        $dir = storage_path('framework/cache/ml-grid-test-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($dir);
        $testPath = $dir.'/test.jsonl';
        $rows = [
            ['reference_date' => '2025-01-15', 'label' => 1],
            ['reference_date' => '2025-01-20', 'label' => 0],
            ['reference_date' => '2025-02-10', 'label' => 1],
            ['reference_date' => '2025-02-12', 'label' => 1],
        ];
        file_put_contents($testPath, implode("\n", array_map(static fn (array $row): string => json_encode($row), $rows))."\n");

        $summary = app(MlChronologicalValidationGridService::class)->summarize(
            ['train' => $dir.'/missing.jsonl', 'validation' => $dir.'/missing.jsonl', 'test' => $testPath],
            [
                'train_start' => '2024-01-01',
                'test_start' => '2025-01-01',
                'test_end' => '2025-02-28',
            ],
        );

        File::deleteDirectory($dir);

        $this->assertSame('v8-chrono-grid-2', $summary['version']);
        $this->assertSame(2, $summary['test_window_count']);
        $this->assertCount(2, array_filter($summary['windows'], fn (array $w): bool => $w['partition'] === 'test'));
    }

    public function test_summarize_tags_benchmark_volatility_regime_slices_on_test_windows(): void
    {
        $dir = storage_path('framework/cache/ml-grid-regime-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($dir);
        $testPath = $dir.'/test.jsonl';
        $rows = [
            ['reference_date' => '2025-01-15', 'label' => 1, 'relative_return' => 0.02, 'features' => ['benchmark_realized_volatility_20d' => 0.30]],
            ['reference_date' => '2025-01-20', 'label' => 0, 'relative_return' => -0.01, 'features' => ['benchmark_realized_volatility_20d' => 0.32]],
            ['reference_date' => '2025-02-10', 'label' => 1, 'relative_return' => 0.01, 'features' => ['benchmark_realized_volatility_20d' => 0.05]],
            ['reference_date' => '2025-02-12', 'label' => 0, 'relative_return' => 0.00, 'features' => ['benchmark_realized_volatility_20d' => 0.06]],
        ];
        file_put_contents($testPath, implode("\n", array_map(static fn (array $row): string => json_encode($row), $rows))."\n");

        $summary = app(MlChronologicalValidationGridService::class)->summarize(
            ['train' => $dir.'/missing.jsonl', 'validation' => $dir.'/missing.jsonl', 'test' => $testPath],
            ['test_start' => '2025-01-01', 'test_end' => '2025-02-28'],
        );

        File::deleteDirectory($dir);

        $this->assertSame('ok', $summary['regime_slices']['status']);
        $this->assertSame(1, $summary['regime_slices']['slices']['high_benchmark_volatility']['window_count']);
        $this->assertSame(1, $summary['regime_slices']['slices']['low_benchmark_volatility']['window_count']);
    }
}
