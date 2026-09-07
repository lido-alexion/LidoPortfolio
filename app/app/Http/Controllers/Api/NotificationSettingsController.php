<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationEmailDestination;
use App\Services\Notification\NotificationChannelSettingsService;
use App\Services\Notification\NotificationChannelTester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationSettingsController extends Controller
{
    public function __construct(private NotificationChannelSettingsService $settings) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->settings->all($request->user())]);
    }

    public function emailDestinations(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->settings->emailDestinations($request->user())]);
    }

    public function addEmailDestination(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);

        return response()->json(['data' => $this->settings->addEmailDestination($request->user(), $validated['email'])], 201);
    }

    public function removeEmailDestination(Request $request, NotificationEmailDestination $destination): JsonResponse
    {
        $this->settings->removeEmailDestination($request->user(), $destination);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function verifyEmailDestination(Request $request, NotificationEmailDestination $destination): JsonResponse
    {
        if (! $request->hasValidSignature() || ! $this->settings->verifyEmailDestination($destination, (string) $request->query('token'))) {
            return response()->json(['message' => 'This email verification link is invalid or expired.'], 422);
        }

        return response()->json(['data' => ['verified' => true, 'email' => $destination->email]]);
    }

    public function update(Request $request, string $channel): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'bot_token' => ['nullable', 'string', 'max:255'],
            'chat_id' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'url:https', 'max:1000'],
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
