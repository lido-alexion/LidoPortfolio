<?php

namespace App\Jobs;

use App\Models\ExportNotificationOutbox;
use App\Services\Notification\NotificationPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class DeliverExportNotificationOutbox implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [30, 120, 300, 900];

    public function __construct(public int $outboxId) {}

    public function handle(NotificationPublisher $notifications): void
    {
        try {
            DB::transaction(function () use ($notifications): void {
                $event = ExportNotificationOutbox::query()->whereKey($this->outboxId)->lockForUpdate()->first();
                if (! $event || $event->delivered_at) return;

                $artifact = $event->artifact()->with('user')->first();
                if (! $artifact || ! $artifact->user) return;

                $completed = $event->event_type === 'completed';
                if (($completed && $artifact->status !== 'ready') || (! $completed && $artifact->status !== 'failed')) {
                    return;
                }

                $downloadAvailable = $completed
                    && $artifact->expires_at?->isFuture()
                    && $artifact->path === 'exports/'.$artifact->user_id.'/'.$artifact->token.'.'.$artifact->format;
                $notifications->publishEvent([$artifact->user], [
                    'notification_type' => $completed ? 'export.completed' : 'export.failed',
                    'audience' => 'investor',
                    'severity' => $completed ? 'info' : 'action_required',
                    'title' => $completed ? 'Export ready' : 'Export failed',
                    'message' => $completed
                        ? ($downloadAvailable ? 'Your '.$artifact->dataset.' export is ready to download.' : 'Your '.$artifact->dataset.' export is no longer available. Run it again to download a fresh copy.')
                        : 'The '.$artifact->dataset.' export could not be generated. Narrow the scope and try again.',
                    'primary_action' => $downloadAvailable
                        ? ['url' => route('api.exports.download', $artifact->token), 'label' => 'Download export']
                        : null,
                    'external_info_delivery' => $completed,
                ]);

                $event->update([
                    'attempts' => $event->attempts + 1,
                    'delivered_at' => now(),
                    'next_attempt_at' => null,
                    'last_error_code' => null,
                ]);
            });
        } catch (Throwable $error) {
            $event = ExportNotificationOutbox::query()->whereKey($this->outboxId)->whereNull('delivered_at')->first();
            if ($event) {
                $attempts = $event->attempts + 1;
                $event->update([
                    'attempts' => $attempts,
                    'next_attempt_at' => now()->addMinutes(min(60, 2 ** min($attempts, 6))),
                    'last_error_code' => substr(class_basename($error), 0, 64),
                ]);
            }

            throw $error;
        }
    }
}
