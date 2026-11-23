<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PortfolioReconciliationRun;
use App\Services\Reconciliation\PortfolioReconciliationService;
use App\Services\Reconciliation\KiteCncTradeSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class PortfolioReconciliationController extends Controller
{
    public function __construct(private PortfolioReconciliationService $reconciliation, private KiteCncTradeSyncService $tradeSync) {}

    public function index(): JsonResponse
    {
        $profile = \activePortfolio();
        $runs = PortfolioReconciliationRun::query()->where('profile_id', $profile->id)
            ->orderByDesc('id')->limit(100)->get();
        $tradeRunQuery = \App\Models\KiteCncTradeSyncRun::query()->where('profile_id', $profile->id);
        $tradeRunQuery->whereIn('broker_account_id', \App\Models\BrokerConnection::query()
            ->select('broker_user_id')->where('user_id', $profile->user_id)
            ->where('provider', \App\Models\BrokerConnection::PROVIDER_KITE)->whereNotNull('broker_user_id'));
        $tradeRuns = $tradeRunQuery->latest('id')->limit(100)
            ->get(['id', 'profile_id', 'trading_date', 'trigger', 'status', 'received_count', 'eligible_count',
                'imported_count', 'matched_count', 'review_count', 'started_at', 'completed_at'])
            ->map(function ($run): array {
                return [...$run->toArray(), 'failure_summary' => match ($run->status) {
                    'gap_unavailable' => 'Kite does not provide arbitrary past trading days. Check the broker tradebook to recover this date.',
                    'sync_failed' => 'Sync did not complete. Check the Kite connection and retry when available.',
                    default => null,
                }];
            });

        return response()->json(['data' => [
            'status' => $this->status($profile),
            'runs' => $this->compactRuns($runs),
            'trade_sync_runs' => $tradeRuns,
        ]]);
    }

    public function syncTrades(): JsonResponse
    {
        try {
            $result = $this->tradeSync->sync(\activePortfolio(), 'manual');
            $run = $result['run'];
            return response()->json(['data' => [
                'run' => [
                    'id' => $run->id, 'trading_date' => $run->trading_date?->toDateString(),
                    'trigger' => $run->trigger, 'status' => $run->status,
                    'received_count' => $run->received_count, 'eligible_count' => $run->eligible_count,
                    'imported_count' => $run->imported_count, 'matched_count' => $run->matched_count,
                    'review_count' => $run->review_count,
                ],
                'imported' => $result['imported'], 'matched' => $result['matched'], 'review' => $result['review'],
            ]]);
        } catch (InvalidArgumentException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        } catch (RuntimeException $error) {
            if (str_contains($error->getMessage(), 'already in progress')) return response()->json(['message' => $error->getMessage()], 409);
            throw $error;
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $error) {
            return response()->json(['message' => 'Kite CNC trade sync is already in progress for this portfolio.'], 409);
        }
    }

    public function reviewItems(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'in:manual_match_review,unresolved_candidate,acknowledged_unresolved'],
            'trading_date' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $profile = \activePortfolio();
        $query = $this->activeAccountReviewQuery($profile)
            ->whereIn('status', ['manual_match_review', 'unresolved_candidate', 'acknowledged_unresolved']);
        if (isset($filters['status'])) $query->where('status', $filters['status']);
        if (isset($filters['trading_date'])) $query->whereHas('syncRun', fn ($q) => $q->whereDate('trading_date', $filters['trading_date']));
        $items = $query->with('syncRun:id,trading_date,trigger')->latest('id')->paginate($filters['per_page'] ?? 25);
        $items->through(fn ($row) => $this->reviewPayload($row));
        return response()->json(['data' => $items]);
    }

    public function reviewItem(int $item): JsonResponse
    {
        $profile = \activePortfolio();
        $row = $this->activeAccountReviewQuery($profile)
            ->whereIn('status', ['manual_match_review', 'unresolved_candidate', 'acknowledged_unresolved'])
            ->with(['syncRun:id,trading_date,trigger', 'reviewAudits'])->findOrFail($item);
        $payload = $this->reviewPayload($row);
        $payload['audits'] = $row->reviewAudits->map(fn ($audit) => [
            'action' => $audit->action, 'previous_status' => $audit->previous_status,
            'resulting_status' => $audit->resulting_status,
            'evidence_fingerprint' => $audit->evidence_fingerprint,
            'created_at' => $audit->created_at,
        ]);
        $payload['exact_match_candidates'] = [];
        if ($row->evidence_kind === 'execution' && (int) ($row->broker_evidence['ambiguity_count'] ?? 0) <= 1
            && $row->stock_id && $row->executed_at && $row->quantity > 0 && $row->price > 0) {
            $payload['exact_match_candidates'] = \App\Models\Transaction::query()->where('profile_id', $row->profile_id)
                ->where('stock_id', $row->stock_id)->where('type', $row->side)
                ->where('execution_exchange', $row->exchange)
                ->whereDate('transaction_date', $row->executed_at->timezone('Asia/Kolkata')->toDateString())
                ->where('quantity', $row->quantity)->where('price', $row->price)
                ->whereNotIn('id', \App\Models\KiteCncTradeImport::query()->where('profile_id', $row->profile_id)
                    ->whereNotNull('matched_transaction_id')->select('matched_transaction_id'))
                ->whereNotIn('id', \App\Models\KiteCncTradeImport::query()->where('profile_id', $row->profile_id)
                    ->whereNotNull('transaction_id')->select('transaction_id'))
                ->orderBy('id')->limit(100)->get(['id', 'stock_id', 'type', 'quantity', 'price', 'transaction_date', 'source'])
                ->map(fn ($transaction) => ['id' => $transaction->id, 'type' => $transaction->type,
                    'quantity' => $transaction->quantity, 'price' => $transaction->price,
                    'transaction_date' => $transaction->transaction_date?->toDateString(),
                    'source' => in_array($transaction->source, \App\Models\Transaction::SOURCES, true) ? $transaction->source : 'unknown']);
        }
        return response()->json(['data' => $payload]);
    }

    public function resolveReviewItem(Request $request, int $item): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:acknowledge_unresolved,match_existing'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:80', 'regex:/^[A-Za-z0-9_-]{16,80}$/'],
            'evidence_key' => ['required', 'string', 'size:64'],
            'transaction_id' => ['required_if:action,match_existing', 'integer', 'min:1'],
        ]);
        $profile = \activePortfolio();
        $actor = $request->user();
        $idempotencyFingerprint = hash('sha256', $data['idempotency_key']);
        $canonicalRequest = [
            'action' => $data['action'],
            'evidence_key' => $data['evidence_key'],
            'transaction_id' => isset($data['transaction_id']) ? (int) $data['transaction_id'] : null,
        ];
        $requestHash = hash('sha256', json_encode($canonicalRequest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $result = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $profile, $actor, $item, $requestHash, $idempotencyFingerprint) {
            \App\Models\PortfolioProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $connection = \App\Models\BrokerConnection::query()->where('user_id', $profile->user_id)
                ->where('provider', \App\Models\BrokerConnection::PROVIDER_KITE)->lockForUpdate()->first();
            $activeAccountId = trim((string) ($connection?->broker_user_id ?? '')) ?: null;
            $priorAudit = \Illuminate\Support\Facades\DB::table('kite_cnc_trade_review_audits')
                ->where('profile_id', $profile->id)->where('idempotency_key', $idempotencyFingerprint)->first();
            if ($priorAudit) {
                abort_unless((int) $priorAudit->trade_import_id === $item && (int) $priorAudit->actor_user_id === (int) $actor->id
                    && $priorAudit->action === $data['action'] && hash_equals($priorAudit->request_hash, $requestHash),
                    409, 'Idempotency key was already used for a different review request.');
                $priorRow = \App\Models\KiteCncTradeImport::query()->where('profile_id', $profile->id)->findOrFail($item);
                abort_unless($activeAccountId !== null && hash_equals((string) $priorRow->broker_account_id, $activeAccountId), 404);
                return $priorRow;
            }
            $row = \App\Models\KiteCncTradeImport::query()->where('profile_id', $profile->id)->lockForUpdate()->findOrFail($item);
            abort_unless($activeAccountId !== null && hash_equals((string) $row->broker_account_id, $activeAccountId), 404);
            abort_unless(in_array($row->status, ['manual_match_review', 'unresolved_candidate', 'acknowledged_unresolved'], true), 409, 'Review evidence is stale or already resolved.');
            abort_unless(hash_equals($row->review_version, $data['evidence_key']), 409, 'Review evidence changed after it was displayed. Reload before acting.');
            abort_if($data['action'] === 'acknowledge_unresolved' && $row->status === 'acknowledged_unresolved',
                409, 'This evidence is already acknowledged and remains unresolved.');
            $previous = $row->status;
            if ($data['action'] === 'match_existing') {
                abort_if($row->broker_account_id === null || $activeAccountId === null || $row->evidence_kind !== 'execution'
                    || (int) ($row->broker_evidence['ambiguity_count'] ?? 0) > 1
                    || ! $row->executed_at || ! $row->stock_id || (float) $row->quantity <= 0 || (float) $row->price <= 0,
                    422, 'Incomplete or unbound evidence cannot be matched safely. It remains unresolved.');
                $transaction = \App\Models\Transaction::query()->where('profile_id', $profile->id)->whereKey($data['transaction_id'])->lockForUpdate()->firstOrFail();
                abort_unless((int) $transaction->stock_id === (int) $row->stock_id
                    && $transaction->type === $row->side
                    && $transaction->execution_exchange === $row->exchange
                    && $transaction->transaction_date->toDateString() === $row->executed_at->timezone('Asia/Kolkata')->toDateString()
                    && (string) $transaction->quantity === (string) $row->quantity
                    && (string) $transaction->price === (string) $row->price, 409, 'The selected booked transaction does not exactly match current broker evidence.');
                abort_if(\App\Models\KiteCncTradeImport::query()->where('profile_id', $profile->id)->where('id', '!=', $row->id)
                    ->where(fn ($query) => $query->where('matched_transaction_id', $transaction->id)->orWhere('transaction_id', $transaction->id))->exists(),
                    409, 'That transaction is already linked to another broker execution.');
                $row->forceFill(['matched_transaction_id' => $transaction->id,
                    'status' => $transaction->source === \App\Models\Transaction::SOURCE_MANUAL ? 'matched_manual' : 'matched_existing',
                    'review_resolution' => 'matched_existing', 'reviewed_by' => $actor->id, 'reviewed_at' => now()])->save();
            } else {
                $row->forceFill(['status' => 'acknowledged_unresolved', 'review_resolution' => 'acknowledged_unresolved', 'reviewed_by' => $actor->id, 'reviewed_at' => now()])->save();
            }
            \Illuminate\Support\Facades\DB::table('kite_cnc_trade_review_audits')->insert([
                'trade_import_id' => $row->id, 'profile_id' => $profile->id, 'actor_user_id' => $actor->id,
                'action' => $data['action'], 'idempotency_key' => $idempotencyFingerprint,
                'request_hash' => $requestHash,
                'previous_status' => $previous, 'resulting_status' => $row->status,
                'evidence_fingerprint' => $data['evidence_key'],
                'created_at' => now(),
            ]);
            return $row->fresh();
        });
        return response()->json(['data' => [
            'id' => $result->id, 'status' => $result->status,
            'matched_transaction_id' => $result->matched_transaction_id,
            'reviewed_at' => $result->reviewed_at?->toISOString(),
        ]]);
    }

    public function show(int $run): JsonResponse
    {
        return response()->json(['data' => PortfolioReconciliationRun::query()
            ->where('profile_id', \activePortfolio()->id)->findOrFail($run)]);
    }

    /** A deliberately narrow response shape prevents future provider fields from leaking through this API. */
    private function reviewPayload(\App\Models\KiteCncTradeImport $row): array
    {
        return [
            'id' => $row->id, 'symbol' => preg_match('/^[A-Z0-9&._-]{1,32}$/', (string) $row->symbol) ? $row->symbol : 'UNKNOWN',
            'exchange' => in_array($row->exchange, ['NSE', 'BSE'], true) ? $row->exchange : 'UNKNOWN',
            'side' => in_array($row->side, ['buy', 'sell'], true) ? $row->side : 'unknown',
            'quantity' => $row->quantity, 'price' => $row->price,
            'executed_at' => $row->executed_at?->toISOString(), 'status' => $row->status,
            'evidence_kind' => in_array($row->evidence_kind, ['execution', 'malformed_candidate', 'legacy_unbound', 'account_conflict'], true) ? $row->evidence_kind : 'malformed_candidate',
            'broker_trade_id' => preg_match('/^[0-9]{1,64}$/', (string) $row->broker_trade_id) ? $row->broker_trade_id : null,
            'review_version' => $row->review_version, 'reviewed_at' => $row->reviewed_at?->toISOString(),
            'evidence' => $this->safeReviewEvidence((array) $row->broker_evidence),
            'sync_run' => $row->syncRun ? ['trading_date' => $row->syncRun->trading_date?->toDateString(), 'trigger' => $row->syncRun->trigger] : null,
        ];
    }

    /** Defense in depth for old rows written before evidence validation was added. */
    private function safeReviewEvidence(array $evidence, int $depth = 0): array
    {
        // Legacy JSON can predate provider payload validation. Bound both
        // breadth (below) and nesting before recursively sanitizing variants.
        if ($depth >= 3) return [];
        $safe = [];
        foreach (['trade_id', 'order_id'] as $key) {
            $pattern = $key === 'trade_id' ? '/^[0-9]{1,64}$/' : '/^(?:[0-9]{1,64}|[A-Z]{1,4}-[0-9]{1,20})$/';
            if (isset($evidence[$key]) && preg_match($pattern, (string) $evidence[$key])) $safe[$key] = (string) $evidence[$key];
        }
        if (isset($evidence['tradingsymbol']) && preg_match('/^[A-Z0-9&._-]{1,32}$/', (string) $evidence['tradingsymbol'])) $safe['tradingsymbol'] = $evidence['tradingsymbol'];
        if (in_array($evidence['exchange'] ?? null, ['NSE', 'BSE'], true)) $safe['exchange'] = $evidence['exchange'];
        if (in_array($evidence['transaction_type'] ?? null, ['BUY', 'SELL'], true)) $safe['transaction_type'] = $evidence['transaction_type'];
        if (in_array($evidence['product'] ?? null, ['CNC', 'MIS', 'NRML', 'MTF'], true)) $safe['product'] = $evidence['product'];
        foreach (['quantity', 'price'] as $key) {
            if (isset($evidence[$key]) && is_numeric($evidence[$key]) && is_finite((float) $evidence[$key]) && (float) $evidence[$key] > 0 && (float) $evidence[$key] <= 99999999999999) {
                $safe[$key] = number_format((float) $evidence[$key], 4, '.', '');
            }
        }
        if (isset($evidence['ambiguity_count']) && filter_var($evidence['ambiguity_count'], FILTER_VALIDATE_INT) !== false) {
            $safe['ambiguity_count'] = min(5000, max(0, (int) $evidence['ambiguity_count']));
        }
        if (isset($evidence['candidate_variants']) && is_array($evidence['candidate_variants'])) {
            $safe['candidate_variants'] = array_map(fn ($variant) => is_array($variant) ? $this->safeReviewEvidence($variant, $depth + 1) : [], array_slice($evidence['candidate_variants'], 0, 8));
        }
        if (isset($evidence['exchange_timestamp']) && is_string($evidence['exchange_timestamp'])
            && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}:[0-9]{2})$/', $evidence['exchange_timestamp'])) {
            $safe['exchange_timestamp'] = $evidence['exchange_timestamp'];
        }
        return $safe;
    }

    private function activeAccountReviewQuery(\App\Models\PortfolioProfile $profile): \Illuminate\Database\Eloquent\Builder
    {
        $activeAccountId = trim((string) (\App\Models\BrokerConnection::query()->where('user_id', $profile->user_id)
            ->where('provider', \App\Models\BrokerConnection::PROVIDER_KITE)->value('broker_user_id') ?? ''));
        $query = \App\Models\KiteCncTradeImport::query()->where('profile_id', $profile->id);
        return $activeAccountId === '' ? $query->whereRaw('1 = 0') : $query->where('broker_account_id', $activeAccountId);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ignore_today_transactions' => ['sometimes', 'boolean'],
        ]);
        try {
            $run = $this->reconciliation->run(
                \activePortfolio(),
                'manual',
                (bool) ($validated['ignore_today_transactions'] ?? false),
            );

            return response()->json(['data' => [
                'status' => $this->status(\activePortfolio()->fresh()),
                'run' => $run,
            ]], 201);
        } catch (InvalidArgumentException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        } catch (RuntimeException $error) {
            if (str_contains($error->getMessage(), 'already in progress')) {
                return response()->json(['message' => $error->getMessage()], 409);
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    private function status($profile): array
    {
        return [
            'holdings' => $profile->reconciliation_holdings_status,
            'funds' => $profile->reconciliation_funds_status,
            'overall' => $profile->reconciliation_overall_status,
            'execution_blocked' => (bool) $profile->execution_blocked_by_reconciliation,
            'last_successful_at' => $profile->last_successful_reconciliation_at?->toISOString(),
            'last_failure' => $profile->last_reconciliation_failure,
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int,PortfolioReconciliationRun> $runs
     * @return list<PortfolioReconciliationRun>
     */
    private function compactRuns($runs): array
    {
        $compacted = [];
        $seenScheduledFailures = [];

        foreach ($runs as $run) {
            if ($run->trigger === 'scheduled' && $run->status === 'sync_failed') {
                $key = implode('|', [
                    $run->completed_at?->toDateString() ?? 'unknown',
                    (string) $run->failure,
                ]);
                if (isset($seenScheduledFailures[$key])) {
                    continue;
                }
                $seenScheduledFailures[$key] = true;
            }

            $compacted[] = $run;
        }

        return $compacted;
    }
}
