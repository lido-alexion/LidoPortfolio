<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$verifier = $root.'/scripts/verify-f042-backup-proof.php';
$scratch = sys_get_temp_dir().'/f042-proof-test-'.bin2hex(random_bytes(6));
mkdir($scratch, 0700);

$runVerifier = static function (string $receipt, string $signature, string $publicKey): array {
    $process = proc_open(
        [PHP_BINARY, $GLOBALS['verifier'], $receipt, $signature, $publicKey, '27', '7096', str_repeat('a', 64), str_repeat('b', 64)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};

try {
    $GLOBALS['verifier'] = $verifier;
    $backup = $scratch.'/backup.sql';
    $restoreReport = $scratch.'/restore.json';
    file_put_contents($backup, 'disposable backup test fixture');
    file_put_contents($restoreReport, '{"result":"restored in isolated fixture"}');
    $receiptPath = $scratch.'/receipt.json';
    $signaturePath = $scratch.'/receipt.sig';
    $keyPath = $scratch.'/trusted-public.pem';
    $privatePath = $scratch.'/test-private.pem';

    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    if ($key === false || ! openssl_pkey_export($key, $privatePem)) {
        throw new RuntimeException('Could not generate a temporary verifier test key.');
    }
    file_put_contents($privatePath, $privatePem, LOCK_EX);
    chmod($privatePath, 0600);
    $details = openssl_pkey_get_details($key);
    file_put_contents($keyPath, $details['key']);

    $receipt = [
        'format' => 'stox-f042-backup-restore-proof-v1',
        'factor_id' => 27,
        'stock_id' => 7096,
        'preflight_sha256' => str_repeat('a', 64),
        'protected_rows_sha256' => str_repeat('b', 64),
        'backup_file' => 'backup.sql',
        'backup_sha256' => hash_file('sha256', $backup),
        'backup_created_at' => '2026-11-28T10:00:00Z',
        'source_database_id' => 'source-instance',
        'restore_report_file' => 'restore.json',
        'restore_report_sha256' => hash_file('sha256', $restoreReport),
        'restored_database_id' => 'isolated-instance',
        'restore_completed_at' => '2026-11-28T10:30:00Z',
        'restore_status' => 'passed',
        'full_restore' => true,
        'isolated_restore' => true,
    ];
    $bytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    file_put_contents($receiptPath, $bytes);
    if (! openssl_sign($bytes, $signature, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Could not sign the verifier test receipt.');
    }
    file_put_contents($signaturePath, $signature);

    [$status, $stdout, $stderr] = $runVerifier($receiptPath, $signaturePath, $keyPath);
    if ($status !== 0 || ! str_contains($stdout, 'PASS:')) {
        throw new RuntimeException("Valid receipt was rejected: {$stdout}{$stderr}");
    }

    $wrongBindingProcess = proc_open(
        [PHP_BINARY, $verifier, $receiptPath, $signaturePath, $keyPath, '28', '7096', str_repeat('a', 64), str_repeat('b', 64)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $wrongBindingPipes,
    );
    fclose($wrongBindingPipes[0]);
    stream_get_contents($wrongBindingPipes[1]);
    stream_get_contents($wrongBindingPipes[2]);
    fclose($wrongBindingPipes[1]);
    fclose($wrongBindingPipes[2]);
    if (proc_close($wrongBindingProcess) === 0) {
        throw new RuntimeException('Signed receipt for another factor was accepted.');
    }

    file_put_contents($backup, 'tampered backup test fixture');
    [$status] = $runVerifier($receiptPath, $signaturePath, $keyPath);
    if ($status === 0) {
        throw new RuntimeException('Tampered backup artifact was accepted.');
    }

    fwrite(STDOUT, "PASS: valid signed receipt accepted; changed backup artifact rejected.\n");
} finally {
    foreach (glob($scratch.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($scratch);
}
