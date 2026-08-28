<?php

namespace App\Console\Commands;

use App\Services\AdminOperationalAlertService;
use Illuminate\Console\Command;

class CheckOperationalAlertsCommand extends Command
{
    protected $signature = 'portfolio:check-operational-alerts';

    protected $description = 'Evaluate sync and unattended-ops health and notify admins via Telegram when issues are detected';

    public function handle(AdminOperationalAlertService $alerts): int
    {
        $result = $alerts->syncAndNotify();
        $active = $result['active'];

        if ($active === []) {
            $this->info('No active operational alerts.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('Active operational alerts: %d', count($active)));
        foreach ($active as $alert) {
            $this->line(sprintf(' - [%s] %s', strtoupper($alert['severity']), $alert['title']));
        }

        if ($result['notified'] !== []) {
            $this->info('Telegram sent for: '.implode(', ', $result['notified']));
        }

        return self::SUCCESS;
    }
}
