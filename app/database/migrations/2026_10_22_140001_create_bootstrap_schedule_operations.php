<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stox_bootstrap_presets', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique('stox_bootstrap_presets_name_uq');
            $table->unsignedInteger('revision')->default(1);
            $table->json('configuration');
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('updated_by_user_id');
            $table->timestamps();
            $table->foreign('created_by_user_id', 'stox_bootstrap_preset_creator_fk')->references('id')->on('portfolio_users')->restrictOnDelete();
            $table->foreign('updated_by_user_id', 'stox_bootstrap_preset_updater_fk')->references('id')->on('portfolio_users')->restrictOnDelete();
        });

        Schema::create('stox_bootstrap_schedules', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('preset_id')->nullable()->constrained('stox_bootstrap_presets', 'id', 'stox_bootstrap_schedule_preset_fk')->restrictOnDelete();
            $table->unsignedInteger('preset_revision')->nullable();
            $table->json('configuration_snapshot');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('generation')->default(1);
            $table->string('frequency', 16);
            $table->unsignedInteger('interval')->default(1);
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('monthday')->nullable();
            $table->time('local_time');
            $table->string('timezone', 64);
            $table->dateTime('starts_at_local');
            $table->date('ends_on_local')->nullable();
            $table->unsignedInteger('max_occurrences')->nullable();
            $table->unsignedInteger('occurrences_created')->default(0);
            $table->dateTime('next_occurrence_at')->nullable();
            $table->boolean('enabled')->default(false);
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('updated_by_user_id');
            $table->timestamps();
            $table->foreign('created_by_user_id', 'stox_bootstrap_schedule_creator_fk')->references('id')->on('portfolio_users')->restrictOnDelete();
            $table->foreign('updated_by_user_id', 'stox_bootstrap_schedule_updater_fk')->references('id')->on('portfolio_users')->restrictOnDelete();
            $table->index(['enabled', 'next_occurrence_at', 'id'], 'stox_bootstrap_schedule_due_idx');
        });

        Schema::create('stox_bootstrap_schedule_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_id')->constrained('stox_bootstrap_schedules', 'id', 'stox_bootstrap_occ_schedule_fk')->restrictOnDelete();
            $table->unsignedInteger('generation');
            $table->unsignedInteger('schedule_version');
            $table->dateTime('scheduled_at_utc');
            $table->dateTime('scheduled_at_local');
            $table->string('timezone', 64);
            $table->unsignedBigInteger('preset_id')->nullable();
            $table->unsignedInteger('preset_revision')->nullable();
            $table->json('configuration_snapshot');
            $table->unsignedBigInteger('actor_admin_id');
            $table->string('status', 16)->default('pending');
            $table->string('idempotency_key', 64)->unique('stox_bootstrap_occ_key_uq');
            $table->unsignedBigInteger('run_id')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('failure_code', 48)->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_delivery_error')->nullable();
            $table->timestamps();
            $table->foreign('preset_id', 'stox_bootstrap_occ_preset_fk')->references('id')->on('stox_bootstrap_presets')->nullOnDelete();
            $table->foreign('actor_admin_id', 'stox_bootstrap_occ_actor_fk')->references('id')->on('portfolio_users')->restrictOnDelete();
            $table->foreign('run_id', 'stox_bootstrap_occ_run_fk')->references('id')->on('stox_fundamental_bootstrap_runs')->restrictOnDelete();
            $table->unique(['schedule_id', 'generation', 'scheduled_at_utc'], 'stox_bootstrap_occurrence_identity_uq');
            $table->index(['status', 'id'], 'stox_bootstrap_occ_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_bootstrap_schedule_occurrences');
        Schema::dropIfExists('stox_bootstrap_schedules');
        Schema::dropIfExists('stox_bootstrap_presets');
    }
};
