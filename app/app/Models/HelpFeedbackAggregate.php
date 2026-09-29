<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['topic_id', 'event_date', 'selected_count', 'helpful_count', 'not_helpful_count'])]
class HelpFeedbackAggregate extends Model
{
    protected $table = 'portfolio_help_feedback_aggregates';
    protected function casts(): array { return ['event_date' => 'date', 'selected_count' => 'integer', 'helpful_count' => 'integer', 'not_helpful_count' => 'integer']; }
}
