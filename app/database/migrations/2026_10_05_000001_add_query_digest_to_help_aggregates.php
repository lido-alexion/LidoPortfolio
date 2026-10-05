<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_help_feedback_aggregates', function (Blueprint $table) {
            $table->dropUnique('portfolio_help_feedback_aggregates_topic_id_event_date_unique');
            $table->unsignedInteger('no_match_count')->default(0);
            $table->unsignedInteger('weak_match_count')->default(0);
            $table->char('query_digest', 64)->default('-');
            $table->unique(['topic_id', 'event_date', 'query_digest'], 'portfolio_help_aggregate_query_unique');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_help_feedback_aggregates', function (Blueprint $table) {
            $table->dropUnique('portfolio_help_aggregate_query_unique');
            $table->dropColumn('query_digest');
            $table->dropColumn(['no_match_count', 'weak_match_count']);
            $table->unique(['topic_id', 'event_date'], 'portfolio_help_feedback_aggregates_topic_id_event_date_unique');
        });
    }
};
