<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\Transaction;
use App\Models\BrokerConnection;
use App\Services\Broker\BrokerGateway;
use App\Services\KiteConsoleTradebookParser;
use App\Services\StockResolverService;
use App\Services\TransactionWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KiteHoldingsOnboardingController extends Controller
{
    public function __construct(private BrokerGateway $broker, private StockResolverService $stocks, private TransactionWriteService $writes, private KiteConsoleTradebookParser $tradebooks) {}

    public function show(): JsonResponse
    {
        $profile = \activePortfolio();
        $draft = DB::table('kite_onboarding_drafts')->where('profile_id', $profile->id)->first();

        return response()->json(['data' => $draft ? $this->payload($draft->id) : null]);
    }

    public function start(Request $request): JsonResponse
    {
        $request->validate(['confirm_legacy_draft' => 'sometimes|accepted']);
        $profile = \activePortfolio();
        $connection = BrokerConnection::query()->where('user_id', $profile->user_id)->where('provider', BrokerConnection::PROVIDER_KITE)->first();
        $accountId = trim((string) ($connection?->broker_user_id ?? ''));
        if (! $connection?->isUsable() || $accountId === '') {
            return response()->json(['message' => 'Kite account identity is unavailable. Reconnect Kite and retry.'], 409);
        }
        $existingDraft = DB::table('kite_onboarding_drafts')->where('profile_id', $profile->id)->first();
        $existingAccountId = trim((string) data_get(json_decode($existingDraft->kite_snapshot ?? '', true), 'broker_account_id', ''));
        if ($existingDraft && $existingAccountId !== '' && ! hash_equals($existingAccountId, $accountId)) {
            return response()->json(['message' => 'This staging draft belongs to a different Kite account. Its entries were preserved; reconnect that account to resume.'], 409);
        }
        if ($existingDraft && $existingAccountId === '' && ! $request->boolean('confirm_legacy_draft')) {
            return response()->json(['message' => 'This older draft has no saved Kite account identity. Review it, then explicitly confirm that it belongs to the currently connected Kite account to resume.'], 409);
        }
        if ($existingDraft && $existingAccountId === '' && DB::table('kite_onboarding_stocks')->where('draft_id', $existingDraft->id)->where('status', 'committed')->exists()) {
            return response()->json(['message' => 'This older draft contains committed history but has no saved Kite account identity. It cannot be safely bound to an account; existing history was preserved.'], 409);
        }
        $snapshot = $this->broker->portfolioSnapshot((int) auth()->id());
        if (! $snapshot) {
            return response()->json(['message' => 'Kite holdings could not be fetched. Reconnect Kite and retry.'], 422);
        }
        if (isset($snapshot['broker_account_id'])
            && ! hash_equals((string) $connection->broker_user_id, (string) $snapshot['broker_account_id'])) {
            return response()->json(['message' => 'Kite snapshot account changed while holdings were fetched. Nothing was staged; retry with the current account.'], 409);
        }
        $afterFetch = BrokerConnection::query()->where('user_id', $profile->user_id)->where('provider', BrokerConnection::PROVIDER_KITE)->first();
        if (! $afterFetch?->isUsable() || $afterFetch->broker_user_id !== $connection->broker_user_id || $afterFetch->updated_at?->toISOString() !== $connection->updated_at?->toISOString()) {
            return response()->json(['message' => 'Kite connection changed while holdings were fetched. Nothing was staged; retry with the current account.'], 409);
        }
        $rows = collect($snapshot['holdings'] ?? [])->filter(fn ($r) => (float) ($r['quantity'] ?? 0) > 0)->values();
        $snapshot['broker_account_id'] = $accountId;
        $confirmedLegacy = $request->boolean('confirm_legacy_draft');
        $draft = DB::transaction(function () use ($profile, $snapshot, $rows, $existingDraft, $existingAccountId, $connection, $accountId, $confirmedLegacy) {
            $lockedConnection = BrokerConnection::query()->where('user_id', $profile->user_id)->where('provider', BrokerConnection::PROVIDER_KITE)->lockForUpdate()->first();
            if (! $lockedConnection?->isUsable() || trim((string) $lockedConnection->broker_user_id) !== $accountId || $lockedConnection->updated_at?->toISOString() !== $connection->updated_at?->toISOString()) {
                abort(409, 'Kite connection changed while holdings were fetched. Nothing was staged; retry with the current account.');
            }
            $lockedDraft = DB::table('kite_onboarding_drafts')->where('profile_id', $profile->id)->lockForUpdate()->first();
            $id = $lockedDraft?->id;
            $lockedAccountId = trim((string) data_get(json_decode($lockedDraft->kite_snapshot ?? '', true), 'broker_account_id', ''));
            if ($lockedDraft && $lockedAccountId !== '' && ! hash_equals($lockedAccountId, $accountId)) {
                abort(409, 'This staging draft belongs to a different Kite account. Its entries were preserved; reconnect that account to resume.');
            }
            if ($lockedDraft && $lockedAccountId === '' && ! $confirmedLegacy) {
                abort(409, 'This older draft has no saved Kite account identity. Explicit confirmation is required.');
            }
            if ($lockedDraft && $lockedAccountId === '' && DB::table('kite_onboarding_stocks')->where('draft_id', $id)->where('status', 'committed')->exists()) {
                abort(409, 'This older draft contains committed history but has no saved Kite account identity. It cannot be safely bound to an account; existing history was preserved.');
            }
            if (! $id) {
                $id = DB::table('kite_onboarding_drafts')->insertGetId(['profile_id' => $profile->id, 'status' => 'draft', 'kite_snapshot' => json_encode($snapshot), 'snapshot_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            } elseif ($lockedDraft && $lockedAccountId === '') {
                // Bind only after explicit attestation. Existing entry evidence is preserved,
                // but verification from an unknown account is revoked and audited.
                DB::table('kite_onboarding_drafts')->where('id', $id)->update(['kite_snapshot' => json_encode($snapshot), 'snapshot_at' => now(), 'updated_at' => now()]);
                $legacyRows = DB::table('kite_onboarding_stocks')->where('draft_id', $id)->where('status', '!=', 'committed')->lockForUpdate()->get();
                foreach ($legacyRows as $legacyRow) {
                    DB::table('kite_onboarding_stocks')->where('id', $legacyRow->id)->update([
                        'status' => 'needs_review',
                        'audit' => json_encode(array_merge(json_decode($legacyRow->audit ?: '[]', true), [['event' => 'legacy_account_bound_reverification_required', 'at' => now()->toISOString(), 'by' => auth()->id()]])),
                        'updated_at' => now(),
                    ]);
                }
            }
            foreach ($rows as $row) {
                $stock = $this->stocks->resolveFromAttributes(['symbol' => $row['symbol'] ?? '', 'exchange' => $row['exchange'] ?? 'NSE']);
                $has = DB::table('kite_onboarding_stocks')->where('draft_id', $id)->where('stock_id', $stock->id)->exists();
                if ($has) {
                    continue;
                }
                $qty = (float) $row['quantity'];
                $price = (float) ($row['average_price'] ?? 0);
                DB::table('kite_onboarding_stocks')->insert(['draft_id' => $id, 'stock_id' => $stock->id, 'symbol' => $stock->symbol, 'exchange' => $row['exchange'] ?? 'NSE', 'kite_quantity' => $qty, 'kite_average_price' => $price, 'entries' => json_encode([['type' => 'buy', 'quantity' => $qty, 'price' => $price, 'date' => today()->toDateString(), 'date_placeholder' => true, 'provenance' => 'kite_opening_position']]), 'status' => 'needs_review', 'created_at' => now(), 'updated_at' => now()]);
            }

            return (int) $id;
        });

        return response()->json(['data' => $this->payload($draft)], 201);
    }

    public function update(Request $request, int $item): JsonResponse
    {
        $data = $request->validate(['entries' => 'required|array|min:1|max:500', 'entries.*.type' => 'required|in:buy,sell', 'entries.*.quantity' => 'required|numeric|gt:0|max:1000000000', 'entries.*.price' => 'required|numeric|gt:0|max:1000000000', 'entries.*.date' => 'required|date_format:Y-m-d|before_or_equal:today', 'entries.*.date_placeholder' => 'sometimes|boolean', 'entries.*.broker_trade_id' => 'nullable|string|max:100', 'entries.*.provenance' => 'nullable|string|max:80']);
        $draftId = DB::transaction(function () use ($item, $data) {
            $row = DB::table('kite_onboarding_stocks')->join('kite_onboarding_drafts', 'kite_onboarding_drafts.id', '=', 'kite_onboarding_stocks.draft_id')->where('kite_onboarding_stocks.id', $item)->where('profile_id', \activePortfolio()->id)->select('kite_onboarding_stocks.*')->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if($row->status === 'committed', 409, 'Committed staging entries cannot be edited.');
            DB::table('kite_onboarding_stocks')->where('id', $item)->update(['entries' => json_encode($data['entries']), 'status' => 'needs_review', 'audit' => json_encode(array_merge(json_decode($row->audit ?: '[]', true), [['event' => 'edited', 'at' => now()->toISOString(), 'by' => auth()->id()]])), 'updated_at' => now()]);

            return (int) $row->draft_id;
        });

        return response()->json(['data' => $this->payload($draftId)]);
    }

    public function verify(int $item): JsonResponse
    {
        return DB::transaction(function () use ($item) {
            $row = DB::table('kite_onboarding_stocks')->join('kite_onboarding_drafts', 'kite_onboarding_drafts.id', '=', 'kite_onboarding_stocks.draft_id')->where('kite_onboarding_stocks.id', $item)->where('profile_id', \activePortfolio()->id)->select('kite_onboarding_stocks.*')->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if($row->status === 'committed', 409, 'Committed stocks cannot be verified again.');
            $entries = json_decode($row->entries, true);
            $net = collect($entries)->sum(fn ($e) => (($e['type'] ?? 'buy') === 'buy' ? 1 : -1) * (float) $e['quantity']);
            if (collect($entries)->contains(fn ($entry) => (bool) ($entry['date_placeholder'] ?? false))) {
                throw ValidationException::withMessages(['entries' => ['Replace every placeholder with a verified acquisition or trade date before verifying this stock.']]);
            }
            if (collect($entries)->contains(fn ($entry) => ($entry['date'] ?? '') > today()->toDateString())) {
                throw ValidationException::withMessages(['entries' => ['Trade dates cannot be in the future.']]);
            }
            if (abs($net - (float) $row->kite_quantity) > 0.0001) {
                throw ValidationException::withMessages(['entries' => ['Net staged quantity must match Kite. Review uncovered sells or corporate actions.']]);
            }
            usort($entries, fn ($a, $b) => strcmp($a['date'], $b['date']));
            $lots = [];
            foreach ($entries as $entry) {
                $quantity = (float) $entry['quantity'];
                if ($entry['type'] === 'buy') {
                    $lots[] = ['qty' => $quantity, 'price' => (float) $entry['price']];

                    continue;
                } foreach ($lots as &$lot) {
                    $used = min($quantity, $lot['qty']);
                    $lot['qty'] -= $used;
                    $quantity -= $used;
                    if ($quantity <= 0.0001) {
                        break;
                    }
                } unset($lot);
                if ($quantity > 0.0001) {
                    throw ValidationException::withMessages(['entries' => ['A sell is not covered by staged buys. Add the missing older purchase or review this tradebook window.']]);
                }
            }
            $stagedBasis = array_sum(array_map(fn ($lot) => $lot['qty'] * $lot['price'], $lots));
            $kiteBasis = (float) $row->kite_quantity * (float) $row->kite_average_price;
            if (abs($stagedBasis - $kiteBasis) > max(1.0, $kiteBasis * 0.01)) {
                throw ValidationException::withMessages(['entries' => ['Staged remaining cost basis differs from Kite by more than 1%. Review missing trades, corporate actions, or prices before verification.']]);
            }
            DB::table('kite_onboarding_stocks')->where('id', $item)->update(['status' => 'verified', 'audit' => json_encode(array_merge(json_decode($row->audit ?: '[]', true), [['event' => 'verified', 'at' => now()->toISOString(), 'by' => auth()->id()]])), 'updated_at' => now()]);

            return response()->json(['data' => $this->payload($row->draft_id)]);
        });
    }

    public function importTradebook(Request $request): JsonResponse
    {
        $file = $request->validate(['tradebook' => ['required', 'file', 'max:10240']])['tradebook'];
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'txt', 'xls', 'xlsx'], true)) {
            throw ValidationException::withMessages(['tradebook' => ['Upload a CSV or TXT file, an XLSX workbook, or Excel 2003 XML with an .xls extension. Legacy binary .xls files are not supported; open the file in Excel or LibreOffice and save a copy as .xlsx.']]);
        }
        $draft = DB::table('kite_onboarding_drafts')->where('profile_id', \activePortfolio()->id)->first();
        abort_unless($draft, 409, 'Fetch Kite holdings before importing a tradebook.');
        $bySymbol = $this->tradebooks->parse($file->getRealPath(), $extension);
        $merged = DB::transaction(function () use ($bySymbol, $draft) {
            $merged = 0;
            foreach ($bySymbol as $symbol => $newEntries) {
                $staged = DB::table('kite_onboarding_stocks')->where('draft_id', $draft->id)->whereRaw('UPPER(symbol) = ?', [$symbol])->lockForUpdate()->first();
                if (! $staged) {
                    continue;
                }
                $old = json_decode($staged->entries, true) ?: [];
                $identities = array_fill_keys(array_map(fn ($entry) => $this->entryIdentity($entry), $old), true);
                // An opening row is provisional only while its suggested date is still marked.
                // If the user supplied a real date, preserve the reviewed row across imports.
                $kept = array_values(array_filter($old, fn ($e) => ! (($e['provenance'] ?? '') === 'kite_opening_position' && (bool) ($e['date_placeholder'] ?? false))));
                foreach ($newEntries as $e) {
                    $identity = $this->entryIdentity($e);
                    if (isset($identities[$identity])) {
                        continue;
                    } $kept[] = $e;
                    $identities[$identity] = true;
                }
                DB::table('kite_onboarding_stocks')->where('id', $staged->id)->update(['entries' => json_encode($kept), 'status' => 'needs_review', 'audit' => json_encode(array_merge(json_decode($staged->audit ?: '[]', true), [['event' => 'tradebook_imported', 'at' => now()->toISOString(), 'by' => auth()->id(), 'rows' => count($newEntries)]])), 'updated_at' => now()]);
                $merged++;
            }

            return $merged;
        });

        return response()->json(['data' => $this->payload($draft->id), 'merged_stocks' => $merged, 'note' => 'Only rows matching currently held Kite stocks were considered. Review all quantities, cost basis, dates and uncovered sells.']);
    }

    public function commit(Request $request): JsonResponse
    {
        $ids = $request->validate(['stock_ids' => 'required|array|min:1', 'stock_ids.*' => 'integer'])['stock_ids'];
        $profile = \activePortfolio();
        $draft = DB::table('kite_onboarding_drafts')->where('profile_id', $profile->id)->firstOrFail();
        $capturedAccountId = trim((string) data_get(json_decode($draft->kite_snapshot, true), 'broker_account_id', ''));
        $connectionBeforeFetch = BrokerConnection::query()->where('user_id', $profile->user_id)->where('provider', BrokerConnection::PROVIDER_KITE)->first();
        if (! $connectionBeforeFetch?->isUsable() || $capturedAccountId === '' || ! hash_equals($capturedAccountId, trim((string) $connectionBeforeFetch->broker_user_id))) {
            return response()->json(['message' => 'Kite account changed since staging. Fetch holdings again and review the draft before committing.'], 409);
        }
        $fresh = $this->broker->portfolioSnapshot((int) auth()->id());
        if (! $fresh) {
            return response()->json(['message' => 'Fresh Kite recheck failed; nothing was committed.'], 409);
        }

        return DB::transaction(function () use ($ids, $profile, $draft, $fresh, $capturedAccountId, $connectionBeforeFetch) {
            $connection = BrokerConnection::query()->where('user_id', $profile->user_id)->where('provider', BrokerConnection::PROVIDER_KITE)->lockForUpdate()->first();
            $activeAccountId = trim((string) ($connection?->broker_user_id ?? ''));
            if (! $connection?->isUsable() || $capturedAccountId === '' || $activeAccountId === '' || ! hash_equals($capturedAccountId, $activeAccountId) || $connection->updated_at?->toISOString() !== $connectionBeforeFetch->updated_at?->toISOString()) {
                return response()->json(['message' => 'Kite account changed since staging. Fetch holdings again and review the draft before committing.'], 409);
            }
            $rows = DB::table('kite_onboarding_stocks')->where('draft_id', $draft->id)->whereIn('id', $ids)->lockForUpdate()->get();
            if ($rows->count() !== count(array_unique($ids)) || $rows->contains(fn ($r) => $r->status !== 'verified')) {
                return response()->json(['message' => 'Only verified staged stocks can be committed.'], 409);
            }
            foreach ($rows as $row) {
                $match = collect($fresh['holdings'] ?? [])->first(fn ($h) => strtoupper($h['symbol'] ?? '') === strtoupper($row->symbol));
                if (! $match || abs((float) $match['quantity'] - (float) $row->kite_quantity) > 0.0001 || abs((float) ($match['average_price'] ?? 0) - (float) $row->kite_average_price) > 0.01) {
                    return response()->json(['message' => 'Kite quantity or average cost changed since review. Refresh the draft and review again.'], 409);
                } if (collect(json_decode($row->entries, true))->contains(fn ($entry) => (bool) ($entry['date_placeholder'] ?? false))) {
                    return response()->json(['message' => $row->symbol.' still has an unverified placeholder date. Edit dates and verify again.'], 409);
                } $existing = Transaction::where('profile_id', $profile->id)->where('stock_id', $row->stock_id)->exists();
                if ($existing) {
                    return response()->json(['message' => $row->symbol.' already has StoX transaction history. Reconcile it before onboarding; no existing holdings were changed.'], 409);
                }
            }
            foreach ($rows as $row) {
                $stock = Stock::findOrFail($row->stock_id);
                foreach (json_decode($row->entries, true) as $entry) {
                    $identity = $this->entryIdentity($entry);
                    $token = 'kite_onboarding_entry:'.$identity;
                    $existing = Transaction::where('profile_id', $profile->id)->where('stock_id', $row->stock_id)->where('notes', 'like', '%'.$token.'%')->exists();
                    if ($existing) {
                        continue;
                    } $this->writes->createFinancialUnit($profile, $stock, ['type' => $entry['type'], 'quantity' => $entry['quantity'], 'price' => $entry['price'], 'fees' => 0, 'transaction_date' => $entry['date'], 'notes' => 'Kite holdings onboarding; '.$token.(isset($entry['broker_trade_id']) ? ' broker_trade_id:'.hash('sha256', (string) $entry['broker_trade_id']) : ''), 'source' => 'other'], auth()->user(), false);
                }
                DB::table('kite_onboarding_stocks')->where('id', $row->id)->update(['status' => 'committed', 'audit' => json_encode(array_merge(json_decode($row->audit ?: '[]', true), [['event' => 'committed', 'at' => now()->toISOString(), 'by' => auth()->id()]])), 'updated_at' => now()]);
            }

            return response()->json(['data' => $this->payload($draft->id)]);
        });
    }

    private function payload(int $draftId): array
    {
        $draft = DB::table('kite_onboarding_drafts')->find($draftId);
        $rows = DB::table('kite_onboarding_stocks')->where('draft_id', $draftId)->orderBy('symbol')->get()->map(fn ($r) => ['id' => $r->id, 'stock_id' => $r->stock_id, 'symbol' => $r->symbol, 'exchange' => $r->exchange, 'kite_quantity' => (float) $r->kite_quantity, 'kite_average_price' => (float) $r->kite_average_price, 'entries' => json_decode($r->entries, true), 'status' => $r->status, 'audit' => json_decode($r->audit ?: '[]', true)]);

        return ['id' => $draft->id, 'snapshot_at' => $draft->snapshot_at, 'broker_account_id' => data_get(json_decode($draft->kite_snapshot, true), 'broker_account_id'), 'stocks' => $rows];
    }

    private function entryIdentity(array $entry): string
    {
        if (! empty($entry['broker_trade_id'])) {
            return hash('sha256', 'broker:'.(string) $entry['broker_trade_id']);
        }

        return hash('sha256', implode('|', [(string) ($entry['type'] ?? ''), (string) ($entry['quantity'] ?? ''), (string) ($entry['price'] ?? ''), (string) ($entry['date'] ?? ''), (string) ($entry['provenance'] ?? '')]));
    }
}
