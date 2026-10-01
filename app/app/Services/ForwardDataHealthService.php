<?php

namespace App\Services;

use App\Models\ForwardCollectionWork;
use Illuminate\Support\Facades\DB;

class ForwardDataHealthService
{
    public function report(): array
    {
        $rows = ForwardCollectionWork::query()->get();
        $grouped = $rows->groupBy('dataset_key');
        $datasets = [];
        foreach ($grouped as $key => $items) {
            $datasets[$key] = [
                'state' => $items->contains(fn ($row) => in_array($row->state, ['blocked_configuration', 'blocked_quality', 'exhausted'], true)) ? 'blocked' : ($items->contains(fn ($row) => $row->state !== 'succeeded') ? 'degraded' : 'ready'),
                'scope' => $items->pluck('scope_key')->unique()->values()->all(),
                'latest_expected_session' => $items->max('session_date'),
                'latest_validated_session' => $items->where('state', 'succeeded')->max('session_date'),
                'last_attempted_fetch' => $items->max('last_attempted_at'),
                'last_successful_check' => $items->max('last_successful_at'),
                'coverage' => $items->isEmpty() ? 0.0 : round($items->where('state', 'succeeded')->count() / $items->count() * 100, 4),
                'unresolved' => $items->where('state', '!=', 'succeeded')->count(),
                'oldest_overdue_work' => $items->where('state', '!=', 'succeeded')->min('session_date'),
                'reason_codes' => $items->where('state', '!=', 'succeeded')->pluck('last_error_code')->filter()->countBy()->all(),
            ];
        }
        return ['generated_at' => now()->toIso8601String(), 'datasets' => $datasets];
    }
}
