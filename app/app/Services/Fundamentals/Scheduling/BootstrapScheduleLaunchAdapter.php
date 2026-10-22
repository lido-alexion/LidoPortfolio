<?php

namespace App\Services\Fundamentals\Scheduling;

use App\Services\Fundamentals\BootstrapLaunchResult;

/** Canonical delivery boundary. Implementations must persist the complete immutable receipt atomically with run creation. */
interface BootstrapScheduleLaunchAdapter
{
    /** @param array<string,mixed> $configuration @param array<string,mixed> $receipt */
    /** Return only after BootstrapLaunchResult contains the same request key and byte-equivalent schedule_receipt. */
    public function launch(array $configuration, int $actorAdminId, string $actorName, string $idempotencyKey, array $receipt): BootstrapLaunchResult;
}
