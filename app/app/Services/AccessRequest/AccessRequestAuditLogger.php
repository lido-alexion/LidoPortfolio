<?php

namespace App\Services\AccessRequest;

use App\Models\AccessRequestAuditEvent;
use App\Models\User;

class AccessRequestAuditLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $eventType,
        ?string $emailNormalized = null,
        ?int $accessRequestId = null,
        ?User $actor = null,
        array $context = [],
    ): void {
        AccessRequestAuditEvent::query()->create([
            'event_type' => $eventType,
            'email_normalized' => $emailNormalized,
            'access_request_id' => $accessRequestId,
            'actor_user_id' => $actor?->id,
            'context' => $context === [] ? null : $context,
            'created_at' => now(),
        ]);
    }
}
