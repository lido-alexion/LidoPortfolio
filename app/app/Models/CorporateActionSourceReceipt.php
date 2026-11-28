<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only captured source bytes; writers create receipts and never update them. */
class CorporateActionSourceReceipt extends Model
{
    protected $table = 'portfolio_corporate_action_source_receipts';

    protected $fillable = [
        'issue_id', 'source_kind', 'source_url', 'http_status', 'content_type',
        'sha256', 'body_bytes', 'body_base64', 'metadata', 'captured_at',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'captured_at' => 'datetime', 'issue_id' => 'integer', 'body_bytes' => 'integer', 'http_status' => 'integer'];
    }

    public function rawBody(): string
    {
        $body = base64_decode($this->body_base64, true);
        if (! is_string($body) || strlen($body) !== $this->body_bytes || ! hash_equals($this->sha256, hash('sha256', $body))) {
            throw new \RuntimeException('Corporate-action source receipt failed its byte-integrity check.');
        }
        return $body;
    }
}
