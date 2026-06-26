<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AlertNotificationService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(
        protected SettingsService $settings,
        protected AlertNotificationService $alertNotifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $profile = \activePortfolio();

        return response()->json(['data' => $this->settings->allForProfile($profile, $request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cron_time' => ['nullable', 'date_format:H:i'],
            'cron_timezone' => ['nullable', 'timezone'],
            'nse_retry_count' => ['nullable', 'integer', 'min:1', 'max:10'],
            'default_stoploss_percent' => ['nullable', 'numeric', 'min:1', 'max:50'],
            'telegram_bot_token' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:255'],
            'alpha_vantage_api_key' => ['nullable', 'string', 'max:255'],
            'notifications_enabled' => ['nullable', 'in:true,false'],
            'notification_schedules' => ['nullable', 'array', 'max:24'],
            'notification_schedules.*' => ['date_format:H:i'],
            'backend_log_level' => ['nullable', 'in:debug,info,warning,error'],
            'sync_log_retention_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'fee_components' => ['nullable', 'array', 'min:1', 'max:32'],
            'fee_components.*.id' => ['required', 'string', 'max:64'],
            'fee_components.*.label' => ['required', 'string', 'max:120'],
            'fee_components.*.value' => ['required', 'numeric', 'gte:0'],
            'fee_components.*.mode' => ['required', 'in:percentage,fixed'],
            'fee_components.*.applies_buy' => ['required', 'boolean'],
            'fee_components.*.applies_sell' => ['required', 'boolean'],
            'fee_components.*.exchange' => ['required', 'in:both,NSE,BSE'],
            'fee_components.*.gst_percent' => ['required', 'numeric', 'gte:0', 'lte:100'],
        ]);

        $profile = \activePortfolio();

        return response()->json([
            'data' => $this->settings->updateForProfile($profile, $request->user(), $validated),
        ]);
    }

    public function testTelegram(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'telegram_bot_token' => ['required', 'string', 'max:255'],
            'telegram_chat_id' => ['required', 'string', 'max:255'],
        ]);

        $result = $this->alertNotifications->sendTestNotification(
            \activePortfolio(),
            $validated['telegram_bot_token'],
            $validated['telegram_chat_id'],
        );

        if (! $result['sent']) {
            return response()->json([
                'message' => 'Telegram delivery failed. Check bot token, chat ID, and server logs.',
            ], 422);
        }

        $alertCount = $result['alert_count'];

        return response()->json([
            'message' => $alertCount > 0
                ? "Sent {$alertCount} active alert(s) to Telegram."
                : 'Sent test message to Telegram.',
            'alert_count' => $alertCount,
        ]);
    }
}
