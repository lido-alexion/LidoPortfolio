<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stox_ml_acceptance_campaigns', function (Blueprint $table) {
            $table->unsignedBigInteger('wizard_sequence')->nullable();
        });

        $sequence = 0;
        DB::table('stox_ml_acceptance_campaigns')->orderBy('created_at')->orderBy('id')->pluck('id')
            ->each(function ($id) use (&$sequence) {
                DB::table('stox_ml_acceptance_campaigns')->where('id', $id)->update(['wizard_sequence' => ++$sequence]);
            });

        Schema::table('stox_ml_acceptance_campaigns', function (Blueprint $table) {
            $table->unique('wizard_sequence', 'stox_ml_acceptance_campaigns_wizard_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::table('stox_ml_acceptance_campaigns', function (Blueprint $table) {
            $table->dropUnique('stox_ml_acceptance_campaigns_wizard_sequence_unique');
            $table->dropColumn('wizard_sequence');
        });
    }
};
