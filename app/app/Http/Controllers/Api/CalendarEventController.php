<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Services\CalendarEventService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CalendarEventController extends Controller
{
    public function __construct(protected CalendarEventService $events) {}

    public function index(): JsonResponse
    {
        $profile = \activePortfolio();

        return response()->json([
            'data' => $this->events->listForProfile($profile),
        ]);
    }

    public function occurrences(Request $request): JsonResponse
    {
        $profile = \activePortfolio();

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $data = $this->events->occurrencesForProfile(
            $profile,
            Carbon::parse($validated['from'])->startOfDay(),
            Carbon::parse($validated['to'])->endOfDay(),
        );

        return response()->json(['data' => $data]);
    }

    public function upcoming(): JsonResponse
    {
        $profile = \activePortfolio();

        return response()->json([
            'data' => $this->events->upcomingForProfile($profile, 31),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $validated = $this->validatePayload($request);

        $event = $this->events->create($profile, $validated);

        return response()->json(['data' => $event], 201);
    }

    public function update(Request $request, CalendarEvent $calendarEvent): JsonResponse
    {
        $profile = \activePortfolio();
        $validated = $this->validatePayload($request, partial: true);

        $event = $this->events->update($calendarEvent, $profile, $validated);

        return response()->json(['data' => $event]);
    }

    public function destroy(CalendarEvent $calendarEvent): JsonResponse
    {
        $profile = \activePortfolio();
        $this->events->delete($calendarEvent, $profile);

        return response()->json(['message' => 'Calendar event deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $partial = false): array
    {
        $recurrenceTypes = [
            CalendarEvent::RECURRENCE_NONE,
            CalendarEvent::RECURRENCE_DAILY,
            CalendarEvent::RECURRENCE_WEEKLY,
            CalendarEvent::RECURRENCE_MONTHLY_DAY,
            CalendarEvent::RECURRENCE_MONTHLY_WEEKDAY,
            CalendarEvent::RECURRENCE_YEARLY_DAY,
            CalendarEvent::RECURRENCE_YEARLY_WEEKDAY,
        ];

        $rules = [
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'anchor_date' => [$partial ? 'sometimes' : 'required', 'date'],
            'recurrence_type' => ['sometimes', 'string', Rule::in($recurrenceTypes)],
            'recurrence_config' => ['nullable', 'array'],
            'recurrence_config.interval' => ['nullable', 'integer', 'min:1', 'max:365'],
            'recurrence_config.weekday' => ['nullable', 'integer', 'min:0', 'max:6'],
            'recurrence_config.month_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'recurrence_config.month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'recurrence_config.week_of_month' => ['nullable', 'integer', 'min:-1', 'max:5'],
            'recurrence_end_date' => ['nullable', 'date'],
            'reminder_enabled' => ['sometimes', 'boolean'],
            'reminder_days_before' => ['nullable', 'array'],
            'reminder_days_before.*' => ['integer', 'min:0', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        return $request->validate($rules);
    }
}
