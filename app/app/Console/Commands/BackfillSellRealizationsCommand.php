<?php

namespace App\Console\Commands;

use App\Services\TransactionRealizationService;
use Illuminate\Console\Command;

class BackfillSellRealizationsCommand extends Command
{
    protected $signature = 'portfolio:backfill-sell-realizations {--profile= : Limit to a portfolio profile id}';

    protected $description = 'Backfill FIFO realized P/L and squared-off fees on sell transactions';

    public function handle(TransactionRealizationService $realizations): int
    {
        $profileId = $this->option('profile');
        $profileFilter = $profileId !== null && $profileId !== '' ? (int) $profileId : null;

        $processed = $realizations->backfillAll($profileFilter);
        $this->info(sprintf('Backfilled sell realizations for %d profile/stock ledger(s).', $processed));

        return self::SUCCESS;
    }
}
