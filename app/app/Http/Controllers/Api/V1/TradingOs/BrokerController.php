<?php

namespace App\Http\Controllers\Api\V1\TradingOs;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Services\Broker\BrokerConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BrokerController extends Controller
{
    public function __construct(
        protected BrokerConnectionService $connections,
    ) {}

    public function status(Request $request): JsonResponse
    {
        return ApiEnvelope::success($this->connections->status($request->user()));
    }

    public function kiteLoginUrl(Request $request): JsonResponse
    {
        return ApiEnvelope::success([
            'url' => $this->connections->loginUrl($request->user()),
        ]);
    }

    public function kiteSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'request_token' => ['required', 'string', 'max:128'],
        ]);

        $this->connections->completeLogin($request->user(), $validated['request_token']);

        return ApiEnvelope::success($this->connections->status($request->user()->fresh()));
    }

    public function kiteCallback(Request $request): RedirectResponse|JsonResponse
    {
        $token = $request->query('request_token');
        $status = $request->query('status');
        $user = $this->connections->userFromLoginState($request->query('state'));
        $frontend = rtrim((string) config('app.url'), '/').'/settings/account';

        if ($status === 'error' || ! is_string($token) || $token === '' || $user === null) {
            return redirect($frontend.'?kite=failed');
        }

        $this->connections->completeLogin($user, $token);

        return redirect($frontend.'?kite=connected');
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->connections->disconnect($request->user());

        return ApiEnvelope::success($this->connections->status($request->user()->fresh()));
    }
}
