<?php

namespace App\Console\Commands;

use App\Services\AdminInvestmentOwnershipAuditService;
use Illuminate\Console\Command;

class AuditAdminInvestmentOwnershipCommand extends Command
{
    protected $signature = 'portfolio:audit-admin-investment-ownership {--json : Emit machine-readable JSON}';

    protected $description = 'Detect Admin-owned Investor-domain data before enforcing V5 FEAT-042';

    public function handle(AdminInvestmentOwnershipAuditService $audit): int
    {
        $result = $audit->audit();

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ($result['safe_to_enforce']) {
            $this->info($result['action']);
        } else {
            $this->error(sprintf(
                '%d Admin account(s) own Investor-domain data.',
                $result['conflicting_admin_accounts'],
            ));
            foreach ($result['conflicts'] as $conflict) {
                $this->line(sprintf(
                    'Admin #%d %s: %d portfolio(s); profile records %s; direct records %s',
                    $conflict['admin_user_id'],
                    $conflict['admin_email'],
                    count($conflict['profiles']),
                    json_encode($conflict['profile_record_counts'], JSON_UNESCAPED_SLASHES),
                    json_encode($conflict['direct_record_counts'], JSON_UNESCAPED_SLASHES),
                ));
            }
            $this->warn($result['action']);
        }

        return $result['safe_to_enforce'] ? self::SUCCESS : self::FAILURE;
    }
}
