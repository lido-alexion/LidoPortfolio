<?php

namespace App\Services\Notification;

use App\Mail\NotificationDeliveryMail;
use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotificationDeliveryAdapter
{
    /** @return array{successful: bool, retryable: bool, response_status: ?int, error_code: ?string} */
    public function send(NotificationDelivery $delivery): array
    {
        try {
            return match ($delivery->channel) {
                'telegram' => $this->telegram($delivery),
                'email' => $this->email($delivery),
                'webhook' => $this->webhook($delivery),
                default => $this->failure(false, null, 'UNSUPPORTED_CHANNEL'),
            };
        } catch (Throwable) {
            return $this->failure(true, null, 'NETWORK_OR_ADAPTER_ERROR');
        }
    }

    private function telegram(NotificationDelivery $delivery): array
    {
        $config = $delivery->channelSetting?->configuration ?? [];
        $token = (string) ($config['bot_token'] ?? '');
        if ($token === '' || $delivery->destination === '') {
            return $this->failure(false, null, 'CONFIGURATION_MISSING');
        }
        $source = $delivery->recipientNotification->source;
        $response = Http::timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $delivery->destination,
            'text' => $source->title."\n\n".$source->message,
        ]);

        return $response->successful()
            ? $this->success($response->status())
            : $this->failure($this->retryableStatus($response->status()), $response->status(), 'TELEGRAM_DELIVERY_FAILED');
    }

    private function email(NotificationDelivery $delivery): array
    {
        if ($delivery->delivery_kind === 'digest') {
            $items = $delivery->digestMemberships()->with('recipientNotification.source')
                ->get()->map(fn ($membership) => [
                    'title' => $membership->recipientNotification->source->title,
                    'message' => $membership->recipientNotification->source->message,
                ])->all();
            if ($items === []) return $this->failure(false, null, 'DIGEST_EMPTY');
            Mail::to($delivery->destination)->send(new NotificationDeliveryMail('Your StoX daily digest', '', null, $items));

            return $this->success(null);
        }
        $source = $delivery->recipientNotification->source;
        Mail::to($delivery->destination)->send(new NotificationDeliveryMail(
            $source->title,
            $source->message,
            $this->navigationAction($source->primary_action),
        ));

        return $this->success(null);
    }

    private function navigationAction(?array $action): ?array
    {
        if (! $action || ! isset($action['url']) || ! is_string($action['url'])) return null;
        $path = parse_url($action['url'], PHP_URL_PATH) ?: '';
        $host = parse_url($action['url'], PHP_URL_HOST);
        $expectedHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')
            || ($host !== null && strtolower($host) !== strtolower((string) $expectedHost))
            || preg_match('#/(approve|execute|place|cancel|reject|accept)(/|$)#i', $path)) return null;

        return ['label' => 'Open in StoX', 'url' => $action['url']];
    }

    private function webhook(NotificationDelivery $delivery): array
    {
        $config = $delivery->channelSetting?->configuration ?? [];
        $secret = (string) ($config['signing_secret'] ?? '');
        if ($secret === '' || $delivery->destination === '') {
            return $this->failure(false, null, 'CONFIGURATION_MISSING');
        }
        $source = $delivery->recipientNotification->source;
        $payload = [
            'schema_version' => '1.0',
            'notification_id' => $delivery->recipient_notification_id,
            'type' => $source->notification_type,
            'severity' => $source->severity,
            'condition_state' => $delivery->recipientNotification->condition_state,
            'title' => $source->title,
            'message' => $source->message,
            'context' => $source->context,
            'primary_action' => $source->primary_action,
            'first_detected_at' => $source->first_detected_at?->toIso8601String(),
            'latest_detected_at' => $source->latest_detected_at?->toIso8601String(),
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $response = Http::timeout(15)->withHeaders([
            'Content-Type' => 'application/json',
            'X-StoX-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret),
            'X-StoX-Schema-Version' => '1.0',
        ])->withBody($body, 'application/json')->post($delivery->destination);

        return $response->successful()
            ? $this->success($response->status())
            : $this->failure($this->retryableStatus($response->status()), $response->status(), 'WEBHOOK_DELIVERY_FAILED');
    }

    private function retryableStatus(int $status): bool
    {
        return $status === 408 || $status === 429 || $status >= 500;
    }

    private function success(?int $status): array
    {
        return ['successful' => true, 'retryable' => false, 'response_status' => $status, 'error_code' => null];
    }

    private function failure(bool $retryable, ?int $status, string $code): array
    {
        return ['successful' => false, 'retryable' => $retryable, 'response_status' => $status, 'error_code' => $code];
    }
}
