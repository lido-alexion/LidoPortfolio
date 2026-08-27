<?php

namespace App\Console\Commands;

use App\Engines\Execution\LiveBrokerExecutionService;
use App\Models\PortfolioProfile;
use App\Models\TradingOrder;
use Illuminate\Console\Command;

class ReconcileBrokerOrdersCommand extends Command
{
    protected $signature = 'tos:reconcile-broker-orders {--profile= : Portfolio profile ID}';

    protected $description = 'Reconcile in-flight Zerodha/Kite broker orders into local fills';

    public function handle(LiveBrokerExecutionService $live): int
    {
        $query = PortfolioProfile::query();
        if ($id = $this->option('profile')) {
            $query->where('id', (int) $id);
        } else {
            $profileIds = TradingOrder::query()
                ->whereNotNull('broker_order_id')
                ->whereIn('broker_status', TradingOrder::IN_FLIGHT_BROKER_STATUSES)
                ->distinct()
                ->pluck('profile_id');
            $query->whereIn('id', $profileIds);
        }

        $count = 0;
        foreach ($query->get() as $profile) {
            $count += $live->reconcileOpenForProfile($profile);
        }

        $this->info("Reconciled {$count} broker order(s).");

        return self::SUCCESS;
    }
}
