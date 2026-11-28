<?php

namespace App\Services;

use App\Models\CorporateActionSourceReceipt;

/** Stores each captured response as an immutable application-level receipt. */
class CorporateActionSourceReceiptStore
{
    public function capture(string $kind, ?string $url, ?int $status, ?string $contentType, string $body, ?int $issueId = null, array $metadata = []): CorporateActionSourceReceipt
    {
        return CorporateActionSourceReceipt::query()->create([
            'issue_id' => $issueId,
            'source_kind' => $kind,
            'source_url' => $url,
            'http_status' => $status,
            'content_type' => $contentType,
            'sha256' => hash('sha256', $body),
            'body_bytes' => strlen($body),
            'body_base64' => base64_encode($body),
            'metadata' => $metadata,
            'captured_at' => now(),
        ]);
    }
}
