<?php

namespace App\Console\Commands;

use App\Services\AccessRequest\AccessRequestVerificationService;
use Illuminate\Console\Command;

class PurgeAccessRequestVerificationsCommand extends Command
{
    protected $signature = 'portfolio:purge-access-request-verifications';

    protected $description = 'Remove expired or stale account-access-request verification tokens (V8 FEAT-055)';

    public function handle(AccessRequestVerificationService $verifications): int
    {
        $deleted = $verifications->purgeExpired();
        $this->info("Purged {$deleted} verification record(s).");

        return self::SUCCESS;
    }
}
