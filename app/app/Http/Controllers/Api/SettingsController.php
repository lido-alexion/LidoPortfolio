<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(protected SettingsService $settings) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->settings->all()]);
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
        ]);

        return response()->json(['data' => $this->settings->update($validated)]);
    }
}
