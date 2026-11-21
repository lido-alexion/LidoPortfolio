<?php

namespace App\Services\Fundamentals;

use App\Models\Stock;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use RuntimeException;

class YahooFundamentalDataProvider implements FundamentalDataProvider
{
    private ?string $lastResponseHash = null;

    /** @var array<string,mixed> */
    private array $lastResponseMetadata = [];

    public function __construct(
        private readonly ?string $pythonBinary = null,
        private readonly ?string $adapterScript = null,
        private readonly ?float $timeoutSeconds = null,
        private readonly ?int $maxOutputBytes = null,
    ) {}

    public function fetch(Stock $stock, string $cadence): array
    {
        $this->lastResponseHash = null;
        $this->lastResponseMetadata = [];
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

        $process = new Process([$python, $script, $symbol, $cadence, (string) ($stock->name ?? '')], base_path());
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

        $statementNames = ['balance_sheet', 'cash_flow', 'income_statement'];
        $actualStatementNames = array_keys($payload['statements']);
        sort($actualStatementNames);
        if ($actualStatementNames !== $statementNames) {
            throw new RuntimeException('Yahoo fundamentals adapter returned an invalid statement schema.');
        }
        foreach ($statementNames as $statementName) {
            if (! is_array($payload['statements'][$statementName])) {
                throw new RuntimeException('Yahoo fundamentals adapter returned an invalid statement schema.');
            }
        }
        $statementsAreEmpty = collect($payload['statements'])->every(fn ($rows) => $rows === []);
        if (($payload['outcome'] ?? 'data') === 'no_data' && ! $statementsAreEmpty) {
            throw new RuntimeException('Yahoo fundamentals adapter outcome does not match its statement data.');
        }

        $this->lastResponseHash = hash('sha256', $stdout);
        $this->lastResponseMetadata = [
            'transport' => 'yfinance',
            'requested_symbol' => $payload['symbol'],
            'provider_symbol' => $payload['provider_symbol'] ?? $payload['symbol'],
            'identity' => is_array($payload['identity'] ?? null) ? $payload['identity'] : null,
        ];

        if (($payload['outcome'] ?? 'data') === 'no_data'
            && collect($payload['statements'])->every(fn ($rows) => is_array($rows) && $rows === [])) {
            throw new YahooNoDataOutcome('Yahoo returned valid empty statements for '.$symbol.'.');
        }
        if (! in_array($payload['outcome'] ?? 'data', ['data', 'no_data'], true)) {
            throw new RuntimeException('Yahoo fundamentals adapter returned an invalid outcome.');
        }

        $rows = (new YahooFundamentalNormalizer)->normalizeYfinance($payload, $cadence);
        if ($rows === []) {
            throw new RuntimeException('Yahoo fundamentals adapter returned statements with no parseable supported facts.');
        }

        return $rows;
    }

    public function lastResponseHash(): ?string
    {
        return $this->lastResponseHash;
    }

    /** @return array<string,mixed> */
    public function lastResponseMetadata(): array
    {
        return $this->lastResponseMetadata;
    }

    private function safeError(string $error): string
    {
        $line = preg_split('/\R/', $error)[0] ?? $error;

        return substr($line, 0, 500);
    }
}
