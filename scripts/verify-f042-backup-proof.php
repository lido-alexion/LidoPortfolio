<?php

declare(strict_types=1);

/**
 * Offline verifier for a custodian-signed F042 backup and isolated-restore
 * receipt. It reads files only; it never loads application configuration or
 * connects to a database.
 *
 * Usage: php scripts/verify-f042-backup-proof.php RECEIPT.json RECEIPT.sig TRUSTED_PUBLIC_KEY.pem FACTOR_ID STOCK_ID PREFLIGHT_SHA256 PROTECTED_ROWS_SHA256
 */

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

if ($argc !== 8) {
    fail('Usage: php scripts/verify-f042-backup-proof.php RECEIPT.json RECEIPT.sig TRUSTED_PUBLIC_KEY.pem FACTOR_ID STOCK_ID PREFLIGHT_SHA256 PROTECTED_ROWS_SHA256');
}

[$script, $receiptPath, $signaturePath, $publicKeyPath, $expectedFactorId, $expectedStockId, $expectedPreflightSha256, $expectedProtectedRowsSha256] = $argv;
foreach ([$receiptPath, $signaturePath, $publicKeyPath] as $path) {
    if (! is_file($path) || ! is_readable($path)) {
        fail("Required proof file is missing or unreadable: {$path}");
    }
}

$receiptBytes = file_get_contents($receiptPath);
$signature = file_get_contents($signaturePath);
$publicKey = openssl_pkey_get_public(file_get_contents($publicKeyPath));
if ($receiptBytes === false || $signature === false || $publicKey === false) {
    fail('Could not read receipt, signature, or trusted public key.');
}
if (openssl_verify($receiptBytes, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
    fail('Receipt signature is invalid for the supplied trusted public key.');
}

try {
    $receipt = json_decode($receiptBytes, true, flags: JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fail('Receipt is not valid JSON.');
}
if (! is_array($receipt) || ($receipt['format'] ?? null) !== 'stox-f042-backup-restore-proof-v1') {
    fail('Unsupported receipt format.');
}
if (! ctype_digit($expectedFactorId) || ! ctype_digit($expectedStockId)
    || ! preg_match('/^[a-f0-9]{64}$/', $expectedPreflightSha256)
    || ! preg_match('/^[a-f0-9]{64}$/', $expectedProtectedRowsSha256)) {
    fail('Expected factor, stock, preflight, or protected-row binding is invalid.');
}

$required = [
    'factor_id', 'stock_id', 'preflight_sha256', 'protected_rows_sha256',
    'backup_file', 'backup_sha256', 'backup_created_at', 'source_database_id',
    'restore_report_file', 'restore_report_sha256', 'restored_database_id',
    'restore_completed_at', 'restore_status', 'full_restore', 'isolated_restore',
];
foreach ($required as $key) {
    if (! array_key_exists($key, $receipt)) {
        fail("Receipt is missing required field: {$key}");
    }
}

foreach (['preflight_sha256', 'protected_rows_sha256', 'backup_sha256', 'restore_report_sha256'] as $key) {
    if (! is_string($receipt[$key]) || ! preg_match('/^[a-f0-9]{64}$/', $receipt[$key])) {
        fail("Receipt has invalid SHA-256 field: {$key}");
    }
}
if (! is_int($receipt['factor_id']) || $receipt['factor_id'] < 1
    || ! is_int($receipt['stock_id']) || $receipt['stock_id'] < 1) {
    fail('Receipt factor_id and stock_id must be positive integers.');
}
if ($receipt['factor_id'] !== (int) $expectedFactorId || $receipt['stock_id'] !== (int) $expectedStockId
    || ! hash_equals($expectedPreflightSha256, $receipt['preflight_sha256'])
    || ! hash_equals($expectedProtectedRowsSha256, $receipt['protected_rows_sha256'])) {
    fail('Signed receipt does not match the independently supplied F042 factor/preflight/row binding.');
}
if ($receipt['restore_status'] !== 'passed' || $receipt['full_restore'] !== true
    || $receipt['isolated_restore'] !== true) {
    fail('Receipt does not attest a passed full restore into an isolated target.');
}
if (! is_string($receipt['source_database_id']) || $receipt['source_database_id'] === ''
    || ! is_string($receipt['restored_database_id']) || $receipt['restored_database_id'] === ''
    || hash_equals($receipt['source_database_id'], $receipt['restored_database_id'])) {
    fail('Restore target must have a distinct, identified database instance.');
}

try {
    $backupCreated = new DateTimeImmutable((string) $receipt['backup_created_at']);
    $restoreCompleted = new DateTimeImmutable((string) $receipt['restore_completed_at']);
} catch (Throwable) {
    fail('Receipt timestamps must be valid ISO-8601 values.');
}
if ($backupCreated >= $restoreCompleted) {
    fail('Restore completion must be later than backup creation.');
}

$receiptDirectory = dirname(realpath($receiptPath) ?: $receiptPath);
foreach ([
    ['backup_file', 'backup_sha256'],
    ['restore_report_file', 'restore_report_sha256'],
] as [$fileKey, $hashKey]) {
    $artifact = (string) $receipt[$fileKey];
    if (! str_starts_with($artifact, DIRECTORY_SEPARATOR)) {
        $artifact = $receiptDirectory.DIRECTORY_SEPARATOR.$artifact;
    }
    if (! is_file($artifact) || ! is_readable($artifact)) {
        fail("Receipt artifact is missing or unreadable: {$fileKey}");
    }
    $actualHash = hash_file('sha256', $artifact);
    if ($actualHash === false || ! hash_equals($receipt[$hashKey], $actualHash)) {
        fail("Receipt artifact digest mismatch: {$fileKey}");
    }
}

fwrite(STDOUT, sprintf(
    "PASS: signed full isolated-restore proof verified for F042 factor %d, stock %d, preflight %s\n",
    $receipt['factor_id'],
    $receipt['stock_id'],
    $receipt['preflight_sha256'],
));
