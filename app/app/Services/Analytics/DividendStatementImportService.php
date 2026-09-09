<?php

namespace App\Services\Analytics;

use App\Models\Dividend;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class DividendStatementImportService
{
    public const DEFINITION_VERSION = 'generic-dividend-csv.v1';

    /** @return array<string, mixed> */
    public function import(User $user, UploadedFile $file, bool $dryRun): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        $header = fgetcsv($handle);
        $expected = ['date', 'symbol', 'amount', 'reference'];
        $normalizedHeader = array_map(fn ($value) => strtolower(trim((string) $value)), $header ?: []);
        if ($normalizedHeader !== $expected) {
            fclose($handle);

            return [
                'definition_version' => self::DEFINITION_VERSION,
                'dry_run' => $dryRun,
                'valid' => false,
                'imported' => 0,
                'duplicates' => 0,
                'errors' => [['row' => 1, 'message' => 'Expected columns: '.implode(',', $expected)]],
            ];
        }

        $rows = [];
        $errors = [];
        $duplicates = 0;
        $seen = [];
        $line = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $line++;
            if ($values === [null] || $values === []) {
                continue;
            }
            if (count($values) !== count($expected)) {
                $errors[] = ['row' => $line, 'message' => 'Column count does not match the definition.'];
                continue;
            }
            $row = array_combine($expected, array_map(fn ($value) => trim((string) $value), $values));
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $row['date']);
            $amount = filter_var($row['amount'], FILTER_VALIDATE_FLOAT);
            $symbol = strtoupper($row['symbol']);
            $stock = Stock::query()->where('symbol', $symbol)->first();
            if ($date === false || $date->format('Y-m-d') !== $row['date'] || $row['date'] > now()->toDateString()) {
                $errors[] = ['row' => $line, 'message' => 'date must be a non-future YYYY-MM-DD date.'];
                continue;
            }
            if ($amount === false || $amount <= 0) {
                $errors[] = ['row' => $line, 'message' => 'amount must be greater than zero.'];
                continue;
            }
            if ($symbol === '' || $stock === null) {
                $errors[] = ['row' => $line, 'message' => 'symbol is not present in the stock master.'];
                continue;
            }
            $dedupeKey = hash('sha256', implode('|', [
                $user->id, $stock->id, $row['date'], number_format((float) $amount, 4, '.', ''), strtolower($row['reference']),
            ]));
            if (isset($seen[$dedupeKey]) || Dividend::query()->where('user_id', $user->id)->where('deduplication_key', $dedupeKey)->exists()) {
                $duplicates++;
                continue;
            }
            $seen[$dedupeKey] = true;
            $rows[] = [
                'user_id' => $user->id,
                'stock_id' => $stock->id,
                'received_on' => $row['date'],
                'amount' => (float) $amount,
                'currency' => 'INR',
                'source' => 'broker_statement',
                'source_reference' => $row['reference'] !== '' ? $row['reference'] : null,
                'deduplication_key' => $dedupeKey,
                'source_evidence' => json_encode([
                    'definition_version' => self::DEFINITION_VERSION,
                    'row' => $line,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        fclose($handle);

        if (! $dryRun && $errors === [] && $rows !== []) {
            DB::transaction(fn () => Dividend::query()->insert($rows));
        }

        return [
            'definition_version' => self::DEFINITION_VERSION,
            'dry_run' => $dryRun,
            'valid' => $errors === [],
            'candidate_rows' => count($rows),
            'imported' => ! $dryRun && $errors === [] ? count($rows) : 0,
            'duplicates' => $duplicates,
            'errors' => $errors,
        ];
    }
}
