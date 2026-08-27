<?php

namespace App\Services\Broker;

use App\Exceptions\DomainException;
use App\Models\BrokerConnection;
use App\Models\User;
use App\Services\PortfolioLoggerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class BrokerConnectionService
{
    public function __construct(
        protected PortfolioLoggerService $logger,
    ) {}

    /**
     * @return array{configured:bool,connected:bool,provider:string,broker_user_id:?string,expires_at:?string,usable:bool}
     */
    public function status(User $user): array
    {
        $configured = $this->kiteConfigured();
        $connection = $this->connectionFor($user);

        return [
            'configured' => $configured,
            'connected' => $connection !== null && is_string($connection->access_token) && $connection->access_token !== '',
            'usable' => $connection?->isUsable() ?? false,
            'provider' => BrokerConnection::PROVIDER_KITE,
            'broker_user_id' => $connection?->broker_user_id,
            'expires_at' => $connection?->expires_at?->toIso8601String(),
            'last_error' => $connection?->last_error,
        ];
    }

    public function loginUrl(User $user): string
    {
        if (! $this->kiteConfigured()) {
            throw new DomainException(
                'Zerodha Kite is not configured on this server.',
                'BROKER_NOT_CONFIGURED',
                503,
            );
        }

        $key = (string) config('broker.kite.api_key');

        $this->logger->event('BrokerConnectionService', 'broker.login_url', 'info', 'Kite login URL issued', [
            'user_id' => $user->id,
        ]);

        return rtrim((string) config('broker.kite.login_url'), '/').'?v=3&api_key='.urlencode($key);
    }

    public function completeLogin(User $user, #[\SensitiveParameter] string $requestToken): BrokerConnection
    {
        if (! $this->kiteConfigured()) {
            throw new DomainException('Zerodha Kite is not configured on this server.', 'BROKER_NOT_CONFIGURED', 503);
        }

        $apiKey = (string) config('broker.kite.api_key');
        $apiSecret = (string) config('broker.kite.api_secret');
        $checksum = hash('sha256', $apiKey.$requestToken.$apiSecret);

        $response = Http::timeout(20)->asForm()->post(
            rtrim((string) config('broker.kite.api_base'), '/').'/session/token',
            [
                'api_key' => $apiKey,
                'request_token' => $requestToken,
                'checksum' => $checksum,
            ],
        );

        $json = $response->json();
        if (! $response->successful() || ! is_array($json) || ($json['status'] ?? '') !== 'success') {
            $this->logger->event('BrokerConnectionService', 'broker.login_failed', 'warning', 'Kite session exchange failed', [
                'user_id' => $user->id,
                'http_status' => $response->status(),
            ]);
            throw new DomainException('Zerodha login failed. Try connecting again.', 'BROKER_LOGIN_FAILED', 422);
        }

        $access = (string) data_get($json, 'data.access_token', '');
        $brokerUser = (string) data_get($json, 'data.user_id', '');
        if ($access === '') {
            throw new DomainException('Zerodha login failed. Try connecting again.', 'BROKER_LOGIN_FAILED', 422);
        }

        $connection = BrokerConnection::query()->updateOrCreate(
            ['user_id' => $user->id, 'provider' => BrokerConnection::PROVIDER_KITE],
            [
                'broker_user_id' => $brokerUser !== '' ? $brokerUser : null,
                'connected_at' => now(),
                'expires_at' => $this->nextKiteExpiry(),
                'last_error' => null,
            ],
        );
        $connection->forceFill(['access_token' => $access])->save();

        $this->logger->event('BrokerConnectionService', 'broker.connected', 'info', 'Kite connected', [
            'user_id' => $user->id,
            'broker_connection_id' => $connection->id,
        ]);

        return $connection->fresh();
    }

    public function disconnect(User $user): void
    {
        BrokerConnection::query()
            ->where('user_id', $user->id)
            ->where('provider', BrokerConnection::PROVIDER_KITE)
            ->delete();

        $this->logger->event('BrokerConnectionService', 'broker.disconnected', 'info', 'Kite disconnected', [
            'user_id' => $user->id,
        ]);
    }

    public function connectionFor(User $user): ?BrokerConnection
    {
        return BrokerConnection::query()
            ->where('user_id', $user->id)
            ->where('provider', BrokerConnection::PROVIDER_KITE)
            ->first();
    }

    public function kiteConfigured(): bool
    {
        $key = trim((string) config('broker.kite.api_key'));
        $secret = trim((string) config('broker.kite.api_secret'));

        return $key !== '' && $secret !== '';
    }

    /**
     * Kite access tokens expire at ~06:00 IST.
     */
    public function nextKiteExpiry(?Carbon $now = null): Carbon
    {
        $now = ($now ?? now())->copy()->timezone('Asia/Kolkata');
        $expiry = $now->copy()->setTime(6, 0, 0);
        if ($now->gte($expiry)) {
            $expiry->addDay();
        }

        return $expiry;
    }
}
