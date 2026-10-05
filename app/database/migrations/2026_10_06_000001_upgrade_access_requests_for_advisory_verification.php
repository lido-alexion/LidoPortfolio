<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stox_access_requests', function (Blueprint $table): void {
            $table->string('verification_status', 16)->default('unverified')->after('status');
            $table->timestamp('verified_at')->nullable()->change();
            $table->timestamp('expires_at')->nullable();
            $table->string('expiry_reason', 64)->nullable();
            $table->index(['status', 'verification_status', 'created_at'], 'access_request_expiry_idx');
        });

        Schema::table('stox_access_request_verifications', function (Blueprint $table): void {
            $table->unsignedBigInteger('access_request_id')->nullable()->after('id');
            $table->text('token_encrypted')->nullable();
            $table->string('email_delivery_status', 24)->default('not_queued');
            $table->unsignedInteger('email_delivery_attempts')->default(0);
            $table->timestamp('email_queued_at')->nullable();
            $table->timestamp('email_accepted_at')->nullable();
            $table->string('email_last_error_code', 64)->nullable();
            $table->index(['email_delivery_status', 'email_queued_at'], 'access_req_verify_email_delivery_idx');
            $table->foreign('access_request_id', 'access_request_verification_request_fk')
                ->references('id')->on('stox_access_requests')->nullOnDelete();
        });

        // Preserve all pre-upgrade business records as verified requests and link
        // matching legacy tokens without deleting or invalidating them.
        DB::table('stox_access_requests')->whereNotNull('verified_at')->update(['verification_status' => 'verified']);
        DB::table('stox_access_request_verifications')
            ->whereNull('access_request_id')
            ->orderBy('id')
            ->get()
            ->each(function (object $verification): void {
                $requestId = DB::table('stox_access_requests')
                    ->where('email_normalized', $verification->email_normalized)
                    ->where('created_at', '>=', $verification->created_at)
                    ->orderBy('id')
                    ->value('id');
                if ($requestId !== null) {
                    DB::table('stox_access_request_verifications')->where('id', $verification->id)
                        ->update(['access_request_id' => $requestId]);
                }
            });

        $pendingLegacyVerifications = DB::table('stox_access_request_verifications')
            ->whereNull('access_request_id')->whereNull('used_at')->where('expires_at', '>', now())->orderBy('id')->get();
        foreach ($pendingLegacyVerifications->groupBy('email_normalized') as $email => $verifications) {
            $requestId = DB::table('stox_access_requests')->where('email_normalized', $email)
                ->where('status', 'pending')->orderBy('id')->value('id');
            if ($requestId === null) {
                $first = $verifications->first();
                $createdAt = $first->created_at ?? now();
                $requestId = DB::table('stox_access_requests')->insertGetId([
                    'full_name' => $first->full_name,
                    'email_normalized' => $email,
                    'status' => 'pending',
                    'verification_status' => 'unverified',
                    'verified_at' => null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
                DB::table('stox_access_request_audit_events')->insert([
                    'event_type' => 'legacy_pending_import',
                    'email_normalized' => $email,
                    'access_request_id' => $requestId,
                    'actor_user_id' => null,
                    'context' => json_encode(['verification_ids' => $verifications->pluck('id')->all()], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            }
            DB::table('stox_access_request_verifications')->whereIn('id', $verifications->pluck('id'))
                ->update(['access_request_id' => $requestId]);
        }
    }

    public function down(): void
    {
        Schema::table('stox_access_request_verifications', function (Blueprint $table): void {
            $table->dropForeign('access_request_verification_request_fk');
            $table->dropIndex('access_req_verify_email_delivery_idx');
            $table->dropColumn(['access_request_id', 'token_encrypted', 'email_delivery_status', 'email_delivery_attempts', 'email_queued_at', 'email_accepted_at', 'email_last_error_code']);
        });
        Schema::table('stox_access_requests', function (Blueprint $table): void {
            $table->dropIndex('access_request_expiry_idx');
            $table->dropColumn(['verification_status', 'expires_at', 'expiry_reason']);
        });
    }
};
