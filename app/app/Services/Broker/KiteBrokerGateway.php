<?php

namespace App\Services\Broker;

use App\Exceptions\DomainException;
use App\Models\BrokerConnection;
use App\Services\PortfolioLoggerService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Zerodha Kite Connect adapter. Production only — tests bind FakeBrokerGateway.
 */
class KiteBrokerGateway implements BrokerGateway
{
    public function __construct(
        protected PortfolioLoggerService $logger,
    ) {}

    public function provider(): string
    {
        return BrokerConnection::PROVIDER_KITE;
    }

    public function placeOrder(BrokerOrderRequest $request): BrokerSubmission
    {
        $token = $this->accessToken($request->userId);
        $tag = substr(hash('sha256', $request->submissionKey), 0, 20);

        try {
            $response = Http::timeout(20)
                ->withHeaders($this->headers($token))
                ->asForm()
                ->post(rtrim((string) config('broker.kite.api_base'), '/').'/orders/regular', [
                    'tradingsymbol' => $request->symbol,
                    'exchange' => $request->exchange ?: 'NSE',
                    'transaction_type' => strtoupper($request->side) === 'SELL' ? 'SELL' : 'BUY',
                    'quantity' => (int) round($request->quantity),
                    'product' => $request->product ?? 'CNC',
                    'order_type' => $request->orderType ?? 'MARKET',
                    'validity' => 'DAY',
                    'tag' => $tag,
                ]);
        } catch (ConnectionException $e) {
            throw new BrokerAmbiguousException('Kite place timed out.', 0, $e);
        }

        if ($response->status() >= 500) {
            throw new BrokerAmbiguousException('Kite place returned HTTP '.$response->status());
        }

        $json = $response->json();
        if (! is_array($json) || ($json['status'] ?? '') !== 'success') {
            $message = is_array($json) ? (string) ($json['message'] ?? 'Kite rejected the order.') : 'Kite rejected the order.';
            $errorType = is_array($json) ? (string) ($json['error_type'] ?? '') : '';
            $this->logger->event('KiteBrokerGateway', 'broker.place_rejected', 'warning', 'Kite place rejected', [
                'user_id' => $request->userId,
                'recommendation_id' => $request->recommendationId,
                'http_status' => $response->status(),
            ]);

            throw new DomainException(
                $message,
                $errorType === 'MarginException' ? 'BROKER_INSUFFICIENT_FUNDS' : 'BROKER_REJECTED',
                422,
            );
        }

        $orderId = (string) data_get($json, 'data.order_id', '');
        if ($orderId === '') {
            throw new BrokerAmbiguousException('Kite accepted without an order id.');
        }

        $this->logger->event('KiteBrokerGateway', 'broker.placed', 'info', 'Kite order placed', [
            'user_id' => $request->userId,
            'recommendation_id' => $request->recommendationId,
            'broker_order_id' => $orderId,
        ]);

        return new BrokerSubmission($orderId, 'submitted');
    }

    public function availableEquityFunds(int $userId): ?float
    {
        $token = $this->accessToken($userId);
        try {
            $response = Http::timeout(20)
                ->withHeaders($this->headers($token))
                ->get(rtrim((string) config('broker.kite.api_base'), '/').'/user/margins/equity');
        } catch (ConnectionException) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $data = data_get($response->json(), 'data');
        if (! is_array($data)) {
            return null;
        }
        $value = data_get($data, 'available.live_balance', $data['net'] ?? null);

        return is_numeric($value) ? max(0.0, (float) $value) : null;
    }

    public function fetchOrder(int $userId, string $brokerOrderId): ?BrokerOrderSnapshot
    {
        $token = $this->accessToken($userId);
        try {
            $response = Http::timeout(20)
                ->withHeaders($this->headers($token))
                ->get(rtrim((string) config('broker.kite.api_base'), '/').'/orders/'.$brokerOrderId);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $row = data_get($response->json(), 'data');
        if (! is_array($row)) {
            return null;
        }
        if (array_is_list($row) && $row !== []) {
            $row = $row[array_key_last($row)];
        }
        if (! is_array($row)) {
            return null;
        }

        return $this->mapSnapshot($brokerOrderId, $row);
    }

    public function cancelOrder(int $userId, string $brokerOrderId): BrokerOrderSnapshot
    {
        $token = $this->accessToken($userId);
        $response = Http::timeout(20)
            ->withHeaders($this->headers($token))
            ->delete(rtrim((string) config('broker.kite.api_base'), '/').'/orders/regular/'.$brokerOrderId);

        $fetched = $this->fetchOrder($userId, $brokerOrderId);
        if ($fetched) {
            return $fetched;
        }

        if (! $response->successful()) {
            throw new DomainException('Kite could not cancel the order.', 'BROKER_CANCEL_FAILED', 422);
        }

        return new BrokerOrderSnapshot($brokerOrderId, 'cancelled', 0, 0, null, 'CANCELLED');
    }

    public function placeGtt(BrokerGttRequest $request): BrokerSubmission
    {
        return $this->submitGtt('POST', '/gtt/triggers', $request, null);
    }

    public function modifyGtt(int $userId, string $brokerGttId, BrokerGttRequest $request): BrokerSubmission
    {
        return $this->submitGtt('PUT', '/gtt/triggers/'.$brokerGttId, $request, $brokerGttId);
    }

    public function fetchGtt(int $userId, string $brokerGttId): ?BrokerGttSnapshot
    {
        $token = $this->accessToken($userId);
        try {
            $response = Http::timeout(20)
                ->withHeaders($this->headers($token))
                ->get(rtrim((string) config('broker.kite.api_base'), '/').'/gtt/triggers/'.$brokerGttId);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $row = data_get($response->json(), 'data');
        if (! is_array($row)) {
            return null;
        }

        return $this->mapGttSnapshot($brokerGttId, $row);
    }

    public function cancelGtt(int $userId, string $brokerGttId): BrokerGttSnapshot
    {
        $token = $this->accessToken($userId);
        try {
            $response = Http::timeout(20)
                ->withHeaders($this->headers($token))
                ->delete(rtrim((string) config('broker.kite.api_base'), '/').'/gtt/triggers/'.$brokerGttId);
        } catch (ConnectionException $e) {
            throw new BrokerAmbiguousException('Kite GTT cancel timed out.', 0, $e);
        }

        if ($response->status() >= 500) {
            throw new BrokerAmbiguousException('Kite GTT cancel returned HTTP '.$response->status());
        }

        $fetched = $this->fetchGtt($userId, $brokerGttId);
        if ($fetched) {
            return $fetched;
        }

        if (! $response->successful()) {
            throw new DomainException('Kite could not cancel the GTT.', 'BROKER_CANCEL_FAILED', 422);
        }

        return new BrokerGttSnapshot($brokerGttId, 'cancelled', 0, 0, 0, null, null, 'CANCELLED');
    }

    protected function submitGtt(string $method, string $path, BrokerGttRequest $request, ?string $existingId): BrokerSubmission
    {
        $token = $this->accessToken($request->userId);
        $payload = [
            'type' => 'single',
            'condition' => json_encode([
                'exchange' => $request->exchange ?: 'NSE',
                'tradingsymbol' => $request->symbol,
                'trigger_values' => [(float) $request->triggerPrice],
                'last_price' => (float) $request->lastPrice,
            ]),
            'orders' => json_encode([[
                'exchange' => $request->exchange ?: 'NSE',
                'tradingsymbol' => $request->symbol,
                'product' => $request->product ?? 'CNC',
                'order_type' => 'LIMIT',
                'transaction_type' => strtoupper($request->side) === 'BUY' ? 'BUY' : 'SELL',
                'quantity' => (int) max(1, round($request->quantity)),
                'price' => round($request->triggerPrice, 2),
            ]]),
        ];

        try {
            $pending = Http::timeout(20)
                ->withHeaders($this->headers($token))
                ->asForm();
            $url = rtrim((string) config('broker.kite.api_base'), '/').$path;
            $response = strtoupper($method) === 'PUT'
                ? $pending->put($url, $payload)
                : $pending->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new BrokerAmbiguousException('Kite GTT '.$method.' timed out.', 0, $e);
        }

        if ($response->status() >= 500) {
            throw new BrokerAmbiguousException('Kite GTT '.$method.' returned HTTP '.$response->status());
        }

        $json = $response->json();
        if (! is_array($json) || ($json['status'] ?? '') !== 'success') {
            $message = is_array($json) ? (string) ($json['message'] ?? 'Kite rejected the GTT.') : 'Kite rejected the GTT.';
            $this->logger->event('KiteBrokerGateway', 'broker.gtt_rejected', 'warning', 'Kite GTT rejected', [
                'user_id' => $request->userId,
                'http_status' => $response->status(),
                'protection_type' => $request->protectionType,
            ]);

            throw new DomainException($message, 'BROKER_REJECTED', 422);
        }

        $triggerId = (string) data_get($json, 'data.trigger_id', $existingId ?? '');
        if ($triggerId === '') {
            throw new BrokerAmbiguousException('Kite accepted GTT without a trigger id.');
        }

        $this->logger->event('KiteBrokerGateway', 'broker.gtt_submitted', 'info', 'Kite GTT submitted', [
            'user_id' => $request->userId,
            'broker_gtt_id' => $triggerId,
            'protection_type' => $request->protectionType,
        ]);

        return new BrokerSubmission($triggerId, 'submitted');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function mapGttSnapshot(string $brokerGttId, array $row): BrokerGttSnapshot
    {
        $raw = strtolower((string) ($row['status'] ?? ''));
        $condition = is_array($row['condition'] ?? null) ? $row['condition'] : [];
        $orders = is_array($row['orders'] ?? null) ? $row['orders'] : [];
        $firstOrder = is_array($orders[0] ?? null) ? $orders[0] : [];
        $qty = (float) ($firstOrder['quantity'] ?? $row['quantity'] ?? 0);
        $triggerValues = $condition['trigger_values'] ?? [];
        $trigger = (float) (is_array($triggerValues) ? ($triggerValues[0] ?? 0) : 0);
        $filled = (float) ($firstOrder['filled_quantity'] ?? $row['filled_quantity'] ?? 0);
        $avg = isset($firstOrder['average_price']) ? (float) $firstOrder['average_price'] : null;
        $childId = isset($firstOrder['order_id']) ? (string) $firstOrder['order_id'] : null;

        $status = match ($raw) {
            'active' => 'active',
            'triggered' => $filled > 0.0001 && $filled + 0.0001 < $qty ? 'triggered' : 'triggered',
            'cancelled', 'canceled', 'disabled', 'expired' => 'cancelled',
            'rejected' => 'rejected',
            default => 'unknown',
        };

        return new BrokerGttSnapshot(
            $brokerGttId,
            $status,
            $qty,
            $trigger,
            $filled,
            $avg !== null && $avg > 0 ? $avg : null,
            $childId,
            strtoupper($raw),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function headers(string $accessToken): array
    {
        $apiKey = (string) config('broker.kite.api_key');

        return [
            'X-Kite-Version' => '3',
            'Authorization' => 'token '.$apiKey.':'.$accessToken,
        ];
    }

    protected function accessToken(int $userId): string
    {
        $connection = BrokerConnection::query()
            ->where('user_id', $userId)
            ->where('provider', BrokerConnection::PROVIDER_KITE)
            ->first();

        if (! $connection?->isUsable()) {
            throw new DomainException(
                'Zerodha session is missing or expired. Connect Kite again.',
                'BROKER_SESSION_EXPIRED',
                403,
            );
        }

        return (string) $connection->access_token;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function mapSnapshot(string $brokerOrderId, array $row): BrokerOrderSnapshot
    {
        $raw = strtoupper((string) ($row['status'] ?? ''));
        $filled = (float) ($row['filled_quantity'] ?? $row['filledQuantity'] ?? 0);
        $qty = (float) ($row['quantity'] ?? 0);
        $pending = (float) ($row['pending_quantity'] ?? max(0, $qty - $filled));
        $avg = isset($row['average_price']) ? (float) $row['average_price'] : null;

        $status = match ($raw) {
            'COMPLETE' => $filled + 0.0001 < $qty && $qty > 0 ? 'partial' : 'filled',
            'REJECTED' => 'rejected',
            'CANCELLED', 'CANCELED' => $filled > 0.0001 ? 'partial' : 'cancelled',
            'OPEN', 'TRIGGER PENDING', 'PUT ORDER REQ RECEIVED', 'VALIDATION PENDING', 'OPEN PENDING' => $filled > 0.0001 ? 'partial' : 'open',
            default => $filled > 0.0001 ? 'partial' : 'unknown',
        };

        if ($status === 'open' && $filled > 0.0001 && $pending > 0.0001) {
            $status = 'partial';
        }

        return new BrokerOrderSnapshot($brokerOrderId, $status, $filled, $pending, $avg > 0 ? $avg : null, $raw);
    }
}
