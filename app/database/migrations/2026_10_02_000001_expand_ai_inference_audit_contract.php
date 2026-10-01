<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void { Schema::table('stox_ai_inference_events', function (Blueprint $table): void { $table->string('prompt_id')->nullable()->after('capability_id'); $table->unsignedInteger('prompt_version')->nullable()->after('prompt_id'); $table->unsignedBigInteger('user_id')->nullable()->index()->after('trace_id'); $table->unsignedBigInteger('account_id')->nullable()->index()->after('user_id'); $table->unsignedInteger('latency_ms')->nullable(); $table->json('usage')->nullable(); $table->json('provenance')->nullable(); }); }
    public function down(): void { Schema::table('stox_ai_inference_events', function (Blueprint $table): void { $table->dropColumn(['prompt_id','prompt_version','user_id','account_id','latency_ms','usage','provenance']); }); }
};
