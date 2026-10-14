#!/usr/bin/env php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Narrow repair for historical StoX ML acceptance objects. Dry-run is default.
 * It never changes database rows or file contents and refuses unknown layouts.
 */

const REPAIR_APP = '/var/www/stoxla/current';
const REPAIR_ROOT = '/var/www/stoxla/shared/storage/app/private/ml-acceptance';
const REPAIR_AUDIT = '/var/log/stoxla/ml-acceptance-permission-repair';

function stop(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(2);
}

function parseArgs(array $argv): array
{
    $args = ['apply' => false, 'actor' => null];
    for ($i = 1; $i < count($argv); $i++) {
        if ($argv[$i] === '--apply') $args['apply'] = true;
        elseif ($argv[$i] === '--actor' && isset($argv[$i + 1])) $args['actor'] = $argv[++$i];
        elseif ($argv[$i] === '--help') {
            echo "Usage: sudo php repair-ml-acceptance-private-permissions.php [--actor OPERATOR] [--apply]\n";
            exit(0);
        } else stop('unknown or incomplete argument');
    }
    if ($args['apply'] && (!is_string($args['actor']) || !preg_match('/\A[a-zA-Z0-9._@-]{2,80}\z/', $args['actor']))) {
        stop('--apply requires --actor with the responsible operator identity');
    }
    if (!$args['apply'] && $args['actor'] !== null && !preg_match('/\A[a-zA-Z0-9._@-]{2,80}\z/', $args['actor'])) stop('invalid actor');
    return $args;
}

function safePath(string $path, string $root): void
{
    if (is_link($path)) stop("symlink encountered: {$path}");
    $real = realpath($path);
    if ($real === false || ($real !== $root && !str_starts_with($real, $root . DIRECTORY_SEPARATOR))) stop("path escapes private root: {$path}");
}

function recordObject(string $path, int $mode, string $kind, string $state, int $uid, int $gid): array
{
    $stat = lstat($path);
    if ($stat === false || is_link($path)) stop("object disappeared or is a symlink: {$path}");
    $isDir = (($stat['mode'] & 0170000) === 0040000);
    $isFile = (($stat['mode'] & 0170000) === 0100000);
    if (!$isDir && !$isFile) stop("unsupported filesystem object: {$path}");
    $hash = $isFile ? hash_file('sha256', $path) : null;
    if ($isFile && !is_string($hash)) stop("cannot hash file: {$path}");
    return ['path' => $path, 'type' => $isDir ? 'directory' : 'file', 'state' => $state,
        'before_mode' => $stat['mode'] & 07777, 'after_mode' => $mode, 'before_uid' => $stat['uid'],
        'before_gid' => $stat['gid'], 'after_uid' => $uid, 'after_gid' => $gid, 'device' => $stat['dev'],
        'inode' => $stat['ino'], 'sha256' => $hash, 'kind' => $kind];
}

function walkSource(string $dir, array $row, string $root, int $uid, int $gid): array
{
    $status = $row->status;
    if (!in_array($status, ['uploading', 'queued', 'sealed', 'failed', 'cancelled'], true)) stop("unknown source state: {$row->id}");
    $manifest = is_array($row->manifest) ? $row->manifest : json_decode((string) $row->manifest, true);
    if (!is_array($manifest) || !is_string($manifest['filename'] ?? null)) stop("invalid source manifest: {$row->id}");
    $expected = ['payload'];
    if ($status === 'sealed') {
        $evidence = is_array($row->evidence) ? $row->evidence : json_decode((string) $row->evidence, true);
        $filename = is_array($evidence) ? ($evidence['filename'] ?? null) : null;
        if (!is_string($filename) || basename($filename) !== $filename || str_contains($filename, '\\')) stop("sealed source has unsafe manifest filename: {$row->id}");
        $expected[] = $filename;
    }
    if (in_array($status, ['failed', 'cancelled'], true)) {
        $retained = preg_replace('/\.zip$/i', '', $manifest['filename']);
        if (!str_ends_with(strtolower($retained), '.csv')) $retained .= '.csv';
        $expected[] = $retained;
    }
    safePath($dir, $root);
    $items = scandir($dir);
    if ($items === false) stop("cannot list source directory: {$dir}");
    $actual = array_values(array_diff($items, ['.', '..']));
    sort($actual);
    if (in_array($status, ['failed', 'cancelled'], true)) {
        if (array_diff($actual, ['payload', $retained ?? '']) !== []) stop("retained source layout differs from manifest: {$row->id}");
    } else {
        sort($expected);
        if ($status === 'uploading' && $actual === []) $expected = [];
        if ($actual !== $expected) stop("source object layout differs from manifest: {$row->id}");
    }
    $immutable = in_array($status, ['sealed', 'failed', 'cancelled'], true);
    $ownerMode = $immutable ? 0400 : 0600;
    $kind = $status === 'sealed' ? 'sealed-payload' : ($immutable ? 'retained-readonly-payload' : 'writable-upload-payload');
    $objects = [recordObject($dir, 0700, 'source-directory', $status, $uid, $gid)];
    foreach ($actual as $name) {
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        safePath($path, $root);
        $o = recordObject($path, $ownerMode, $kind, $status, $uid, $gid);
        if ($o['type'] !== 'file') stop("source payload is not a regular file: {$path}");
        $objects[] = $o;
    }
    return $objects;
}

function walkCampaign(string $dir, string $root, int $uid, int $gid): array
{
    safePath($dir, $root);
    $objects = [recordObject($dir, 0700, 'campaign-directory', 'terminal-campaign', $uid, $gid)];
    $items = scandir($dir);
    if ($items === false) stop("cannot list campaign directory: {$dir}");
    foreach (array_diff($items, ['.', '..']) as $name) {
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        safePath($path, $root);
        if (is_dir($path)) {
            if (!in_array($name, ['1m', '3m', '6m'], true)) stop("unrecognized campaign directory: {$path}");
            $objects[] = recordObject($path, 0700, 'campaign-horizon-directory', 'terminal-campaign', $uid, $gid);
            $children = scandir($path);
            if ($children === false) stop("cannot list campaign horizon: {$path}");
            foreach (array_diff($children, ['.', '..']) as $child) {
                $file = $path . DIRECTORY_SEPARATOR . $child;
                safePath($file, $root);
                if ($child !== 'pit-evidence.jsonl.gz' || !is_file($file)) stop("unrecognized campaign evidence object: {$file}");
                $objects[] = recordObject($file, 0400, 'sealed-evidence-journal', 'terminal-campaign', $uid, $gid);
            }
        } else stop("unrecognized campaign object: {$path}");
    }
    return $objects;
}

$options = parseArgs($argv);
if (PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 0) stop('run on the production host as root via sudo');
if (!is_dir(REPAIR_APP . '/app') || !is_file(REPAIR_APP . '/artisan')) stop('expected current Laravel release not found');
if (realpath(REPAIR_ROOT) !== REPAIR_ROOT || is_link(REPAIR_ROOT)) stop('private root missing, symlinked, or non-canonical');
foreach (['/var', '/var/www', '/var/www/stoxla', '/var/www/stoxla/shared', '/var/www/stoxla/shared/storage', '/var/www/stoxla/shared/storage/app', '/var/www/stoxla/shared/storage/app/private'] as $parent) {
    if (is_link($parent) || realpath($parent) !== $parent) stop("symlinked/noncanonical parent: {$parent}");
}

require REPAIR_APP . '/vendor/autoload.php';
$app = require REPAIR_APP . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$service = trim((string) shell_exec('/usr/bin/systemctl is-active stoxla-ml-acceptance.service 2>/dev/null'));
if (!in_array($service, ['inactive', 'failed'], true)) stop("dedicated worker must be stopped (state: " . ($service ?: 'unknown') . ')');
$busy = DB::table('stox_ml_acceptance_campaigns')->whereIn('status', ['preflight', 'training'])->count();
if ($busy !== 0) stop('an acceptance campaign is active');
$queued = DB::table('jobs')->where('queue', 'ml-acceptance')->count();
if ($queued !== 0) stop('dedicated acceptance queue is not empty');

$uid = posix_getpwnam('www-data')['uid'] ?? null;
$gid = posix_getgrnam('www-data')['gid'] ?? null;
if (!is_int($uid) || !is_int($gid)) stop('www-data identity unavailable');
$plan = [];
$sourcesRoot = REPAIR_ROOT . '/sources';
$campaignRoot = REPAIR_ROOT . '/campaigns';
foreach (['sources' => $sourcesRoot, 'campaigns' => $campaignRoot] as $name => $path) {
    if (!is_dir($path) || is_link($path) || realpath($path) !== $path) stop("expected canonical {$name} directory missing");
}
$sourceRows = DB::table('stox_ml_acceptance_sources')->get();
$sourceIds = [];
foreach ($sourceRows as $row) {
    if (!preg_match('/\A[0-9a-f-]{36}\z/i', $row->id)) stop('invalid source identity in database');
    $sourceIds[$row->id] = true;
    $dir = $sourcesRoot . '/' . $row->id;
    if (file_exists($dir) || is_link($dir)) $plan = array_merge($plan, walkSource($dir, $row, REPAIR_ROOT, $uid, $gid));
}
$entries = array_values(array_diff(scandir($sourcesRoot) ?: [], ['.', '..']));
foreach ($entries as $entry) if (!isset($sourceIds[$entry])) stop("unregistered source directory present: {$entry}");

$campaignRows = DB::table('stox_ml_acceptance_campaigns')->get()->keyBy('id');
$campaignIds = [];
foreach ($campaignRows as $row) {
    if (!preg_match('/\A[0-9a-f-]{36}\z/i', $row->id)) stop('invalid campaign identity in database');
    $campaignIds[$row->id] = true;
    $dir = $campaignRoot . '/' . $row->id;
    if (file_exists($dir) || is_link($dir)) {
        if (in_array($row->status, ['preflight', 'training'], true)) stop("active campaign has private objects: {$row->id}");
        $plan = array_merge($plan, walkCampaign($dir, REPAIR_ROOT, $uid, $gid));
    }
}
$entries = array_values(array_diff(scandir($campaignRoot) ?: [], ['.', '..']));
foreach ($entries as $entry) if (!isset($campaignIds[$entry])) stop("unregistered campaign directory present: {$entry}");

foreach ($plan as $object) {
    if ($object['before_mode'] !== $object['after_mode'] || $object['before_uid'] !== $uid || $object['before_gid'] !== $gid) {
        printf("%s %04o:%d:%d -> %04o:%d:%d [%s]\n", $object['type'], $object['before_mode'], $object['before_uid'], $object['before_gid'], $object['after_mode'], $uid, $gid, $object['kind']);
    }
}
$changes = array_values(array_filter($plan, fn ($o) => $o['before_mode'] !== $o['after_mode'] || $o['before_uid'] !== $uid || $o['before_gid'] !== $gid));
printf("Plan: %d recognized objects, %d changes; service=%s; active campaigns=0; queued jobs=0; mode=%s\n", count($plan), count($changes), $service, $options['apply'] ? 'APPLY' : 'DRY-RUN');
if (!$options['apply']) exit(0);
if ($changes === []) { echo "No changes required.\n"; exit(0); }

if (!is_dir(REPAIR_AUDIT) && !mkdir(REPAIR_AUDIT, 0700, true)) stop('cannot create protected audit directory');
if (is_link(REPAIR_AUDIT) || (fileperms(REPAIR_AUDIT) & 0077) !== 0) stop('audit directory must be owner-only and not symlinked');
$id = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(5));
$metadata = ['id' => $id, 'actor' => $options['actor'], 'created_at_utc' => gmdate(DATE_ATOM), 'root' => REPAIR_ROOT,
    'service_state' => $service, 'changes' => $changes, 'rollback' => 'restore before_mode/before_uid/before_gid from each entry after revalidating its path and SHA-256'];
$auditPath = REPAIR_AUDIT . '/' . $id . '.json';
$json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
$handle = fopen($auditPath, 'x');
if ($handle === false || fwrite($handle, $json) !== strlen($json)) stop('cannot write rollback metadata');
fclose($handle); chmod($auditPath, 0600); chown($auditPath, 0); chgrp($auditPath, 0);
foreach ($changes as $object) {
    safePath($object['path'], REPAIR_ROOT);
    $before = lstat($object['path']);
    if ($before === false || $before['dev'] !== $object['device'] || $before['ino'] !== $object['inode']) stop("object identity changed after planning: {$object['path']}");
    if ($object['type'] === 'file' && !hash_equals($object['sha256'], (string) hash_file('sha256', $object['path']))) stop("content changed after planning: {$object['path']}");
    if (!chown($object['path'], $uid) || !chgrp($object['path'], $gid) || !chmod($object['path'], $object['after_mode'])) stop("failed to apply permissions: {$object['path']}");
}
foreach ($changes as $object) {
    $stat = lstat($object['path']);
    if (($stat['mode'] & 07777) !== $object['after_mode'] || $stat['uid'] !== $uid || $stat['gid'] !== $gid) stop("post-apply ownership/mode check failed: {$object['path']}");
    if ($object['type'] === 'file' && !hash_equals($object['sha256'], (string) hash_file('sha256', $object['path']))) stop("post-apply hash check failed: {$object['path']}");
}
echo "Applied {$id}; rollback metadata: {$auditPath}\n";
