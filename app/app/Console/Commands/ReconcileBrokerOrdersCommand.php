<?php

namespace App\Console\Commands;

use App\Engines\Execution\LiveBrokerExecutionService;
use App\Models\PortfolioProfile;
use App\Models\PositionProtection;
use App\Models\TradingOrder;
use App\Services\AdminOperationalAlertService;
use Illuminate\Console\Command;
use Throwable;

class ReconcileBrokerOrdersCommand extends Command
{
    protected $signature = 'tos:reconcile-broker-orders {--profile= : Portfolio profile ID}';

    protected $description = 'Reconcile in-flight Zerodha/Kite broker orders and GTT protection into local fills';

    public function handle(LiveBrokerExecutionService $live, AdminOperationalAlertService $opsAlerts): int
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
        $errors = [];
        $protectionService = app(\App\Services\Protection\PositionProtectionService::class);
        foreach ($query->get() as $profile) {
            try {
                $count += $live->reconcileOpenForProfile($profile);
                $protections += $protectionService->reconcileOpenForProfile($profile);
            } catch (Throwable $e) {
                $errors[] = sprintf('profile #%d: %s', $profile->id, $e->getMessage());
                $this->error(sprintf('Broker reconcile failed for profile #%d: %s', $profile->id, $e->getMessage()));
            }
        }

        $this->info("Reconciled {$count} broker order(s) and {$protections} protection(s).");

        if ($errors !== []) {
            $opsAlerts->recordUnattendedFailure(
                AdminOperationalAlertService::KEY_BROKER_RECONCILE_FAILED,
                'Broker reconciliation failed',
                'Unattended broker reconciliation failed. '.implode('; ', $errors),
                ['failed_profiles' => count($errors)],
            );
            $opsAlerts->syncAndNotify();

            return self::FAILURE;
        }

        if ($opsAlerts->clearUnattendedFailure(AdminOperationalAlertService::KEY_BROKER_RECONCILE_FAILED)) {
            $opsAlerts->syncAndNotify();
        }

        return self::SUCCESS;
    }
}
