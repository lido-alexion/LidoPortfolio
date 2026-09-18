<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_RESERVATION_EVENTS = 'portfolio_recommendation_reservation_events';
    private const STOX_RESERVATION_EVENTS = 'stox_recommendation_reservation_events';
    private const LEGACY_BRIDGE_RETURNS = 'portfolio_tos_recall_bridge_loan_returns';
    private const STOX_BRIDGE_RETURNS = 'stox_tos_recall_bridge_loan_returns';

    public function up(): void
    {
        $this->renameIfNeeded(self::LEGACY_RESERVATION_EVENTS, self::STOX_RESERVATION_EVENTS);
        $this->renameIfNeeded(self::LEGACY_BRIDGE_RETURNS, self::STOX_BRIDGE_RETURNS);
    }

    public function down(): void
    {
        $this->renameIfNeeded(self::STOX_RESERVATION_EVENTS, self::LEGACY_RESERVATION_EVENTS);
        $this->renameIfNeeded(self::STOX_BRIDGE_RETURNS, self::LEGACY_BRIDGE_RETURNS);
    }

    private function renameIfNeeded(string $from, string $to): void
    {
        if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
            Schema::rename($from, $to);
        }
    }
};
