<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketDepthSnapshot extends Model
{
    protected $table = 'portfolio_market_depth_snapshots';

    protected $fillable = [
        'as_of_date',
        'exchange_scope',
        'payload_json',
    ];

    protected function casts(): array
    {
        return [
            'as_of_date' => 'date',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decodedPayload(): ?array
    {
        $decoded = json_decode((string) $this->payload_json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
