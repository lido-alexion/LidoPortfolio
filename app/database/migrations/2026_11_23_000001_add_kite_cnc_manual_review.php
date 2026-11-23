<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kite_cnc_trade_sync_runs', function (Blueprint $table): void {
            $table->string('broker_account_id', 64)->nullable()->after('user_id');
        });
        Schema::table('kite_cnc_trade_imports', function (Blueprint $table): void {
            $table->string('broker_account_id', 64)->nullable()->after('profile_id');
            $table->string('evidence_kind', 24)->default('execution')->after('status');
            $table->string('evidence_key', 64)->nullable()->after('evidence_kind');
            $table->string('review_resolution', 32)->nullable()->after('evidence_key');
            $table->foreignId('reviewed_by')->nullable()->after('review_resolution')->constrained('portfolio_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->unique(['profile_id', 'evidence_key'], 'kite_cnc_profile_evidence_uq');
        });
        DB::table('kite_cnc_trade_imports')->select(['id', 'broker_evidence', 'broker_order_id', 'broker_trade_id', 'transaction_id'])->orderBy('id')->chunkById(250, function ($rows): void {
            foreach ($rows as $row) {
                $evidence = is_array($row->broker_evidence) ? $row->broker_evidence : json_decode((string) $row->broker_evidence, true);
                if (! is_array($evidence)) {
                    $orderId = preg_match('/^(?:[0-9]{1,64}|[A-Z]{1,4}-[0-9]{1,20})$/', (string) $row->broker_order_id) ? $row->broker_order_id : null;
                    DB::table('kite_cnc_trade_imports')->where('id', $row->id)->update(['broker_evidence' => null, 'broker_order_id' => $orderId]);
                    if ($row->transaction_id && preg_match('/^[0-9]{1,64}$/', (string) $row->broker_trade_id)) {
                        $transaction = DB::table('portfolio_transactions')->where('id', $row->transaction_id)->first(['id', 'notes']);
                        $prefix = 'Kite CNC execution '.$row->broker_trade_id.' (order ';
                        if ($transaction && is_string($transaction->notes) && str_starts_with($transaction->notes, $prefix)
                            && str_ends_with($transaction->notes, ')')) {
                            DB::table('portfolio_transactions')->where('id', $transaction->id)
                                ->update(['notes' => 'Kite CNC execution '.$row->broker_trade_id]);
                        }
                    }
                    continue;
                }
                $safe = [];
                foreach (['trade_id', 'order_id'] as $key) {
                    if (isset($evidence[$key]) && is_scalar($evidence[$key])
                        && preg_match('/^[0-9]{1,64}$/', (string) $evidence[$key])) $safe[$key] = (string) $evidence[$key];
                }
                $rawSymbol = $evidence['tradingsymbol'] ?? '';
                $symbol = is_string($rawSymbol) && strlen($rawSymbol) <= 32 ? strtoupper(trim($rawSymbol)) : '';
                if (preg_match('/^[A-Z0-9&._-]{1,32}$/', $symbol)) $safe['tradingsymbol'] = $symbol;
                $rawExchange = $evidence['exchange'] ?? '';
                $exchange = is_string($rawExchange) && strlen($rawExchange) <= 8 ? strtoupper(trim($rawExchange)) : '';
                if (in_array($exchange, ['NSE', 'BSE'], true)) $safe['exchange'] = $exchange;
                $rawSide = $evidence['transaction_type'] ?? '';
                $side = is_string($rawSide) && strlen($rawSide) <= 8 ? strtoupper(trim($rawSide)) : '';
                if (in_array($side, ['BUY', 'SELL'], true)) $safe['transaction_type'] = $side;
                $rawProduct = $evidence['product'] ?? '';
                $product = is_string($rawProduct) && strlen($rawProduct) <= 16 ? strtoupper(trim($rawProduct)) : '';
                if (in_array($product, ['CNC', 'MIS', 'NRML', 'MTF'], true)) $safe['product'] = $product;
                foreach (['quantity', 'price'] as $key) {
                    if (isset($evidence[$key]) && is_numeric($evidence[$key]) && is_finite((float) $evidence[$key])
                        && (float) $evidence[$key] > 0 && (float) $evidence[$key] <= 99999999999999) {
                        $safe[$key] = number_format((float) $evidence[$key], 4, '.', '');
                    }
                }
                if (isset($evidence['exchange_timestamp']) && is_string($evidence['exchange_timestamp'])
                    && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:?[0-9]{2})?$/', $evidence['exchange_timestamp'])) {
                    $safe['exchange_timestamp'] = $evidence['exchange_timestamp'];
                }
                $orderId = preg_match('/^(?:[0-9]{1,64}|[A-Z]{1,4}-[0-9]{1,20})$/', (string) $row->broker_order_id) ? $row->broker_order_id : null;
                DB::table('kite_cnc_trade_imports')->where('id', $row->id)->update([
                    'broker_evidence' => json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'broker_order_id' => $orderId,
                ]);
                if ($row->transaction_id && preg_match('/^[0-9]{1,64}$/', (string) $row->broker_trade_id)) {
                    $transaction = DB::table('portfolio_transactions')->where('id', $row->transaction_id)->first(['id', 'notes']);
                    $prefix = 'Kite CNC execution '.$row->broker_trade_id.' (order ';
                    if ($transaction && is_string($transaction->notes) && str_starts_with($transaction->notes, $prefix)
                        && str_ends_with($transaction->notes, ')')) {
                        DB::table('portfolio_transactions')->where('id', $transaction->id)
                            ->update(['notes' => 'Kite CNC execution '.$row->broker_trade_id]);
                    }
                }
            }
        });
        DB::table('kite_cnc_trade_sync_runs')->where('status', 'sync_failed')->update([
            'failure' => 'Kite CNC sync did not complete. Check the connection and retry.',
        ]);
        // These rows predate account provenance. Keep their existing ledger
        // links intact, but make their uncertain broker ownership visible.
        DB::table('kite_cnc_trade_imports')->whereNull('broker_account_id')->update([
            'status' => 'unresolved_candidate',
            'evidence_kind' => 'legacy_unbound',
        ]);
        DB::table('kite_cnc_trade_imports')->orderBy('id')->chunkById(250, function ($rows): void {
            foreach ($rows as $row) {
                if ($row->evidence_key !== null) continue;
                $sourceId = preg_match('/^[0-9]{1,64}$/', (string) $row->broker_trade_id)
                    ? 'trade:'.$row->broker_trade_id
                    : 'legacy:'.$row->id.':'.(string) $row->broker_evidence;
                DB::table('kite_cnc_trade_imports')->where('id', $row->id)
                    ->update(['evidence_key' => hash('sha256', $sourceId)]);
            }
        });
        Schema::create('kite_cnc_trade_review_audits', function (Blueprint $table): void {
            $table->id();
            // Audit history must survive ordinary deletion of the source row/profile.
            $table->unsignedBigInteger('trade_import_id');
            $table->unsignedBigInteger('profile_id');
            // Preserve the immutable actor identifier if a user account is later removed.
            $table->unsignedBigInteger('actor_user_id');
            $table->string('action', 32);
            $table->string('idempotency_key', 80);
            $table->string('request_hash', 64);
            $table->string('evidence_fingerprint', 64);
            $table->string('previous_status', 32);
            $table->string('resulting_status', 32);
            $table->timestamp('created_at');
            $table->unique(['profile_id', 'idempotency_key'], 'kite_cnc_review_audit_idem_uq');
            $table->index(['profile_id', 'trade_import_id'], 'kite_cnc_review_audit_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kite_cnc_trade_review_audits');
        Schema::table('kite_cnc_trade_imports', function (Blueprint $table): void {
            $table->dropUnique('kite_cnc_profile_evidence_uq');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['broker_account_id', 'evidence_kind', 'evidence_key', 'review_resolution', 'reviewed_at']);
        });
        Schema::table('kite_cnc_trade_sync_runs', function (Blueprint $table): void {
            $table->dropColumn('broker_account_id');
        });
    }
};
