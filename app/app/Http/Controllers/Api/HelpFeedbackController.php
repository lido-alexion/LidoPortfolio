<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HelpFeedbackAggregate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HelpFeedbackController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['topic_id' => ['required', 'string', 'max:32'], 'event' => ['required', 'in:selected,helpful,not_helpful,no_match,weak_match'], 'query' => ['nullable', 'string', 'max:160']]);
        $queryDigest = in_array($data['event'], ['no_match', 'weak_match'], true) && filled($data['query'] ?? null)
            ? hash_hmac('sha256', mb_strtolower(trim($data['query'])), (string) config('app.key'))
            : '-';
        $aggregate = HelpFeedbackAggregate::firstOrCreate(['topic_id' => $data['topic_id'], 'event_date' => today(), 'query_digest' => $queryDigest]);
        $field = match ($data['event']) { 'selected' => 'selected_count', 'helpful' => 'helpful_count', 'not_helpful' => 'not_helpful_count', 'no_match' => 'no_match_count', default => 'weak_match_count' };
        $aggregate->increment($field);
        return response()->json(['data' => ['recorded' => true]]);
    }
}
