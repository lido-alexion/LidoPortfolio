<?php

namespace App\Http\Controllers\Api\V1\TradingOs;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Services\Broker\BrokerConnectionService;
use App\Models\PortfolioProfile;
use App\Services\Execution\KiteSessionEvidenceService;
use App\Services\Reconciliation\KiteCncTradeSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BrokerController extends Controller
{
    public function __construct(
        protected BrokerConnectionService $connections,
        protected KiteSessionEvidenceService $sessionEvidence,
        protected KiteCncTradeSyncService $tradeSync,
    ) {}

    public function status(Request $request): JsonResponse
    {
        return ApiEnvelope::success([...$this->connections->status($request->user()), 'session_evidence' => $this->sessionEvidence->latest($request->user())]);
    }

    public function refreshEvidence(Request $request): JsonResponse
    {
        return ApiEnvelope::success($this->sessionEvidence->capture($request->user()));
    }

    public function kiteLoginUrl(Request $request): JsonResponse
    {
        return ApiEnvelope::success([
            'url' => $this->connections->loginUrl($request->user(), (string) $request->query('return_to', 'account')),
        ]);
    }

    public function kiteSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'request_token' => ['required', 'string', 'max:128'],
        ]);

        $this->connections->completeLogin($request->user(), $validated['request_token']);
        $user = $request->user()->fresh();
        $this->captureEvidenceSafely($user);
        $this->catchUpCurrentDayTrades($user);

        return ApiEnvelope::success($this->connections->status($request->user()->fresh()));
    }

    public function kiteCallback(Request $request): RedirectResponse|JsonResponse
    {
        $token = $request->query('request_token');
        $status = $request->query('status');
        $user = $this->connections->userFromLoginState($request->query('state'));
        $destination = $this->connections->returnToFromLoginState($request->query('state'));
        $destinationPath = match ($destination) {
            'dashboard' => '/',
            default => '/settings/account',
        };
        $frontend = rtrim((string) config('app.url'), '/').$destinationPath;

        if ($status === 'error' || ! is_string($token) || $token === '' || $user === null) {
            return redirect($frontend.'?kite=failed');
        }

        $this->connections->completeLogin($user, $token);
        $user = $user->fresh();
        $this->captureEvidenceSafely($user);
        $this->catchUpCurrentDayTrades($user);

        return redirect($frontend.'?kite=connected');
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->connections->disconnect($request->user());

        return ApiEnvelope::success($this->connections->status($request->user()->fresh()));
    }

    private function captureEvidenceSafely($user): void
    {
        try {
            $this->sessionEvidence->capture($user);
        } catch (\Throwable $error) {
            logger()->warning('Kite session evidence capture after reconnect failed', [
                'user_id' => $user->id,
                'exception_type' => get_class($error),
            ]);
        }
    }

    private function catchUpCurrentDayTrades($user): void
    {
        PortfolioProfile::query()->where('user_id', $user->id)->where('portfolio_type', PortfolioProfile::TYPE_LIVE)
            ->whereIn('execution_mode', [PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC, PortfolioProfile::EXECUTION_MODE_AUTOMATIC])
            ->each(function (PortfolioProfile $profile): void {
                try {
                    $this->tradeSync->sync($profile, 'reconnect');
                } catch (\Throwable $error) {
                    logger()->warning('Kite CNC reconnect catch-up failed', ['profile_id' => $profile->id, 'reason' => $error->getMessage()]);
                }
            });
    }
}
