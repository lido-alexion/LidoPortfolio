<?php

namespace App\Console\Commands;

use App\Models\CashAccount;
use App\Models\CashLedgerEntry;
use App\Models\PortfolioProfile;
use App\Models\User;
use App\Models\Transaction;
use App\Services\SourceCashReconciliationService;
use App\Services\CashReconstructionCleanupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** One-shot, profile-1-only correction guarded by private source evidence and an exact baseline hash. */
class CleanupCashArtifactsCommand extends Command
{
    protected $signature = 'cash:cleanup-reconstruction-artifacts {--profile= : Must be 1} {--source-evidence= : Absolute private normalized source JSON} {--apply : Apply after preflight} {--rollback= : Restore a matching pre-change backup by SHA-256} {--backup-dir= : Absolute private directory for backup and apply receipt}';
    protected $description = 'Dry-run/apply/rollback the exact profile 1 cash reconstruction cleanup';

    // Exact 203-row profile-1 snapshot, recomputed read-only on 2026-11-20. Any subsequent edit fails closed.
    private const BASELINE_SHA256 = 'eb4416a8757ee8859e4a15c78bad78fbb607e20de54c619b2d5d580c3a48e354';
    private const OPENING_ID = CashReconstructionCleanupService::OPENING_ID;
    private const OLD_OPENING = CashReconstructionCleanupService::OLD_OPENING;
    private const NEW_OPENING = CashReconstructionCleanupService::NEW_OPENING;
    private const EXPECTED_ACCOUNT = CashReconstructionCleanupService::EXPECTED_ACCOUNT;
    private const ARTIFACTS = CashReconstructionCleanupService::ARTIFACTS;

    public function handle(SourceCashReconciliationService $reconciler): int
    {
        try {
            if ((string) $this->option('profile') !== '1') throw new RuntimeException('--profile=1 is mandatory.');
            $evidencePath = (string) $this->option('source-evidence');
            if (! str_starts_with($evidencePath, '/') || ! is_file($evidencePath) || ! is_readable($evidencePath)) throw new RuntimeException('--source-evidence must be a readable absolute private path.');
            $this->assertPrivatePath($evidencePath, false);
            $evidence = json_decode((string) file_get_contents($evidencePath), true, 512, JSON_THROW_ON_ERROR);
            $proof = $reconciler->reconcile($evidence);
            $this->assertSourceProof($proof);
            $backupDir = (string) ($this->option('backup-dir') ?: '');
            if ($backupDir !== '' && (! str_starts_with($backupDir, '/') || ! is_dir($backupDir))) throw new RuntimeException('--backup-dir must be an existing absolute private directory.');
            if ($backupDir !== '') $this->assertPrivatePath($backupDir, true);

            if ($this->option('rollback')) {
                $this->assertTransactionSourceRows($evidence, false, false);
                $this->assertAdminConfirmation('Rollback this exact profile 1 backup?');
                return $this->rollback((string) $this->option('rollback'), $backupDir);
            }
            $this->assertTransactionSourceRows($evidence, false);
            $snapshot = $this->lockedSnapshot(false);
            $hash = $this->snapshotHash($snapshot['rows']->all());
            $this->assertBaseline($snapshot, $hash);
            $plan = ['mode' => $this->option('apply') ? 'apply_preflight' : 'dry_run', 'profile_id' => 1,
                'source_evidence_sha256' => hash_file('sha256', $evidencePath), 'source_counts' => ['trades' => 89, 'bank_flows' => 97, 'account_charges' => 11, 'dp_information_only' => 11],
                'baseline_sha256' => $hash,
                'snapshot_hash_recipe' => 'profile_id=1 rows ordered by id; hash each Eloquent getAttributes() row after removing entry_classification, JSON_UNESCAPED_SLASHES, SHA-256; hash the JSON list of those rows with the same flags',
                'baseline_row_sha256' => $this->snapshotRowHashes($snapshot['rows']->all()),
                'opening_old' => self::OLD_OPENING, 'opening_new_derived_residual' => self::NEW_OPENING,
                'artifact_ids_to_delete' => array_keys(self::ARTIFACTS), 'expected_ledger_count_after' => 198,
                'expected_balance_after' => self::EXPECTED_ACCOUNT, 'writes_performed' => false];
            if (! $this->option('apply')) { $this->line(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); return self::SUCCESS; }

            $operatorId = $this->assertAdminConfirmation('This updates only profile 1 StoX cash rows. Continue?');
            if ($backupDir === '' || ! is_dir($backupDir) || ! is_writable($backupDir)) throw new RuntimeException('Apply requires an existing writable private --backup-dir outside the repository.');
            $backup = ['format' => 'stox-cash-cleanup-backup-v1', 'profile_id' => 1, 'captured_at' => now()->toISOString(), 'operator_user_id' => $operatorId,
                'account' => $snapshot['account']->getAttributes(), 'rows' => array_map(fn ($row) => $row->getAttributes(), $snapshot['rows'])];
            $backupJson = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
            $backupSha = hash('sha256', $backupJson);
            $backupPath = rtrim($backupDir, '/').'/cash-cleanup-profile1-'.$backupSha.'.json';
            if (file_put_contents($backupPath, $backupJson, LOCK_EX) === false) throw new RuntimeException('Could not write pre-change backup.');
            chmod($backupPath, 0600);

            $postHash = DB::transaction(function () use ($hash, $evidence, $backupSha, $backupDir) {
                $current = $this->lockedSnapshot(true);
                $this->assertBaseline($current, $this->snapshotHash($current['rows']->all()));
                if ($this->snapshotHash($current['rows']->all()) !== $hash) throw new RuntimeException('Ledger changed after preflight.');
                $this->assertTransactionSourceRows($evidence, true);
                $projectedRows = app(CashReconstructionCleanupService::class)->project($current['rows']->map(fn ($row) => $row->getAttributes())->all());
                CashLedgerEntry::query()->where('profile_id', 1)->whereKey(array_keys(self::ARTIFACTS))->delete();
                $persistedRows = CashLedgerEntry::query()->where('profile_id', 1)->lockForUpdate()->get()->keyBy('id');
                foreach ($projectedRows as $attributes) {
                    $row = $persistedRows->get((int) $attributes['id']);
                    if (! $row) throw new RuntimeException('Projected profile 1 row disappeared during cleanup.');
                    $row->forceFill(['amount' => $attributes['amount'], 'balance_after' => $attributes['balance_after'],
                        'entry_classification' => $attributes['entry_classification'] ?? null, 'reason' => $attributes['reason']])->save();
                }
                $balance = (float) end($projectedRows)['balance_after'];
                $rows = CashLedgerEntry::query()->where('profile_id', 1)->orderBy('id')->lockForUpdate()->get();
                if ($rows->count() !== 198 || abs($balance - (float) self::EXPECTED_ACCOUNT) > 0.00005) throw new RuntimeException('Post-mutation row count or ending balance mismatch.');
                $account = CashAccount::query()->where('profile_id', 1)->lockForUpdate()->firstOrFail();
                if (abs((float) $account->balance - (float) self::EXPECTED_ACCOUNT) > 0.00005) throw new RuntimeException('Cash account balance does not match the source-backed expected balance.');
                $postHash = $this->snapshotHash($rows->sortBy('id')->values()->all());
                $receipt = ['format' => 'stox-cash-cleanup-apply-receipt-v1', 'backup_sha256' => $backupSha, 'post_apply_sha256' => $postHash, 'profile_id' => 1];
                $receiptPath = rtrim($backupDir, '/').'/cash-cleanup-profile1-'.$backupSha.'.applied.json';
                if (file_put_contents($receiptPath, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX) === false) throw new RuntimeException('Could not write apply receipt; transaction rolled back.');
                chmod($receiptPath, 0600);
                return $postHash;
            }, 1);
            $this->line(json_encode(['mode' => 'applied', 'backup_path' => $backupPath, 'backup_sha256' => $backupSha, 'post_apply_sha256' => $postHash, 'writes_performed' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (Throwable $error) { $this->error($error->getMessage()); return self::FAILURE; }
    }

    private function assertSourceProof(array $proof): void
    {
        if (($proof['profile_id'] ?? null) !== 1 || ($proof['transaction_cash']['count'] ?? 0) !== 89 || ($proof['bank_cash']['count'] ?? 0) !== 97
            || ($proof['account_charges']['count'] ?? 0) !== 11 || ($proof['broker_dp_evidence']['count'] ?? 0) !== 11
            || ($proof['broker_trade_link_count'] ?? null) !== 0 || ($proof['cash_event_count'] ?? 0) !== 197 || ($proof['opening_balance']['amount'] ?? null) !== (float) self::NEW_OPENING
            || ($proof['closing_balance'] ?? null) !== (float) self::EXPECTED_ACCOUNT) {
            throw new RuntimeException('Source proof totals/counts do not support the exact approved profile 1 cleanup.');
        }
    }

    /** Verify each of the 89 source fills against its exact profile-owned transaction and cash delta. */
    private function assertTransactionSourceRows(array $evidence, bool $lock, bool $validateSnapshotCounts = true): void
    {
        $sourceRows = $evidence['transactions'] ?? [];
        $transactionQuery = Transaction::query()->where('profile_id', 1);
        if ($lock) $transactionQuery->lockForUpdate();
        $transactions = $transactionQuery->get()->keyBy('id');
        if (! is_array($sourceRows) || count($sourceRows) !== 89 || $transactions->count() !== 89) {
            throw new RuntimeException('Fail closed: profile 1 does not contain the exact 89 source-linked trades.');
        }
        foreach ($sourceRows as $source) {
            $id = (int) ($source['id'] ?? 0);
            $trade = $transactions->get($id);
            if (! $trade || (string) $trade->transaction_date?->toDateString() !== (string) ($source['date'] ?? '')
                || strtolower((string) $trade->type) !== strtolower((string) ($source['type'] ?? ''))
                || abs((float) $trade->quantity - (float) ($source['quantity'] ?? NAN)) > 0.00005
                || abs((float) $trade->price - (float) ($source['price'] ?? NAN)) > 0.00005
                || abs((float) $trade->fees - (float) ($source['fees'] ?? NAN)) > 0.00005) {
                throw new RuntimeException("Fail closed: source trade row {$id} differs from the profile 1 transaction.");
            }
            $expected = $trade->type === 'buy'
                ? -round((float) $trade->quantity * (float) $trade->price + (float) $trade->fees, 4)
                : round((float) $trade->quantity * (float) $trade->price - (float) $trade->fees, 4);
            $cash = CashLedgerEntry::query()->where('profile_id', 1)->where('transaction_id', $trade->id)
                ->whereIn('entry_type', [CashLedgerEntry::TYPE_BUY, CashLedgerEntry::TYPE_SELL])->orderBy('id')->first();
            if (! $cash || abs((float) $cash->amount - $expected) > 0.00005) throw new RuntimeException("Fail closed: trade {$id} cash ledger delta does not independently match its fees and notional.");
        }
        if (! $validateSnapshotCounts) return;
        $rows = CashLedgerEntry::query()->where('profile_id', 1)->get();
        if ($rows->where('entry_type', CashLedgerEntry::TYPE_BUY)->count() + $rows->where('entry_type', CashLedgerEntry::TYPE_SELL)->count() !== 89
            || $rows->where('entry_type', CashLedgerEntry::TYPE_DEPOSIT)->count() !== 52
            || $rows->where('entry_type', CashLedgerEntry::TYPE_WITHDRAWAL)->count() !== 45
            || $rows->where('entry_type', CashLedgerEntry::TYPE_ADJUSTMENT)->count() !== 17) {
            throw new RuntimeException('Fail closed: source-linked trade/bank/charge/artifact ledger category counts differ from the approved snapshot.');
        }
        $expectedBankIn = array_sum(array_map(fn ($row) => strtolower((string) ($row['type'] ?? '')) === 'deposit' ? (float) $row['amount'] : 0.0, $evidence['bank_flows'] ?? []));
        $expectedBankOut = array_sum(array_map(fn ($row) => strtolower((string) ($row['type'] ?? '')) === 'withdrawal' ? (float) $row['amount'] : 0.0, $evidence['bank_flows'] ?? []));
        if (abs((float) $rows->where('entry_type', CashLedgerEntry::TYPE_DEPOSIT)->sum('amount') - $expectedBankIn) > 0.00005
            || abs(abs((float) $rows->where('entry_type', CashLedgerEntry::TYPE_WITHDRAWAL)->sum('amount')) - $expectedBankOut) > 0.00005) {
            throw new RuntimeException('Fail closed: exact source bank-flow totals do not match profile 1 ledger.');
        }
        $actualBank = $rows->reject(fn ($row) => (int) $row->id === self::OPENING_ID)
            ->filter(fn ($row) => in_array($row->entry_type, [CashLedgerEntry::TYPE_DEPOSIT, CashLedgerEntry::TYPE_WITHDRAWAL], true))
            ->map(fn ($row) => $row->entry_date?->toDateString().'|'.number_format((float) $row->amount, 4, '.', ''))->sort()->values()->all();
        $expectedBank = array_map(function ($row) {
            $amount = strtolower((string) $row['type']) === 'withdrawal' ? -(float) $row['amount'] : (float) $row['amount'];
            return (string) $row['date'].'|'.number_format($amount, 4, '.', '');
        }, $evidence['bank_flows'] ?? []);
        sort($expectedBank);
        if ($actualBank !== $expectedBank) throw new RuntimeException('Fail closed: bank-flow source date/amount rows do not match exactly.');
        $chargeTotal = array_sum(array_map(fn ($row) => (float) $row['amount'], $evidence['account_charges'] ?? []));
        $artifactTotal = array_sum(array_map(fn ($row) => (float) $row['amount'], self::ARTIFACTS));
        if (abs((float) $rows->where('entry_type', CashLedgerEntry::TYPE_ADJUSTMENT)->sum('amount') - (float) self::OLD_OPENING - $artifactTotal + $chargeTotal) > 0.00005) {
            throw new RuntimeException('Fail closed: exact account-charge total differs from adjustment ledger rows.');
        }
        $actualCharges = $rows->where('entry_type', CashLedgerEntry::TYPE_ADJUSTMENT)->reject(fn ($row) => (int) $row->id === self::OPENING_ID || array_key_exists((int) $row->id, self::ARTIFACTS))
            ->map(fn ($row) => $row->entry_date?->toDateString().'|'.number_format((float) $row->amount, 4, '.', ''))->sort()->values()->all();
        $expectedCharges = array_map(fn ($row) => (string) $row['date'].'|'.number_format(-(float) $row['amount'], 4, '.', ''), $evidence['account_charges'] ?? []);
        sort($expectedCharges);
        if ($actualCharges !== $expectedCharges) throw new RuntimeException('Fail closed: account-charge source date/amount rows do not match exactly.');
    }

    private function assertAdminConfirmation(string $prompt): int
    {
        $operatorId = (int) $this->ask('Admin user ID authorizing this one-shot profile 1 operation');
        $operator = User::query()->find($operatorId);
        if (! $operator || ! $operator->is_admin) throw new RuntimeException('Operation requires an existing admin operator user.');
        if (! $this->confirm($prompt, false)) throw new RuntimeException('Operation was not confirmed.');
        return $operatorId;
    }

    private function assertPrivatePath(string $path, bool $directory): void
    {
        $real = realpath($path);
        $repo = realpath(base_path());
        if ($real === false || $repo === false || $real === $repo || str_starts_with($real, $repo.DIRECTORY_SEPARATOR)) throw new RuntimeException('Private evidence and backup paths must be outside the repository.');
        $privateDirectory = $directory ? $real : dirname($real);
        $permissions = fileperms($privateDirectory);
        if ($permissions === false || ($permissions & 0077) !== 0) throw new RuntimeException('Private evidence and backup directories must not grant group or world access.');
    }

    private function lockedSnapshot(bool $lock): array
    {
        if ($lock) PortfolioProfile::query()->whereKey(1)->lockForUpdate()->firstOrFail();
        $query = CashLedgerEntry::query()->where('profile_id', 1)->orderBy('id');
        if ($lock) $query->lockForUpdate();
        $accountQuery = CashAccount::query()->where('profile_id', 1);
        if ($lock) $accountQuery->lockForUpdate();
        $account = $accountQuery->firstOrFail();
        return ['account' => $account, 'rows' => $query->get()];
    }

    private function assertBaseline(array $snapshot, string $hash): void
    {
        if (count($snapshot['rows']) !== 203 || $hash !== self::BASELINE_SHA256 || abs((float) $snapshot['account']->balance - (float) self::EXPECTED_ACCOUNT) > 0.00005) throw new RuntimeException('Fail closed: exact 203-row profile 1 baseline hash/count/account mismatch.');
        $opening = collect($snapshot['rows'])->firstWhere('id', self::OPENING_ID);
        if (! $opening || (string) $opening->amount !== self::OLD_OPENING || $opening->entry_type !== CashLedgerEntry::TYPE_ADJUSTMENT) throw new RuntimeException('Fail closed: opening entry id 52 does not match the expected synthetic adjustment snapshot.');
        foreach (self::ARTIFACTS as $id => $expected) {
            $row = collect($snapshot['rows'])->firstWhere('id', $id);
            if (! $row || (string) $row->amount !== $expected['amount'] || $row->entry_date?->toDateString() !== $expected['date'] || (string) $row->reason !== $expected['reason'] || $row->entry_type !== CashLedgerEntry::TYPE_ADJUSTMENT) throw new RuntimeException("Fail closed: artifact row {$id} does not match the exact expected snapshot.");
        }
    }

    private function snapshotHash(array $rows): string
    {
        $data = $this->snapshotHashRows($rows);
        return hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function snapshotHashRows(array $rows): array
    {
        return array_map(function ($row) {
            $attributes = $row->getAttributes();
            unset($attributes['entry_classification']);
            return $attributes;
        }, $rows);
    }

    /** @return array<string, string> Per-row diagnostic hashes, keyed by ledger ID. */
    private function snapshotRowHashes(array $rows): array
    {
        $hashes = [];
        foreach ($this->snapshotHashRows($rows) as $attributes) {
            $hashes[(string) $attributes['id']] = hash('sha256', json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        return $hashes;
    }

    private function rollback(string $sha, string $backupDir): int
    {
        if ($backupDir === '' || ! preg_match('/^[a-f0-9]{64}$/', $sha)) throw new RuntimeException('Rollback requires --backup-dir and a 64-character backup SHA.');
        $path = rtrim($backupDir, '/').'/cash-cleanup-profile1-'.$sha.'.json';
        if (! is_file($path) || ! hash_equals($sha, hash_file('sha256', $path))) throw new RuntimeException('Rollback backup is missing or its SHA-256 differs.');
        $backup = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($backup['format'] ?? null) !== 'stox-cash-cleanup-backup-v1' || ($backup['profile_id'] ?? null) !== 1 || count($backup['rows'] ?? []) !== 203) throw new RuntimeException('Rollback backup schema/profile/count mismatch.');
        $receiptPath = rtrim($backupDir, '/').'/cash-cleanup-profile1-'.$sha.'.applied.json';
        if (! is_file($receiptPath)) throw new RuntimeException('Rollback apply receipt is missing; refusing to overwrite current ledger state.');
        $receipt = json_decode((string) file_get_contents($receiptPath), true, 512, JSON_THROW_ON_ERROR);
        if (($receipt['backup_sha256'] ?? null) !== $sha || ($receipt['format'] ?? null) !== 'stox-cash-cleanup-apply-receipt-v1') throw new RuntimeException('Rollback apply receipt does not match backup.');
        DB::transaction(function () use ($backup, $receipt) {
            PortfolioProfile::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            CashAccount::query()->where('profile_id', 1)->lockForUpdate()->firstOrFail();
            $current = CashLedgerEntry::query()->where('profile_id', 1)->orderBy('id')->lockForUpdate()->get();
            if ($current->count() !== 198 || $this->snapshotHash($current->all()) !== ($receipt['post_apply_sha256'] ?? null)) throw new RuntimeException('Current ledger differs from the exact applied state; rollback refused.');
            $account = CashAccount::query()->where('profile_id', 1)->firstOrFail();
            if (abs((float) $account->balance - (float) self::EXPECTED_ACCOUNT) > 0.00005) throw new RuntimeException('Cash account balance differs from the exact applied state; rollback refused.');
            // Restore in place to preserve original IDs, self-references and audit chronology.
            // Eloquent::create() would silently discard `id` (not mass assignable).
            $existingIds = array_fill_keys(array_map('intval', $current->modelKeys()), true);
            foreach ($backup['rows'] as $attributes) {
                $id = (int) ($attributes['id'] ?? 0);
                if ($id <= 0 || (int) ($attributes['profile_id'] ?? 0) !== 1) {
                    throw new RuntimeException('Backup row has an unexpected ID or profile.');
                }
                if (isset($existingIds[$id])) {
                    $changes = $attributes;
                    unset($changes['id']);
                    DB::table('portfolio_cash_ledger_entries')->where('profile_id', 1)->where('id', $id)->update($changes);
                } else {
                    DB::table('portfolio_cash_ledger_entries')->insert($attributes);
                }
            }
            CashAccount::query()->where('profile_id', 1)->update(['balance' => $backup['account']['balance']]);
            $restored = $this->lockedSnapshot(true);
            $this->assertBaseline($restored, $this->snapshotHash($restored['rows']->all()));
        }, 1);
        $this->info('Rollback completed from SHA-256 verified private backup.');
        return self::SUCCESS;
    }
}
