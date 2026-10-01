<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiFailureIncident extends Model
{
    protected $table = 'portfolio_api_failure_incidents';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_github_checked_at' => 'datetime',
            'last_reported_at' => 'datetime',
            'closed_at' => 'datetime',
            'occurrence_count' => 'integer',
            'generation' => 'integer',
            'github_issue_number' => 'integer',
        ];
    }
}
