#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${STOXLA_APP_ROOT:-/var/www/stoxla}"
PHP_BIN="${STOXLA_PHP_BIN:-/usr/bin/php}"
CANARY_SYMBOL="${STOXLA_FUNDAMENTALS_CANARY_SYMBOL:-TCS}"
BATCH="${STOXLA_FUNDAMENTALS_BOOTSTRAP_BATCH:-10}"
RUN_ID="${STOXLA_FUNDAMENTALS_RUN_ID:-}"
SLICE_SECONDS="${STOXLA_FUNDAMENTALS_SLICE_SECONDS:-2400}"
LOCK_PATH="$APP_ROOT/.fundamentals-bootstrap.lock"

[[ "$APP_ROOT" == /var/www/stoxla ]] || { echo "refusing unexpected app root" >&2; exit 1; }
[[ -x "$PHP_BIN" ]] || { echo "PHP binary not found: $PHP_BIN" >&2; exit 1; }
[[ "$SLICE_SECONDS" =~ ^[0-9]+$ ]] || { echo "invalid slice seconds: $SLICE_SECONDS" >&2; exit 1; }
(( SLICE_SECONDS >= 60 && SLICE_SECONDS <= 2700 )) || { echo "slice seconds must be between 60 and 2700" >&2; exit 1; }

exec 9>"$LOCK_PATH"
flock -n 9 || { echo "another fundamentals bootstrap is already running" >&2; exit 20; }
cd "$APP_ROOT/current"

acceptance_state="$(/usr/bin/systemctl is-active stoxla-ml-acceptance.service 2>/dev/null || true)"
case "$acceptance_state" in
  inactive|failed|unknown) ;;
  *) echo "refusing to run while stoxla-ml-acceptance.service is active: $acceptance_state" >&2; exit 21;;
esac

if [[ -n "$RUN_ID" ]]; then
    [[ "$RUN_ID" =~ ^[0-9]+$ ]] || { echo "invalid run id: $RUN_ID" >&2; exit 22; }
    full_run_id="$RUN_ID"
    echo "RESUMING_EXISTING_RUN run=$full_run_id slice_seconds=$SLICE_SECONDS"
else
    run_output="$($PHP_BIN artisan stox:fundamentals-bootstrap --stock="$CANARY_SYMBOL" --batch=1 --no-interaction)"
    printf '%s\n' "$run_output"
    canary_run_id="$(printf '%s\n' "$run_output" | sed -n 's/.*Bootstrap run #\([0-9][0-9]*\).*/\1/p' | tail -n 1)"
    [[ -n "$canary_run_id" ]] || { echo "could not determine canary run id" >&2; exit 23; }

    canary_evidence="$($PHP_BIN artisan tinker --no-interaction --execute="\$run=\App\Models\V7\FundamentalBootstrapRun::findOrFail($canary_run_id); \$job=\$run->jobs()->with('stock')->first(); \$checks=\Illuminate\Support\Facades\DB::table('stox_fundamental_provider_checks')->where('stock_id',\$job->stock_id)->get(['cadence','provider','response_hash','requested_symbol','provider_symbol']); echo json_encode(['run_id'=>\$run->id,'status'=>\$run->status,'job_status'=>\$job->status,'symbol'=>\$job->stock->symbol,'facts_inserted'=>\$job->facts_inserted,'facts_upgraded'=>\$job->facts_upgraded,'facts_deduped'=>\$job->facts_deduped,'quarterly_status'=>\$job->quarterly_status,'annual_status'=>\$job->annual_status,'earliest_period'=>\$job->earliest_period?->toDateString(),'latest_period'=>\$job->latest_period?->toDateString(),'checks'=>\$checks]);")"
    printf 'CANARY_EVIDENCE %s\n' "$canary_evidence"

    CANARY_EVIDENCE="$canary_evidence" "$PHP_BIN" -r '
    $e = json_decode(getenv("CANARY_EVIDENCE"), true);
    $checks = is_array($e["checks"] ?? null) ? $e["checks"] : [];
    $hashes = array_filter($checks, fn ($row) => is_string($row["response_hash"] ?? null) && strlen($row["response_hash"]) === 64);
    if (($e["job_status"] ?? null) === "failed" || (($e["facts_inserted"] ?? 0) + ($e["facts_upgraded"] ?? 0) + ($e["facts_deduped"] ?? 0) <= 0) || count($hashes) === 0) {
        fwrite(STDERR, "canary did not prove persisted or unchanged facts and response hashes\n");
        exit(24);
    }
    '

    full_output="$($PHP_BIN artisan stox:fundamentals-bootstrap --all --batch="$BATCH" --no-interaction)"
    printf '%s\n' "$full_output"
    full_run_id="$(printf '%s\n' "$full_output" | sed -n 's/.*Bootstrap run #\([0-9][0-9]*\).*/\1/p' | tail -n 1)"
    [[ -n "$full_run_id" ]] || { echo "could not determine full run id" >&2; exit 25; }
fi

deadline=$(( $(date +%s) + SLICE_SECONDS ))

while :; do
    summary="$($PHP_BIN artisan tinker --no-interaction --execute="\$run=\App\Models\V7\FundamentalBootstrapRun::findOrFail($full_run_id); echo implode('|',[\$run->status,\$run->requested,\$run->queued,\$run->running,\$run->completed,\$run->failed]);")"
    printf 'FULL_PROGRESS run=%s %s\n' "$full_run_id" "$summary"
    IFS='|' read -r status requested queued running completed failed <<< "$summary"
    if [[ "$queued" == "0" && "$running" == "0" ]]; then
        break
    fi
    if (( $(date +%s) >= deadline )); then
        echo "RESUME_REQUIRED run=$full_run_id queued=$queued running=$running failed=$failed"
        exit 0
    fi
    $PHP_BIN artisan stox:fundamentals-bootstrap --run="$full_run_id" --batch="$BATCH" --no-interaction || true
    sleep 5
done

$PHP_BIN artisan tinker --no-interaction --execute="\$run=\App\Models\V7\FundamentalBootstrapRun::findOrFail($full_run_id); \$jobs=\App\Models\V7\FundamentalBootstrapJob::where('run_id',\$run->id)->selectRaw('status,COUNT(*) as count')->groupBy('status')->pluck('count','status'); \$facts=\Illuminate\Support\Facades\DB::table('stox_fundamental_facts')->selectRaw('cadence,COUNT(*) as fact_rows,COUNT(DISTINCT stock_id) as stocks,MIN(period_end) as first_period,MAX(period_end) as last_period')->groupBy('cadence')->get(); \$checks=\Illuminate\Support\Facades\DB::table('stox_fundamental_provider_checks')->selectRaw('cadence,COUNT(DISTINCT stock_id) as stocks,COUNT(*) as checks,SUM(CASE WHEN response_hash IS NOT NULL AND CHAR_LENGTH(response_hash)=64 THEN 1 ELSE 0 END) as hashed_checks')->groupBy('cadence')->get(); \$errors=\App\Models\V7\FundamentalBootstrapJob::where('run_id',\$run->id)->whereIn('status',['failed','retry'])->selectRaw('status,last_error,COUNT(*) as count')->groupBy('status','last_error')->get(); echo json_encode(['run_id'=>\$run->id,'status'=>\$run->status,'requested'=>\$run->requested,'completed'=>\$run->completed,'failed'=>\$run->failed,'summary'=>\$jobs,'facts'=>\$facts,'provider_checks'=>\$checks,'errors'=>\$errors]);"
