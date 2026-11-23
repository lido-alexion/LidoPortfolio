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
        // Add review provenance without rewriting legacy broker evidence or
        // linked portfolio transactions. Review payloads sanitize evidence at
        // read time, and transaction notes are historical ledger data.
        DB::table('kite_cnc_trade_imports')->select(['id', 'broker_evidence', 'broker_trade_id', 'evidence_key'])->orderBy('id')->chunkById(250, function ($rows): void {
            foreach ($rows as $row) {
                if ($row->evidence_key !== null) continue;
                $sourceId = preg_match('/^[0-9]{1,64}$/', (string) $row->broker_trade_id)
                    ? 'trade:'.$row->broker_trade_id
                    : 'legacy:'.$row->id.':'.(string) $row->broker_evidence;
                DB::table('kite_cnc_trade_imports')->where('id', $row->id)
                    ->update(['evidence_key' => hash('sha256', $sourceId)]);
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
