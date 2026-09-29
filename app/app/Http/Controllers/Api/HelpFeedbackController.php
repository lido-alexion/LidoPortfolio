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
        $data = $request->validate(['topic_id' => ['required', 'string', 'max:32'], 'event' => ['required', 'in:selected,helpful,not_helpful']]);
        $aggregate = HelpFeedbackAggregate::firstOrCreate(['topic_id' => $data['topic_id'], 'event_date' => today()]);
        $field = match ($data['event']) { 'selected' => 'selected_count', 'helpful' => 'helpful_count', default => 'not_helpful_count' };
        $aggregate->increment($field);
        return response()->json(['data' => ['recorded' => true]]);
    }
}
