<?php
/**
 * Detect and repair OHLCV rows via F042 pending PriceAdjustmentFactors and/or
 * F020 applied corporate actions (no SSH).
 *
 * Upload to: public_html/portfolio/cpanel-repair-corporate-action-prices.php
 * Scan:      https://YOUR-DOMAIN/portfolio/cpanel-repair-corporate-action-prices.php?token=YOUR_TOKEN
 * Apply:     ...?token=YOUR_TOKEN&apply=1
 * Optional:   &profile=123 &stock=45 &action=7 &factor=9 &force=1
 *             &factors_only=1 | &ca_only=1
 *
 * Requires CorporateActionPriceRepairService (upload latest app/Services/).
 * DELETE this file from the server immediately after success.
 */
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }
    if (! in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (! headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(500);
    }
    echo "\n\nPHP FATAL: {$error['message']}\n{$error['file']}:{$error['line']}\n";
});

const SETUP_TOKEN = 'Lido';

if (($_GET['token'] ?? '') !== SETUP_TOKEN) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden. Set SETUP_TOKEN in this file, then visit ?token=YOUR_TOKEN\n");
}

header('Content-Type: text/plain; charset=utf-8');

$laravelRootCandidates = [
    __DIR__.'/laravel',
    dirname(__DIR__).'/lidoportfolio',
    dirname(__DIR__, 2).'/public_html/lidoportfolio',
];

$laravelRoot = null;
foreach ($laravelRootCandidates as $candidate) {
    if (is_file($candidate.'/vendor/autoload.php')) {
        $laravelRoot = $candidate;
        break;
    }
}

if ($laravelRoot === null) {
    http_response_code(500);
    echo "Could not find Laravel (vendor/autoload.php).\n";
    exit(1);
}

echo "=== Repair corporate-action OHLCV prices (F043) ===\n\n";
echo 'Laravel root: '.$laravelRoot."\n";
echo 'PHP: '.PHP_VERSION."\n\n";

require $laravelRoot.'/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require_once $laravelRoot.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if (! Illuminate\Support\Facades\Schema::hasTable('portfolio_corporate_actions')) {
    http_response_code(500);
    echo "Table portfolio_corporate_actions not found.\n";
    echo "Run cpanel-migrate.php first.\n";
    exit(1);
}

if (! class_exists(App\Services\CorporateActionPriceRepairService::class)) {
    http_response_code(500);
    echo "CorporateActionPriceRepairService not found.\n";
    echo "Upload app/Services/CorporateActionPriceRepairService.php and related services.\n";
    exit(1);
}

$profileId = intParam($_GET['profile'] ?? null);
$stockId = intParam($_GET['stock'] ?? null);
$actionId = intParam($_GET['action'] ?? null);
$factorId = intParam($_GET['factor'] ?? null);
$apply = truthy($_GET['apply'] ?? null);
$force = truthy($_GET['force'] ?? null);
$factorsOnly = truthy($_GET['factors_only'] ?? null);
$caOnly = truthy($_GET['ca_only'] ?? null);

if ($factorsOnly && $caOnly) {
    http_response_code(400);
    echo "Use only one of factors_only=1 or ca_only=1.\n";
    exit(1);
}

$runFactors = ! $caOnly;
$runCa = ! $factorsOnly;

echo 'Mode: '.($apply ? 'APPLY (writes OHLCV + metadata)' : 'SCAN (dry-run)')."\n";
echo 'Profile filter: '.($profileId ?? 'all')."\n";
echo 'Stock filter: '.($stockId ?? 'all')."\n";
echo 'Action filter: '.($actionId ?? 'all')."\n";
echo 'Factor filter: '.($factorId ?? 'all')."\n";
echo 'Factors only: '.($factorsOnly ? 'yes' : 'no')."\n";
echo 'CA only: '.($caOnly ? 'yes' : 'no')."\n";
echo 'Force ambiguous CA: '.($force ? 'yes' : 'no')."\n\n";

try {
    /** @var App\Services\CorporateActionPriceRepairService $repair */
    $repair = $app->make(App\Services\CorporateActionPriceRepairService::class);

    if (! $apply) {
        if ($runFactors) {
            $factorFindings = $repair->scanPendingFactors($stockId, $factorId);
            echo 'Scanned '.count($factorFindings)." F042 price adjustment factor(s).\n\n";
            foreach ($factorFindings as $row) {
                echo sprintf(
                    "factor #%d issue=%s %s %s ex=%s rows=%s [%s]\n  %s\n",
                    $row['factor_id'],
                    $row['issue_id'] ?? '—',
                    $row['symbol'] ?? '?',
                    $row['action_type'] ?? '?',
                    $row['ex_date'] ?? '?',
                    (string) ($row['rows_to_adjust'] ?? 0),
                    $row['status'],
                    $row['message'],
                );
            }
            echo "\n";
        }

        if ($runCa) {
            $findings = $repair->scan($profileId, $stockId, $actionId);
            echo 'Scanned '.count($findings)." applied corporate action(s).\n\n";

            if ($findings === [] && ! $runFactors) {
                echo "No applied corporate actions found.\n";
                echo "\nDone. DELETE cpanel-repair-corporate-action-prices.php when finished.\n";
                exit(0);
            }

            foreach ($findings as $row) {
                echo sprintf(
                    "#%d %s %s %s ex=%s [%s]\n  %s\n",
                    $row['corporate_action_id'],
                    $row['symbol'] ?? '?',
                    $row['action_type'],
                    $row['ratio'],
                    $row['ex_date'],
                    $row['status'],
                    $row['message'],
                );
            }

            $needsRepair = 0;
            foreach ($findings as $row) {
                if (in_array($row['status'], [
                    App\Services\CorporateActionPriceRepairService::STATUS_MISSING_METADATA,
                    App\Services\CorporateActionPriceRepairService::STATUS_SUSPECTED_UNADJUSTED,
                    App\Services\CorporateActionPriceRepairService::STATUS_SUSPECTED_ALREADY_ADJUSTED,
                ], true)) {
                    $needsRepair++;
                }
            }

            echo "\n";
            if ($needsRepair > 0) {
                echo "{$needsRepair} F020 action(s) may need repair.\n";
                echo "Re-run with &apply=1 to fix (add &force=1 only if scan shows ambiguous).\n";
            } else {
                echo "No F020 repair candidates found.\n";
            }
        }

        echo "\nDone (scan only). DELETE cpanel-repair-corporate-action-prices.php when finished.\n";
        exit(0);
    }

    if ($runFactors) {
        $factorResult = $repair->repairPendingFactors(
            stockId: $stockId,
            factorId: $factorId,
            dryRun: false,
            repairSource: 'cpanel-repair-corporate-action-prices',
        );
        echo sprintf(
            "F042 factor repair: scanned %d, repaired %d, skipped %d.\n\n",
            $factorResult['scanned'],
            $factorResult['repaired'],
            $factorResult['skipped'],
        );
        foreach ($factorResult['details'] as $detail) {
            echo sprintf(
                "  factor #%d %s (%s)\n",
                $detail['factor_id'],
                $detail['action'],
                $detail['status'] ?? '',
            );
            if (isset($detail['rows_adjusted'])) {
                echo "    rows_adjusted: {$detail['rows_adjusted']}\n";
            }
        }
        echo "\n";
    }

    if ($runCa) {
        $result = $repair->repair($profileId, $stockId, $actionId, dryRun: false, force: $force);

        echo sprintf(
            "F020 CA repair: scanned %d, repaired %d, skipped %d.\n\n",
            $result['scanned'],
            $result['repaired'],
            $result['skipped'],
        );

        foreach ($result['details'] as $detail) {
            echo sprintf(
                "  #%d %s (%s)\n",
                $detail['corporate_action_id'],
                $detail['action'],
                $detail['status'] ?? '',
            );
            if (isset($detail['rows_adjusted'])) {
                echo "    rows_adjusted: {$detail['rows_adjusted']}\n";
            }
        }
    }

    echo "\nDone. DELETE cpanel-repair-corporate-action-prices.php from public_html/portfolio/ now.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "\nFAILED: ".$e->getMessage()."\n\n";
    echo $e->getTraceAsString()."\n";
    exit(1);
}

function intParam(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    return (int) $value;
}

function truthy(mixed $value): bool
{
    if ($value === null || $value === '') {
        return false;
    }

    $normalized = strtolower((string) $value);

    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}
