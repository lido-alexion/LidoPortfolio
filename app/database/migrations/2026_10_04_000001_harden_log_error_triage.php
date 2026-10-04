<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stox_log_error_triages', function (Blueprint $table) {
            $table->renameColumn('fingerprint', 'signature');
        });
        Schema::table('stox_log_error_triages', function (Blueprint $table) {
            $table->string('fingerprint', 64)->nullable()->index('log_triage_bug_fp_idx');
            $table->string('actionability', 32)->nullable();
            $table->string('bug_kind', 32)->nullable();
            $table->text('summary')->nullable();
            $table->string('failure_reason', 80)->nullable();
            $table->string('report_status', 40)->nullable();
            $table->unsignedInteger('generation')->default(0);
            $table->unsignedInteger('prompt_version')->nullable();
            $table->uuid('inference_request_id')->nullable();
            $table->string('provider_path', 191)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('last_report_attempt_at')->nullable();
        });
        // Baseline records were not allowlist-safe: discard feature-specific free text.
        DB::table('stox_log_error_triages')->update(['safe_message' => 'Legacy event withheld', 'safe_context' => null, 'component' => null, 'exception_class' => null, 'evidence' => null, 'status' => 'skipped']);
        Schema::create('stox_log_triage_decisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('triage_id')->index('log_decision_triage_idx');
            $table->uuid('request_id')->unique('log_decision_request_uq');
            $table->json('decision');
            $table->timestamp('created_at');
        });
        Schema::create('stox_github_reporter_state', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->timestamp('window_started_at')->nullable();
            $table->unsignedInteger('created_count')->default(0);
            $table->json('creation_attempts')->nullable();
            $table->unsignedInteger('failures')->default(0);
            $table->timestamp('circuit_until')->nullable();
        });
        DB::table('stox_github_reporter_state')->insert(['id' => 1]);
        Schema::create('stox_github_issue_bindings', function (Blueprint $table) {
            $table->id();
            $table->string('marker', 120)->unique('github_binding_marker_uq');
            $table->unsignedBigInteger('issue_number')->nullable();
            $table->string('issue_url')->nullable();
            $table->string('state', 32)->default('unknown');
            $table->unsignedInteger('generation')->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->json('history')->nullable();
        });
        $schema = file_get_contents(config_path('ai-schemas/ops.log_error_triage.v1.json'));
        DB::table('stox_ai_capabilities')->where('capability_id', 'ops.log_error_triage')->update(['output_schema' => $schema, 'max_concurrency' => 1, 'updated_at' => now()]);
        // Version the shipped placeholder without overwriting a governed Admin prompt.
        $prompt = DB::table('stox_ai_prompts')->where('prompt_id', 'ops.log_error_triage')->where('active', true)->first();
        if ($prompt && $prompt->version == 1 && $prompt->template === 'Classify the sanitized error using the required structured output only.') {
            DB::table('stox_ai_prompts')->where('id', $prompt->id)->update(['active' => false]);
            DB::table('stox_ai_prompts')->insert([
                'prompt_id' => 'ops.log_error_triage', 'version' => 2, 'active' => true,
                'template' => 'Classify only supplied sanitized technical facts. Return the required JSON schema. code_bug means concrete application logic failure; external_dependency means provider/DNS/outage; configuration_or_environment means missing configuration or expired credentials; expected_operational_condition means handled normal operation; data_quality_or_input means invalid input; security_or_abuse_signal means a security concern; uncertain means insufficient evidence. Prefer uncertain over inventing facts. Provider outage, rate limits and missing configuration are not code bugs unless supplied evidence proves application mishandling. Null/type/invariant/schema failures in application frames may be code bugs. Evidence items MUST be exact entries from evidence_candidates. suspected_component MUST equal supplied component or null. Never infer absent frames. Never output secrets or personal data. No tools, fixes, configuration changes or domain actions.',
                'output_schema' => $schema, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_log_triage_decisions');
        Schema::dropIfExists('stox_github_issue_bindings');
        Schema::dropIfExists('stox_github_reporter_state');
        Schema::table('stox_log_error_triages', function (Blueprint $table) {
            $table->dropIndex('log_triage_bug_fp_idx');
            $table->dropColumn(['fingerprint','actionability','bug_kind','summary','failure_reason','report_status','generation','prompt_version','inference_request_id','provider_path','next_attempt_at','lease_until','lease_token','last_report_attempt_at']);
        });
        Schema::table('stox_log_error_triages', fn (Blueprint $table) => $table->renameColumn('signature', 'fingerprint'));
        DB::table('stox_ai_prompts')->where('prompt_id', 'ops.log_error_triage')->where('version', 2)->delete();
    }
};
