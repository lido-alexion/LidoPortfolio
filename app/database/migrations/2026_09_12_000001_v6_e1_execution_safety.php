<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portfolio_users')) {
            Schema::table('portfolio_users', function (Blueprint $table) {
                if (! Schema::hasColumn('portfolio_users', 'execution_state')) {
                    $table->string('execution_state', 32)->default('normal')->after('automated_execution_entitled_at');
                }
                if (! Schema::hasColumn('portfolio_users', 'execution_halted_at')) {
                    $table->timestamp('execution_halted_at')->nullable()->after('execution_state');
                }
                if (! Schema::hasColumn('portfolio_users', 'execution_halted_by')) {
                    $table->foreignId('execution_halted_by')->nullable()->after('execution_halted_at')
                        ->constrained('portfolio_users')->nullOnDelete();
                }
                if (! Schema::hasColumn('portfolio_users', 'execution_halt_reason')) {
                    $table->string('execution_halt_reason', 500)->nullable()->after('execution_halted_by');
                }
                if (! Schema::hasColumn('portfolio_users', 'execution_recovered_at')) {
                    $table->timestamp('execution_recovered_at')->nullable()->after('execution_halt_reason');
                }
                if (! Schema::hasColumn('portfolio_users', 'live_quote_policy')) {
                    $table->string('live_quote_policy', 32)->default('strict')->after('execution_recovered_at');
                }
            });
        }

        if (! Schema::hasTable('portfolio_execution_safety_events')) {
            Schema::create('portfolio_execution_safety_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
                $table->foreignId('actor_user_id')->nullable()->constrained('portfolio_users')->nullOnDelete();
                $table->string('event', 80);
                $table->string('provider', 40)->nullable();
                $table->string('status', 40)->default('completed');
                $table->json('context')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['user_id', 'event', 'created_at'], 'execution_safety_user_event_idx');
                $table->index(['actor_user_id', 'created_at'], 'execution_safety_actor_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_execution_safety_events');

        if (Schema::hasTable('portfolio_users')) {
            Schema::table('portfolio_users', function (Blueprint $table) {
                $columns = array_values(array_filter([
                    Schema::hasColumn('portfolio_users', 'execution_state') ? 'execution_state' : null,
                    Schema::hasColumn('portfolio_users', 'execution_halted_at') ? 'execution_halted_at' : null,
                    Schema::hasColumn('portfolio_users', 'execution_halted_by') ? 'execution_halted_by' : null,
                    Schema::hasColumn('portfolio_users', 'execution_halt_reason') ? 'execution_halt_reason' : null,
                    Schema::hasColumn('portfolio_users', 'execution_recovered_at') ? 'execution_recovered_at' : null,
                    Schema::hasColumn('portfolio_users', 'live_quote_policy') ? 'live_quote_policy' : null,
                ]));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
