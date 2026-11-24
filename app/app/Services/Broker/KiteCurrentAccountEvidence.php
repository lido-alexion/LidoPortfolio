<?php

namespace App\Services\Broker;

use App\Models\BrokerConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Direct, read-only Kite observations pinned to one stored broker session. */
final class KiteCurrentAccountEvidence
{
    private const BASE = 'https://api.kite.trade';
    private const PATHS = ['/user/profile', '/portfolio/holdings', '/portfolio/positions'];
    private const MAX_ROWS = 5000;
    private const MAX_BODY_BYTES = 2_000_000;

    /** Returns metadata only; broker rows and financial values are deliberately discarded. */
    public function capture(BrokerConnection $connection): array
    {
        // Pin the exact caller-selected identity before refreshing, not the identity
        // of a potentially reassigned connection returned by the refresh.
        if ($connection->provider !== BrokerConnection::PROVIDER_KITE || ! $connection->isUsable()
            || ! is_string($connection->broker_user_id) || trim($connection->broker_user_id) === ''
            || ! $connection->connected_at || $connection->connected_at->isFuture()
            || ! $connection->expires_at || ! $connection->expires_at->isFuture()) {
            throw new RuntimeException('session_unavailable');
        }
        $accountId = $connection->broker_user_id;
        $ownerId = (string) $connection->user_id;
        $connectionId = (string) $connection->getKey();
        $token = (string) $connection->access_token;
        $connectedAt = $connection->connected_at->copy()->utc()->format('Y-m-d\TH:i:s.u\Z');
        $expiresAt = $connection->expires_at->copy()->utc()->format('Y-m-d\TH:i:s.u\Z');
        $pinned = $connection->fresh();
        if (! $pinned || ! $this->sameSession($pinned, $ownerId, $connectionId, $accountId, $token, $connectedAt, $expiresAt)) {
            throw new RuntimeException('session_changed');
        }
        $apiKey = trim((string) config('broker.kite.api_key'));
        if ($apiKey === '' || rtrim((string) config('broker.kite.api_base'), '/') !== self::BASE) {
            throw new RuntimeException('configuration_rejected');
        }

        $started = now('UTC');
        $monotonicStart = hrtime(true);
        $responses = [];
        foreach (self::PATHS as $path) {
            try {
                $response = Http::connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false, 'verify' => true])
                    ->withHeaders(['X-Kite-Version' => '3', 'Authorization' => 'token '.$apiKey.':'.$token, 'Accept' => 'application/json'])
                    ->get(self::BASE.$path);
            } catch (Throwable) {
                throw new RuntimeException('provider_request_failed');
            }
            $body = (string) $response->body();
            if ($response->status() !== 200 || strlen($body) > self::MAX_BODY_BYTES) {
                throw new RuntimeException('provider_response_rejected');
            }
            try {
                $json = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw new RuntimeException('provider_response_malformed');
            }
            if (! $json instanceof \stdClass || ($json->status ?? null) !== 'success' || ! property_exists($json, 'data')) {
                throw new RuntimeException('provider_response_rejected');
            }
            $responses[$path] = $json->data;
            if ($path === '/user/profile') {
                $profile = $json->data;
                if (! $profile instanceof \stdClass || ! is_string($profile->user_id ?? null)
                    || ! hash_equals($accountId, $profile->user_id)) {
                    throw new RuntimeException('provider_identity_mismatch');
                }
            }
        }
        $holdings = $responses['/portfolio/holdings'];
        $positions = $responses['/portfolio/positions'];
        if (! $this->validRows($holdings, ['instrument_token', 'exchange', 'tradingsymbol', 'quantity', 'average_price', 't1_quantity', 'used_quantity']) || ! $positions instanceof \stdClass
            || ! property_exists($positions, 'net') || ! property_exists($positions, 'day')
            || ! $this->validRows($positions->net, ['instrument_token', 'exchange', 'tradingsymbol', 'product', 'quantity'])
            || ! $this->validRows($positions->day, ['instrument_token', 'exchange', 'tradingsymbol', 'product', 'quantity'])) {
            throw new RuntimeException('provider_shape_rejected');
        }

        $current = $connection->fresh();
        if (! $current || ! $this->sameSession($current, $ownerId, $connectionId, $accountId, $token, $connectedAt, $expiresAt)) {
            throw new RuntimeException('session_changed');
        }

        // Reconfirm provider identity last, with the exact same token, after the fresh local session read.
        $this->requestProfile($apiKey, $token, $accountId);
        $ended = now('UTC');
        $final = $connection->fresh();
        if (! $final || ! $this->sameSession($final, $ownerId, $connectionId, $accountId, $token, $connectedAt, $expiresAt)) {
            throw new RuntimeException('session_changed');
        }

        $durationMs = (int) ceil((hrtime(true) - $monotonicStart) / 1_000_000);
        if ($ended->lt($started) || $durationMs > 35_000 || $started->diffInMilliseconds($ended) > 35_000) {
            throw new RuntimeException('observation_time_rejected');
        }

        return [
            'provider' => 'kite', 'account_bound' => true, 'session_stable' => true,
            'observation' => ['started_at_utc' => $started->format('Y-m-d\TH:i:s.u\Z'), 'ended_at_utc' => $ended->format('Y-m-d\TH:i:s.u\Z'), 'duration_ms' => $durationMs],
            'requested' => ['profile', 'holdings', 'positions_net', 'positions_day'],
            'succeeded' => ['profile', 'holdings', 'positions_net', 'positions_day'],
            'holdings' => ['state' => $holdings === [] ? 'empty' : 'observed', 'row_count' => count($holdings)],
            'positions' => [
                'net' => ['state' => $positions->net === [] ? 'empty' : 'observed', 'row_count' => count($positions->net)],
                'day' => ['state' => $positions->day === [] ? 'empty' : 'observed', 'row_count' => count($positions->day)],
            ],
            'cash_comparable' => false,
            'claim' => 'current_account_observation_only; no settlement, payout, margin, historical coverage or acceptance asserted',
        ];
    }

    private function validRows(mixed $rows, array $required): bool
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > self::MAX_ROWS) return false;
        foreach ($rows as $row) {
            if (! $row instanceof \stdClass) return false;
            foreach ($required as $key) if (! property_exists($row, $key)) return false;
            foreach (['exchange', 'tradingsymbol', 'product'] as $key) {
                if (property_exists($row, $key) && (! is_string($row->$key) || trim($row->$key) === '')) return false;
            }
            foreach (['instrument_token', 'quantity', 'average_price', 't1_quantity', 'used_quantity'] as $key) {
                if (property_exists($row, $key)) {
                    $value = $row->$key;
                    if (! (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) || ! is_finite((float) $value)) return false;
                }
            }
        }
        return true;
    }

    private function sameSession(BrokerConnection $row, string $owner, string $id, string $account, string $token, ?string $connectedAt, ?string $expiresAt): bool
    {
        return (string) $row->user_id === $owner && (string) $row->getKey() === $id
            && $row->provider === BrokerConnection::PROVIDER_KITE && $row->broker_user_id === $account
            && hash_equals($token, (string) $row->access_token) && $row->isUsable() && $row->expires_at
            && $row->expires_at->isFuture() && $row->connected_at && ! $row->connected_at->isFuture()
            && $row->connected_at?->copy()->utc()->format('Y-m-d\TH:i:s.u\Z') === $connectedAt
            && $row->expires_at->copy()->utc()->format('Y-m-d\TH:i:s.u\Z') === $expiresAt;
    }

    private function requestProfile(string $apiKey, string $token, string $accountId): void
    {
        try {
            $response = Http::connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false, 'verify' => true])
                ->withHeaders(['X-Kite-Version' => '3', 'Authorization' => 'token '.$apiKey.':'.$token, 'Accept' => 'application/json'])
                ->get(self::BASE.'/user/profile');
            $body = (string) $response->body();
            if ($response->status() !== 200 || strlen($body) > self::MAX_BODY_BYTES) throw new RuntimeException();
            $json = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
            if (! $json instanceof \stdClass || ($json->status ?? null) !== 'success' || ! ($json->data ?? null) instanceof \stdClass
                || ! is_string($json->data->user_id ?? null) || ! hash_equals($accountId, $json->data->user_id)) throw new RuntimeException();
        } catch (Throwable) { throw new RuntimeException('provider_identity_mismatch'); }
    }
}
