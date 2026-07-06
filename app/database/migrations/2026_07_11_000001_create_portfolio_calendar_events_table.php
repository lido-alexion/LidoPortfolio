<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portfolio_calendar_events')) {
            return;
        }

        Schema::create('portfolio_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('color', 7)->default('#6366f1');
            $table->date('anchor_date');
            $table->string('recurrence_type', 32)->default('none');
            $table->json('recurrence_config')->nullable();
            $table->date('recurrence_end_date')->nullable();
            $table->boolean('reminder_enabled')->default(false);
            $table->json('reminder_days_before')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['profile_id', 'is_active'], 'pce_profile_active_idx');
            $table->index(['profile_id', 'anchor_date'], 'pce_profile_anchor_idx');
        });

        if (Schema::hasTable('portfolio_calendar_reminder_sends')) {
            return;
        }

        Schema::create('portfolio_calendar_reminder_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('portfolio_calendar_events')->cascadeOnDelete();
            $table->date('occurrence_date');
            $table->unsignedSmallInteger('days_before')->default(0);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['event_id', 'occurrence_date', 'days_before'], 'pcrs_event_occ_days_unique');
            $table->index(['occurrence_date', 'days_before'], 'pcrs_occ_days_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_calendar_reminder_sends');
        Schema::dropIfExists('portfolio_calendar_events');
    }
};
