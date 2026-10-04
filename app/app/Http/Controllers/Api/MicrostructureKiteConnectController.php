<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Broker\BrokerConnectionService;
use App\Services\Microstructure\MicrostructureCollectorControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MicrostructureKiteConnectController extends Controller
{
    public function status(
        Request $request,
        BrokerConnectionService $connections,
        MicrostructureCollectorControlService $collector,
    ): JsonResponse {
        $userId = (int) config('microstructure_collector.kite_user_id');
        abort_unless($userId > 0 && (int) $request->user()->id === $userId, 403);
        $user = User::query()->findOrFail($userId);
        $kite = $connections->status($user);
        $heartbeat = $collector->operationalStatus();
        $lastPacket = $heartbeat['last_packet_at'] ?? null;
        $packetRecent = false;
        if (is_string($lastPacket) && $lastPacket !== '') {
            try {
                $packetRecent = \Illuminate\Support\Carbon::parse($lastPacket)->gte(now()->subMinutes(5));
            } catch (\Throwable) {
                $packetRecent = false;
            }
        }
        $socket = (bool) ($heartbeat['websocket_connected'] ?? false);
        $displayState = ! $kite['configured'] ? 'attention'
            : (! $kite['usable'] ? 'login_needed'
            : ($socket && $packetRecent ? 'receiving_live_ticks'
                : (($lastPacket === null && ($heartbeat['collector_state'] ?? '') === 'collecting' && empty($heartbeat['latest_error'])) ? 'connecting' : 'attention')));

        return response()->json(['data' => [
            'kite' => ['configured' => $kite['configured'], 'usable' => $kite['usable']],
            'display_state' => $displayState,
            'collector' => [
                'enabled' => $heartbeat['enabled'],
                'session' => $kite['usable'],
                'websocket_connected' => $socket,
                'packet_recent' => $packetRecent,
                'last_packet_at' => $lastPacket,
                'collector_state' => $heartbeat['collector_state'] ?? 'unknown',
            ],
        ]]);
    }

    public function loginUrl(Request $request, BrokerConnectionService $connections): JsonResponse
    {
        $userId = (int) config('microstructure_collector.kite_user_id');
        abort_unless($userId > 0 && (int) $request->user()->id === $userId, 403);

        return response()->json(['data' => ['url' => $connections->loginUrl($request->user(), 'kite-connect')]]);
    }
}
