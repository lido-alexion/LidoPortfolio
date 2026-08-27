<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V4-FEAT-001 — TOTP, execution mode, entitlement, broker connection, broker order fields.
 * Idempotent: safe to re-run after partial failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portfolio_users')) {
            Schema::table('portfolio_users', function (Blueprint $table) {
                if (! Schema::hasColumn('portfolio_users', 'totp_secret')) {
                    $table->text('totp_secret')->nullable();
                }
                if (! Schema::hasColumn('portfolio_users', 'totp_pending_secret')) {
                    $table->text('totp_pending_secret')->nullable();
                }
                if (! Schema::hasColumn('portfolio_users', 'totp_confirmed_at')) {
                    $table->timestamp('totp_confirmed_at')->nullable();
                }
                if (! Schema::hasColumn('portfolio_users', 'totp_last_counter')) {
                    $table->unsignedBigInteger('totp_last_counter')->nullable();
                }
                if (! Schema::hasColumn('portfolio_users', 'totp_recovery_codes')) {
                    $table->text('totp_recovery_codes')->nullable();
                }
                if (! Schema::hasColumn('portfolio_users', 'automated_execution_entitled_at')) {
                    $table->timestamp('automated_execution_entitled_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('portfolio_profiles')
            && ! Schema::hasColumn('portfolio_profiles', 'execution_mode')) {
            Schema::table('portfolio_profiles', function (Blueprint $table) {
                $table->string('execution_mode', 32)->default('manual');
            });
        }

        if (! Schema::hasTable('portfolio_broker_connections')) {
            Schema::create('portfolio_broker_connections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('portfolio_users')->cascadeOnDelete();
                $table->string('provider', 32)->default('kite');
                $table->string('broker_user_id', 64)->nullable();
                $table->text('access_token')->nullable();
                $table->timestamp('connected_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->string('last_error', 191)->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'provider'], 'brk_conn_user_provider_uq');
            });
        }

        if (! Schema::hasTable('portfolio_tos_execution_decisions')) {
            Schema::create('portfolio_tos_execution_decisions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
                $table->foreignId('recommendation_id')->constrained('portfolio_tos_recommendations')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('portfolio_users')->nullOnDelete();
                $table->string('mode', 32);
                $table->string('trigger', 32);
                $table->string('outcome', 32);
                $table->string('reason', 191)->nullable();
                $table->unsignedBigInteger('order_id')->nullable();
                $table->timestamps();

                $table->index(['profile_id', 'recommendation_id'], 'tos_exec_dec_profile_rec_idx');
            });
        }

        if (Schema::hasTable('portfolio_tos_orders')) {
            Schema::table('portfolio_tos_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('portfolio_tos_orders', 'broker_provider')) {
                    $table->string('broker_provider', 32)->nullable();
                }
                if (! Schema::hasColumn('portfolio_tos_orders', 'broker_order_id')) {
                    $table->string('broker_order_id', 64)->nullable();
                }
                if (! Schema::hasColumn('portfolio_tos_orders', 'broker_status')) {
                    $table->string('broker_status', 32)->nullable();
                }
                if (! Schema::hasColumn('portfolio_tos_orders', 'filled_quantity')) {
                    $table->decimal('filled_quantity', 18, 4)->default(0);
                }
                if (! Schema::hasColumn('portfolio_tos_orders', 'average_fill_price')) {
                    $table->decimal('average_fill_price', 18, 4)->nullable();
                }
                if (! Schema::hasColumn('portfolio_tos_orders', 'submission_key')) {
                    $table->string('submission_key', 80)->nullable();
                }
                if (! Schema::hasColumn('portfolio_tos_orders', 'execution_decision_id')) {
                    $table->unsignedBigInteger('execution_decision_id')->nullable();
                }
                if (! Schema::hasColumn('portfolio_tos_orders', 'last_broker_sync_at')) {
                    $table->timestamp('last_broker_sync_at')->nullable();
                }
            });

            try {
                Schema::table('portfolio_tos_orders', function (Blueprint $table) {
                    $table->unique('submission_key', 'tos_ord_submission_key_uq');
                });
            } catch (\Throwable) {
                // Index already exists.
            }
            try {
                Schema::table('portfolio_tos_orders', function (Blueprint $table) {
                    $table->index(['broker_provider', 'broker_order_id'], 'tos_ord_broker_order_idx');
                });
            } catch (\Throwable) {
                // Index already exists.
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('portfolio_tos_orders')) {
            Schema::table('portfolio_tos_orders', function (Blueprint $table) {
                try {
                    $table->dropUnique('tos_ord_submission_key_uq');
                } catch (\Throwable) {
                }
                try {
                    $table->dropIndex('tos_ord_broker_order_idx');
                } catch (\Throwable) {
                }
                $cols = array_values(array_filter(
                    [
                        'broker_provider',
                        'broker_order_id',
                        'broker_status',
                        'filled_quantity',
                        'average_fill_price',
                        'submission_key',
                        'execution_decision_id',
                        'last_broker_sync_at',
                    ],
                    fn (string $c) => Schema::hasColumn('portfolio_tos_orders', $c),
                ));
                if ($cols !== []) {
                    $table->dropColumn($cols);
                }
            });
        }

        Schema::dropIfExists('portfolio_tos_execution_decisions');
        Schema::dropIfExists('portfolio_broker_connections');

        if (Schema::hasTable('portfolio_profiles') && Schema::hasColumn('portfolio_profiles', 'execution_mode')) {
            Schema::table('portfolio_profiles', function (Blueprint $table) {
                $table->dropColumn('execution_mode');
            });
        }

        if (Schema::hasTable('portfolio_users')) {
            Schema::table('portfolio_users', function (Blueprint $table) {
                $cols = array_values(array_filter(
                    [
                        'totp_secret',
                        'totp_pending_secret',
                        'totp_confirmed_at',
                        'totp_last_counter',
                        'totp_recovery_codes',
                        'automated_execution_entitled_at',
                    ],
                    fn (string $c) => Schema::hasColumn('portfolio_users', $c),
                ));
                if ($cols !== []) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
