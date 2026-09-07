<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Notification\NotificationChannelSettingsService;
use App\Services\Notification\NotificationChannelTester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationSettingsController extends Controller
{
    public function __construct(private NotificationChannelSettingsService $settings) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->settings->all($request->user())]);
    }

    public function update(Request $request, string $channel): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'bot_token' => [Rule::requiredIf($channel === 'telegram'), 'nullable', 'string', 'max:255'],
            'chat_id' => [Rule::requiredIf($channel === 'telegram'), 'nullable', 'string', 'max:255'],
            'url' => [Rule::requiredIf($channel === 'webhook'), 'nullable', 'url:https', 'max:1000'],
        ]);

        $configuration = match ($channel) {
            'telegram' => ['bot_token' => $validated['bot_token'] ?? null, 'chat_id' => $validated['chat_id'] ?? null],
            'webhook' => ['url' => $validated['url'] ?? null],
            default => [],
        };

        return response()->json(['data' => $this->settings->update(
            $request->user(),
            $channel,
            $configuration,
            array_key_exists('enabled', $validated) ? (bool) $validated['enabled'] : null,
        )]);
    }

    public function test(Request $request, string $channel, NotificationChannelTester $tester): JsonResponse
    {
        $result = $tester->test($request->user(), $channel);

        return response()->json(['data' => $result], $result['successful'] ? 200 : 422);
    }
}
