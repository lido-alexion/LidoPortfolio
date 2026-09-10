<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_paper_simulation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('portfolio_profiles')->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->date('effective_session_date');
            $table->foreignId('user_id')->nullable()->constrained('portfolio_users')->nullOnDelete();
            $table->json('evidence')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['profile_id', 'effective_session_date'], 'paper_event_profile_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_paper_simulation_events');
    }
};
