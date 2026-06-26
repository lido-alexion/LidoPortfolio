<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs partial deploys where portfolio_alert_policies was created but
 * portfolio_alerts policy columns were not added (early-return bug in initial migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_alert_policies')) {
            return;
        }

        if (! Schema::hasTable('portfolio_alerts')) {
            return;
        }

        if (! Schema::hasColumn('portfolio_alerts', 'alert_policy_id')) {
            Schema::table('portfolio_alerts', function (Blueprint $table) {
                $table->foreignId('alert_policy_id')->nullable()->after('stock_id')
                    ->constrained('portfolio_alert_policies')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('portfolio_alerts', 'instance_key')) {
            Schema::table('portfolio_alerts', function (Blueprint $table) {
                $table->string('instance_key', 191)->nullable()->after('alert_type');
            });
        }

        if (! Schema::hasColumn('portfolio_alerts', 'condition_display')) {
            Schema::table('portfolio_alerts', function (Blueprint $table) {
                $table->text('condition_display')->nullable()->after('message');
            });
        }

        if (! Schema::hasColumn('portfolio_alerts', 'action_suggested')) {
            Schema::table('portfolio_alerts', function (Blueprint $table) {
                $table->string('action_suggested', 255)->nullable()->after('condition_display');
            });
        }

        if (! Schema::hasColumn('portfolio_alerts', 'context_json')) {
            Schema::table('portfolio_alerts', function (Blueprint $table) {
                $table->json('context_json')->nullable()->after('action_suggested');
            });
        }

        if (
            Schema::hasColumn('portfolio_alerts', 'profile_id')
            && Schema::hasColumn('portfolio_alerts', 'instance_key')
        ) {
            try {
                Schema::table('portfolio_alerts', function (Blueprint $table) {
                    $table->index(['profile_id', 'instance_key'], 'pa_profile_instance_idx');
                });
            } catch (\Throwable) {
                // Index may already exist.
            }
        }
    }

    public function down(): void
    {
        // Non-destructive repair migration — no down.
    }
};
