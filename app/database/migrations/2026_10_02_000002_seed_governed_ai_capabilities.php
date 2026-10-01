<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        foreach ([
            ['capability_id' => 'documentation_chat', 'owner' => 'V9-AI-001'],
            ['capability_id' => 'ops.log_error_triage', 'owner' => 'V9-OPS-003'],
        ] as $capability) {
            DB::table('stox_ai_capabilities')->updateOrInsert(['capability_id' => $capability['capability_id']], $capability + ['path_order' => json_encode([]), 'output_schema' => null, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('stox_ai_prompts')->updateOrInsert(['prompt_id' => $capability['capability_id'], 'version' => 1], ['template' => $capability['capability_id'] === 'documentation_chat' ? 'Answer only from the supplied maintained StoX documentation. Cite supplied sources; refuse unsupported questions.' : 'Classify the sanitized error using the required structured output only.', 'input_schema' => null, 'output_schema' => null, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }
    public function down(): void { DB::table('stox_ai_prompts')->whereIn('prompt_id', ['documentation_chat', 'ops.log_error_triage'])->delete(); DB::table('stox_ai_capabilities')->whereIn('capability_id', ['documentation_chat', 'ops.log_error_triage'])->delete(); }
};
