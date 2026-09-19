<?php

namespace App\Services\Fundamentals;

use App\Models\Stock;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use RuntimeException;

class YahooFundamentalDataProvider implements FundamentalDataProvider
{
    public function __construct(
        private readonly ?string $pythonBinary = null,
        private readonly ?string $adapterScript = null,
        private readonly ?float $timeoutSeconds = null,
        private readonly ?int $maxOutputBytes = null,
    ) {}

    public function fetch(Stock $stock, string $cadence): array
    {
        if (! in_array($cadence, [FundamentalDataService::CADENCE_QUARTERLY, FundamentalDataService::CADENCE_ANNUAL], true)) {
            throw new RuntimeException('Yahoo fundamentals adapter received an unsupported cadence.');
        }

        $symbol = $stock->yahoo_symbol ?: $stock->symbol.($stock->exchange === 'BSE' ? '.BO' : '.NS');
        $python = $this->pythonBinary ?? (string) config('fundamentals.yahoo.python');
        $script = $this->adapterScript ?? (string) config('fundamentals.yahoo.adapter_script');
        $timeout = $this->timeoutSeconds ?? (float) config('fundamentals.yahoo.timeout_seconds', 45);
        $maxOutput = $this->maxOutputBytes ?? (int) config('fundamentals.yahoo.max_output_bytes', 4 * 1024 * 1024);

        if ($python === '' || ! is_file($python) || ! is_executable($python)) {
            throw new RuntimeException('Yahoo fundamentals Python runtime is unavailable.');
        }
        if ($script === '' || ! is_file($script)) {
            throw new RuntimeException('Yahoo fundamentals adapter script is unavailable.');
        }

        $process = new Process([$python, $script, $symbol, $cadence], base_path());
        $process->setTimeout($timeout);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new RuntimeException('Yahoo fundamentals adapter timed out.');
        }

        $stdout = $process->getOutput();
        $stderr = trim($process->getErrorOutput());
        if (strlen($stdout) > $maxOutput) {
            throw new RuntimeException('Yahoo fundamentals adapter output exceeded the configured limit.');
        }
        if (! $process->isSuccessful()) {
            $detail = $stderr === '' ? 'adapter exited with status '.$process->getExitCode() : $this->safeError($stderr);
            throw new RuntimeException('Yahoo fundamentals adapter failed: '.$detail);
        }

        try {
            $payload = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Yahoo fundamentals adapter returned invalid JSON.');
        }

        if (! is_array($payload)
            || ($payload['schema_version'] ?? null) !== 1
            || ($payload['symbol'] ?? null) !== $symbol
            || ($payload['cadence'] ?? null) !== $cadence
            || ! is_array($payload['statements'] ?? null)) {
            throw new RuntimeException('Yahoo fundamentals adapter returned an invalid response schema.');
        }

        return (new YahooFundamentalNormalizer)->normalizeYfinance($payload, $cadence);
    }

    private function safeError(string $error): string
    {
        $line = preg_split('/\R/', $error)[0] ?? $error;

        return substr($line, 0, 500);
    }
}
