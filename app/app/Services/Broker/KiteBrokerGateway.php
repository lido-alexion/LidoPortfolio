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
            $this->logger->event('KiteBrokerGateway', 'broker.place_rejected', 'warning', 'Kite place rejected', [
                'user_id' => $request->userId,
                'recommendation_id' => $request->recommendationId,
                'http_status' => $response->status(),
            ]);

            throw new DomainException($message, 'BROKER_REJECTED', 422);
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
