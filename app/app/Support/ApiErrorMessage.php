<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ApiErrorMessage
{
    public static function for(Throwable $e): string
    {
        if ($e instanceof HttpExceptionInterface) {
            $message = $e->getMessage();

            return $message !== '' ? $message : 'Request could not be completed.';
        }

        if ($e instanceof QueryException) {
            return self::forQueryException($e);
        }

        if (app()->hasDebugModeEnabled()) {
            return $e->getMessage() !== ''
                ? $e->getMessage()
                : class_basename($e).': An unexpected error occurred.';
        }

        return 'An unexpected server error occurred. If this persists, contact support with the request ID from the response.';
    }

    public static function forQueryException(QueryException $e): string
    {
        $sqlMessage = $e->getMessage();

        if (str_contains($sqlMessage, 'portfolio_operational_alerts')) {
            if (str_contains($sqlMessage, 'manually_cleared_at')) {
                return 'Admin alerts database is missing the manual clear column. Run the latest migrations on the server (cpanel-migrate.php), then try again.';
            }

            return 'Admin alerts database tables are missing or incomplete. Run the latest migrations on the server (cpanel-migrate.php), then try again.';
        }

        if (str_contains($sqlMessage, 'portfolio_alert_policies')) {
            return 'Alert policies database tables are missing or incomplete. Run the latest migrations on the server (cpanel-migrate.php), then try again.';
        }

        foreach (['instance_key', 'condition_display', 'action_suggested', 'context_json', 'alert_policy_id'] as $column) {
            if (str_contains($sqlMessage, $column)) {
                return 'Alert policy columns are missing on portfolio_alerts. Run the latest migrations on the server (migration 2026_07_01_000002), then try again.';
            }
        }

        if (str_contains($sqlMessage, 'Unknown column') || str_contains($sqlMessage, "doesn't exist")) {
            return 'Database schema is out of date for this feature. Run the latest migrations on the server, then try again.';
        }

        if (
            str_contains($sqlMessage, 'Data too long')
            || str_contains($sqlMessage, '1406')
            || str_contains($sqlMessage, '22001')
        ) {
            return 'A settings value was too large to store. Run the latest migrations on the server (widens portfolio_settings), then try Scan all gaps again.';
        }

        return app()->hasDebugModeEnabled()
            ? $sqlMessage
            : 'A database error occurred while processing your request. Run migrations on the server, then try again.';
    }

    public static function assertAlertPolicySchemaReady(): void
    {
        if (! Schema::hasTable('portfolio_alert_policies')) {
            throw new HttpResponseException(response()->json([
                'message' => 'Alert policies are not installed yet. Run migration 2026_07_01_000001 on the server (cpanel-migrate.php), then try again.',
            ], 503));
        }

        $required = ['alert_policy_id', 'instance_key', 'condition_display', 'action_suggested', 'context_json'];
        $missing = array_values(array_filter(
            $required,
            fn (string $column) => ! Schema::hasColumn('portfolio_alerts', $column),
        ));

        if ($missing !== []) {
            throw new HttpResponseException(response()->json([
                'message' => 'Alert policy columns are missing on portfolio_alerts ('.implode(', ', $missing).'). Run migration 2026_07_01_000002 on the server (cpanel-migrate.php), then try again.',
            ], 503));
        }
    }
}
