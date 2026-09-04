<?php

namespace App\Console\Commands;

use App\Services\Execution\InternalTransferValuationFinalizer;
use Illuminate\Console\Command;

class FinalizeInternalTransferValuationsCommand extends Command
{
    protected $signature = 'tos:finalize-internal-transfer-valuations';

    protected $description = 'Finalize provisional FEAT-039 internal transfer prices';

    public function handle(InternalTransferValuationFinalizer $finalizer): int
    {
        $count = $finalizer->finalizePending();
        $this->info("Internal transfer valuations finalized: {$count}.");

        return self::SUCCESS;
    }
}
