<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class MlAcceptancePrivatePermissionRepairScriptTest extends TestCase
{
    public function test_repair_cli_is_dry_run_by_default_and_requires_operator_identity_for_apply(): void
    {
        $script = base_path('scripts/repair-ml-acceptance-private-permissions.php');
        $this->assertFileExists($script);

        $help = new Process([PHP_BINARY, $script, '--help'], base_path());
        $help->run();
        $this->assertSame(0, $help->getExitCode(), $help->getErrorOutput());
        $this->assertStringContainsString('[--actor OPERATOR] [--apply]', $help->getOutput());

        $unguardedApply = new Process([PHP_BINARY, $script, '--apply'], base_path());
        $unguardedApply->run();
        $this->assertSame(2, $unguardedApply->getExitCode());
        $this->assertStringContainsString('--apply requires --actor', $unguardedApply->getErrorOutput());
    }
}
