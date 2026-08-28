<?php

namespace App\Console\Commands;

use App\Engines\Execution\LiveBrokerExecutionService;
use App\Models\PortfolioProfile;
use App\Models\PositionProtection;
use App\Models\TradingOrder;
use Illuminate\Console\Command;

class ReconcileBrokerOrdersCommand extends Command
{
    protected $signature = 'tos:reconcile-broker-orders {--profile= : Portfolio profile ID}';

    protected $description = 'Reconcile in-flight Zerodha/Kite broker orders and GTT protection into local fills';

    public function handle(LiveBrokerExecutionService $live): int
    {
        $query = PortfolioProfile::query();
        if ($id = $this->option('profile')) {
            $query->where('id', (int) $id);
        } else {
            $orderIds = TradingOrder::query()
                ->whereNotNull('broker_order_id')
                ->whereIn('broker_status', TradingOrder::IN_FLIGHT_BROKER_STATUSES)
                ->distinct()
                ->pluck('profile_id');
            $protectionIds = PositionProtection::query()
                ->whereIn('state', PositionProtection::OPEN_STATES)
                ->distinct()
                ->pluck('profile_id');
            $query->whereIn('id', $orderIds->merge($protectionIds)->unique()->filter()->values());
        }

        $count = 0;
        $protections = 0;
        $protectionService = app(\App\Services\Protection\PositionProtectionService::class);
        foreach ($query->get() as $profile) {
            $count += $live->reconcileOpenForProfile($profile);
            $protections += $protectionService->reconcileOpenForProfile($profile);
        }

        $this->info("Reconciled {$count} broker order(s) and {$protections} protection(s).");

        return self::SUCCESS;
    }
}
