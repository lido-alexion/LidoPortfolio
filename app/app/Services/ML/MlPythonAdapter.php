<?php

namespace App\Services\ML;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use RuntimeException;

class MlPythonAdapter
{
    public function run(string $operation, array $payload): array
    {
        $python = (string) config('ml.python');
        $script = (string) config('ml.adapter_script');
        if ($python === '' || ! is_file($python) || ! is_executable($python)) {
            throw new RuntimeException('ML Python runtime is unavailable.');
        }
        if ($script === '' || ! is_file($script)) {
            throw new RuntimeException('ML adapter script is unavailable.');
        }

        $process = new Process([$python, $script, $operation], base_path());
        $process->setTimeout((float) config('ml.timeout_seconds', 180));
        $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new RuntimeException('ML adapter timed out.');
        }

        $stdout = $process->getOutput();
        $maxOutputBytes = (int) config('ml.max_output_bytes', 8 * 1024 * 1024);
        $stderr = $process->getErrorOutput();
        if (strlen($stderr) > $maxOutputBytes) {
            $stderr = substr($stderr, -$maxOutputBytes);
        }
        $stderr = trim($stderr);
        if (strlen($stdout) > $maxOutputBytes) {
            throw new RuntimeException('ML adapter output exceeded the configured limit.');
        }
        if (! $process->isSuccessful()) {
            $detail = $stderr === '' ? 'adapter exited with status '.$process->getExitCode() : self::failureDetail($stderr);
            throw new RuntimeException('ML adapter failed: '.$detail);
        }

        try {
            $result = json_decode($stdout, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('ML adapter returned invalid JSON.');
        }
        if (! is_array($result) || ($result['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('ML adapter returned an invalid response schema.');
        }

        $required = match ($operation) {
            'train' => ['artifact_sha256', 'metrics', 'baselines', 'metadata'],
            'predict' => ['score', 'confidence', 'contributions'],
            'drift' => ['status', 'metrics', 'warnings'],
            default => [],
        };
        foreach ($required as $key) {
            if (! array_key_exists($key, $result)) {
                throw new RuntimeException("ML adapter response is missing {$operation} field: {$key}.");
            }
        }
        if ($operation === 'drift' && ! in_array($result['status'], ['ok', 'warning', 'insufficient_data'], true)) {
            throw new RuntimeException('ML adapter returned an invalid drift status.');
        }

        return $result;
    }

    private static function failureDetail(string $stderr): string
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $stderr) ?: []),
            static fn (string $line): bool => $line !== '',
        ));
        $adapterFailures = array_values(array_filter(
            $lines,
            static fn (string $line): bool => str_starts_with($line, 'ml adapter failed:'),
        ));
        $detail = $adapterFailures !== [] ? end($adapterFailures) : ($lines !== [] ? end($lines) : 'adapter failed without diagnostics');
        if (str_starts_with($detail, 'ml adapter failed:')) {
            $detail = trim(substr($detail, strlen('ml adapter failed:')));
        }

        return substr($detail, 0, 500);
    }
}
