<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stox_forward_collection_work', function (Blueprint $table): void {
            $table->timestamp('acquisition_eligible_at')->nullable()->after('attempts');
            $table->timestamp('publication_grace_until')->nullable()->after('acquisition_eligible_at');
            $table->index(['state', 'acquisition_eligible_at'], 'stox_forward_work_eligibility_idx');
        });

        DB::table('stox_forward_collection_work')
            ->whereNull('acquisition_eligible_at')
            ->update(['acquisition_eligible_at' => DB::raw('next_attempt_at')]);
        DB::table('stox_forward_collection_work')
            ->whereNull('publication_grace_until')
            ->update(['publication_grace_until' => DB::raw('next_attempt_at')]);
    }

    public function down(): void
    {
        Schema::table('stox_forward_collection_work', function (Blueprint $table): void {
            $table->dropIndex('stox_forward_work_eligibility_idx');
            $table->dropColumn(['acquisition_eligible_at', 'publication_grace_until']);
        });
    }
};
