<?php

namespace App\Services\Microstructure;

use App\Models\MicrostructureCollectorState;
use App\Models\User;
use App\Services\Broker\BrokerConnectionService;
use App\Services\Nifty500ConstituentService;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class MicrostructureCollectorControlService
{
    public const COMMAND_START = 'start';

    public const COMMAND_STOP = 'stop';

    public const COMMAND_FORCE_RESUBSCRIBE = 'force_resubscribe';

    public const COMMAND_RETRY_FINALIZATION = 'retry_finalization';

    public const COMMAND_RETRY_BACKUP = 'retry_backup';

    public const COMMAND_REFRESH_UNIVERSE = 'refresh_universe';

    /** @var list<string> */
    public const ADMIN_COMMANDS = [
        self::COMMAND_START,
        self::COMMAND_STOP,
        self::COMMAND_FORCE_RESUBSCRIBE,
        self::COMMAND_RETRY_FINALIZATION,
        self::COMMAND_RETRY_BACKUP,
        self::COMMAND_REFRESH_UNIVERSE,
    ];

    public function __construct(
        protected BrokerConnectionService $brokerConnections,
        protected Nifty500ConstituentService $nifty500,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('microstructure_collector.enabled');
    }

    /**
     * @return array<string, mixed>
     */
    public function operationalStatus(): array
    {
        $state = MicrostructureCollectorState::current();
        $heartbeat = $this->readHeartbeat();
        $kiteUserId = config('microstructure_collector.kite_user_id');
        $kiteUser = is_int($kiteUserId) ? User::query()->find($kiteUserId) : null;
        $kiteConnected = $kiteUser !== null
            && $this->brokerConnections->connectionFor($kiteUser)?->isUsable() === true;

        $dataRoot = (string) config('microstructure_collector.data_root');
        $diskFreeGb = null;
        if ($dataRoot !== '' && is_dir($dataRoot)) {
            $free = @disk_free_space($dataRoot);
            if ($free !== false) {
                $diskFreeGb = round($free / (1024 ** 3), 2);
            }
        }

        return [
            'enabled' => $this->isEnabled(),
            'schema_version' => config('microstructure_collector.schema_version'),
            'data_root' => $dataRoot,
            'manual_hold' => $state->manual_hold,
            'manual_hold_at' => $state->manual_hold_at?->toIso8601String(),
            'last_command' => $state->last_command,
            'last_command_at' => $state->last_command_at?->toIso8601String(),
            'universe_refreshed_at' => $state->universe_refreshed_at?->toIso8601String(),
            'kite_user_id' => $kiteUserId,
            'kite_session_connected' => $kiteConnected,
            'subscribed_instrument_count' => $heartbeat['subscribed_instrument_count'] ?? null,
            'websocket_connected' => $heartbeat['websocket_connected'] ?? null,
            'last_packet_at' => $heartbeat['last_packet_at'] ?? null,
            'reconnect_count' => $heartbeat['reconnect_count'] ?? null,
            'collector_state' => $heartbeat['collector_state'] ?? 'unknown',
            'session_phase' => $heartbeat['session_phase'] ?? null,
            'coverage_summary' => $heartbeat['coverage_summary'] ?? null,
            'latest_finalized_partition' => $heartbeat['latest_finalized_partition'] ?? null,
            'backup_status' => $heartbeat['backup_status'] ?? null,
            'finalization' => $heartbeat['finalization'] ?? null,
            'raw_tick_spool' => $heartbeat['raw_tick_spool'] ?? null,
            'latest_error' => $heartbeat['latest_error'] ?? null,
            'disk_free_gb' => $diskFreeGb,
            'disk_free_warning_gb' => (float) config('microstructure_collector.disk_free_warning_gb', 5),
            'nifty500_symbol_count' => count($this->nifty500->symbols()),
            'heartbeat_received_at' => $heartbeat['_read_at'] ?? null,
        ];
    }

    public function applyAdminCommand(User $admin, string $command): MicrostructureCollectorState
    {
        if (! $this->isEnabled()) {
            throw ValidationException::withMessages([
                'command' => ['Microstructure collector is disabled on this server.'],
            ]);
        }

        if (! in_array($command, self::ADMIN_COMMANDS, true)) {
            throw ValidationException::withMessages([
                'command' => ['Unsupported collector command.'],
            ]);
        }

        $state = MicrostructureCollectorState::current();

        if ($command === self::COMMAND_STOP) {
            $state->manual_hold = true;
            $state->manual_hold_by_user_id = $admin->id;
            $state->manual_hold_at = now();
        }

        if ($command === self::COMMAND_START) {
            $state->manual_hold = false;
            $state->manual_hold_by_user_id = null;
            $state->manual_hold_at = null;
        }

        if ($command === self::COMMAND_REFRESH_UNIVERSE) {
            $this->nifty500->symbols(forceRefresh: true);
            $state->universe_refreshed_at = now();
        }

        $state->last_command = $command;
        $state->last_command_at = now();
        $state->last_command_by_user_id = $admin->id;
        $state->save();

        $this->writeCommandFile($command, $admin->id);

        return $state->fresh();
    }

    public function signalAutoStartAfterKiteLogin(User $user): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $configuredUserId = config('microstructure_collector.kite_user_id');
        if ($configuredUserId !== null && (int) $configuredUserId !== $user->id) {
            return;
        }

        $state = MicrostructureCollectorState::current();
        if ($state->manual_hold) {
            return;
        }

        $this->writeCommandFile(self::COMMAND_START, null, 'kite_auth');
    }

    protected function writeCommandFile(string $command, ?int $adminUserId, string $source = 'admin'): void
    {
        $path = (string) config('microstructure_collector.command_file');
        if ($path === '') {
            return;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'command' => $command,
            'issued_at' => now()->toIso8601String(),
            'admin_user_id' => $adminUserId,
            'source' => $source,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /**
     * @return array<string, mixed>
     */
    protected function readHeartbeat(): array
    {
        $path = (string) config('microstructure_collector.heartbeat_file');
        if ($path === '' || ! is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['latest_error' => 'Invalid collector heartbeat JSON'];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $decoded['_read_at'] = now()->toIso8601String();

        return $decoded;
    }
}
