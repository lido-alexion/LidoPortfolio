<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MlAcceptancePrivatePermissionRepairScriptTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = sys_get_temp_dir().'/ml-permission-plan-'.Str::uuid();
        mkdir($this->fixtureRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixtureRoot) && is_dir($this->fixtureRoot)) {
            $this->removeFixtureTree($this->fixtureRoot);
        }
        parent::tearDown();
    }

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

    public function test_source_planning_executes_for_database_object_rows_and_preserves_expected_modes_and_hashes(): void
    {
        $id = '12345678-1234-4234-8234-123456789abc';
        $writableDir = $this->fixtureRoot.'/'.$id;
        mkdir($writableDir, 0700);
        file_put_contents($writableDir.'/payload', 'archive bytes');
        $writable = $this->runPlanner('$row = (object) '.var_export([
            'id' => $id, 'status' => 'uploading', 'manifest' => json_encode(['filename' => 'cm01Oct2026bhav.csv.zip']), 'evidence' => null,
        ], true).'; echo json_encode(walkSource('.var_export($writableDir, true).', $row, '.var_export($this->fixtureRoot, true).', 33, 33));');

        $this->assertSame(0, $writable->getExitCode(), $writable->getErrorOutput());
        $writablePlan = json_decode($writable->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(2, $writablePlan);
        $this->assertSame(0700, $writablePlan[0]['after_mode']);
        $this->assertSame(0600, $writablePlan[1]['after_mode']);
        $this->assertSame(hash('sha256', 'archive bytes'), $writablePlan[1]['sha256']);

        $sealedDir = $this->fixtureRoot.'/22345678-1234-4234-8234-123456789abc';
        mkdir($sealedDir, 0700);
        file_put_contents($sealedDir.'/payload', 'sealed archive bytes');
        file_put_contents($sealedDir.'/cm01Oct2026bhav.csv', "SYMBOL,CLOSE\nABC,10\n");
        $sealed = $this->runPlanner('$row = (object) '.var_export([
            'id' => basename($sealedDir), 'status' => 'sealed', 'manifest' => json_encode(['filename' => 'cm01Oct2026bhav.csv.zip']),
            'evidence' => json_encode(['filename' => 'cm01Oct2026bhav.csv', 'content_sha256' => hash('sha256', "SYMBOL,CLOSE\nABC,10\n")]),
        ], true).'; echo json_encode(walkSource('.var_export($sealedDir, true).', $row, '.var_export($this->fixtureRoot, true).', 33, 33));');

        $this->assertSame(0, $sealed->getExitCode(), $sealed->getErrorOutput());
        $sealedPlan = json_decode($sealed->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(3, $sealedPlan);
        $sealedFiles = [];
        foreach (array_slice($sealedPlan, 1) as $object) $sealedFiles[basename($object['path'])] = $object;
        $this->assertSame(0400, $sealedFiles['payload']['after_mode']);
        $this->assertSame(0400, $sealedFiles['cm01Oct2026bhav.csv']['after_mode']);
        $this->assertSame(hash('sha256', 'sealed archive bytes'), $sealedFiles['payload']['sha256']);
        $this->assertSame(hash('sha256', "SYMBOL,CLOSE\nABC,10\n"), $sealedFiles['cm01Oct2026bhav.csv']['sha256']);
    }

    public function test_source_planning_fails_closed_for_symlinks_and_unregistered_entries(): void
    {
        $id = '32345678-1234-4234-8234-123456789abc';
        $dir = $this->fixtureRoot.'/'.$id;
        mkdir($dir, 0700);
        file_put_contents($this->fixtureRoot.'/outside', 'outside');
        symlink($this->fixtureRoot.'/outside', $dir.'/payload');
        $symlink = $this->runPlanner('$row = (object) '.var_export([
            'id' => $id, 'status' => 'uploading', 'manifest' => json_encode(['filename' => 'upload.zip']), 'evidence' => null,
        ], true).'; walkSource('.var_export($dir, true).', $row, '.var_export($this->fixtureRoot, true).', 33, 33);');
        $this->assertSame(2, $symlink->getExitCode());
        $this->assertStringContainsString('symlink encountered', $symlink->getErrorOutput());

        mkdir($this->fixtureRoot.'/sources', 0700);
        mkdir($this->fixtureRoot.'/sources/unregistered', 0700);
        $unregistered = $this->runPlanner('assertRegisteredEntries('.var_export($this->fixtureRoot.'/sources', true).', [], "source");');
        $this->assertSame(2, $unregistered->getExitCode());
        $this->assertStringContainsString('unregistered source directory', $unregistered->getErrorOutput());
    }

    public function test_terminal_campaign_plans_exact_interrupted_dataset_builder_layout_without_touching_bytes(): void
    {
        $campaign = $this->fixtureRoot.'/campaigns/42345678-1234-4234-8234-123456789abc';
        $payloads = [
            'train.jsonl' => "{\"row\":\"train fixture\"}\n",
            'validation.jsonl' => "{\"row\":\"validation fixture\"}\n",
            'test.jsonl' => "{\"row\":\"test fixture\"}\n",
        ];
        $before = [];
        foreach (['1m', '3m', '6m'] as $horizonName) {
            $horizon = $campaign.'/'.$horizonName;
            $dataset = $horizon.'/dataset';
            mkdir($dataset, 0700, true);
            foreach ($payloads as $name => $bytes) {
                file_put_contents($dataset.'/'.$name, $bytes);
                $before[$horizonName.'/'.$name] = hash('sha256', $bytes);
            }
            file_put_contents($horizon.'/pit-evidence.jsonl.gz', 'journal fixture bytes');
        }

        // This mirrors the producer's paths returned by buildStreamed and its finally-cleaned dataset child.
        $first = $this->runPlanner('echo json_encode(walkCampaign('.var_export($campaign, true).', '.var_export($this->fixtureRoot, true).', 33, 33, "failed"));');
        $second = $this->runPlanner('echo json_encode(walkCampaign('.var_export($campaign, true).', '.var_export($this->fixtureRoot, true).', 33, 33, "failed"));');

        $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
        $plan = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($plan, json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertCount(19, $plan); // campaign, three horizons, three datasets, nine partitions and three journals
        $datasetDirectories = array_values(array_filter($plan, fn ($item) => $item['kind'] === 'retained-temporary-dataset-directory'));
        $this->assertCount(3, $datasetDirectories);
        foreach ($datasetDirectories as $directory) $this->assertSame(0700, $directory['after_mode']);
        $partitionObjects = array_values(array_filter($plan, fn ($item) => $item['kind'] === 'retained-temporary-dataset-partition'));
        $this->assertCount(9, $partitionObjects);
        foreach ($partitionObjects as $object) {
            $this->assertSame(0400, $object['after_mode']);
            $this->assertSame(hash_file('sha256', $object['path']), $object['sha256']);
        }
        $after = [];
        foreach (['1m', '3m', '6m'] as $horizonName) {
            foreach (array_keys($payloads) as $name) $after[$horizonName.'/'.$name] = hash_file('sha256', $campaign.'/'.$horizonName.'/dataset/'.$name);
        }
        $this->assertSame($before, $after);
    }

    public function test_campaign_planning_rejects_unexpected_nested_objects_symlinks_and_nonterminal_states(): void
    {
        $campaign = $this->fixtureRoot.'/campaigns/52345678-1234-4234-8234-123456789abc';
        mkdir($campaign.'/1m/dataset', 0700, true);
        file_put_contents($campaign.'/1m/dataset/train.jsonl', "{}\n");
        mkdir($campaign.'/1m/dataset/nested');
        $nested = $this->runPlanner('walkCampaign('.var_export($campaign, true).', '.var_export($this->fixtureRoot, true).', 33, 33, "cancelled");');
        $this->assertSame(2, $nested->getExitCode());
        $this->assertStringContainsString('unrecognized retained dataset object', $nested->getErrorOutput());

        rmdir($campaign.'/1m/dataset/nested');
        file_put_contents($this->fixtureRoot.'/outside-partition', 'outside bytes');
        symlink($this->fixtureRoot.'/outside-partition', $campaign.'/1m/dataset/validation.jsonl');
        $symlink = $this->runPlanner('walkCampaign('.var_export($campaign, true).', '.var_export($this->fixtureRoot, true).', 33, 33, "cancelled");');
        $this->assertSame(2, $symlink->getExitCode());
        $this->assertStringContainsString('symlink encountered', $symlink->getErrorOutput());

        $active = $this->runPlanner('walkCampaign('.var_export($campaign, true).', '.var_export($this->fixtureRoot, true).', 33, 33, "training");');
        $this->assertSame(2, $active->getExitCode());
        $this->assertStringContainsString('not in a recognized terminal state', $active->getErrorOutput());
    }

    private function runPlanner(string $body): Process
    {
        $script = base_path('scripts/repair-ml-acceptance-private-permissions.php');
        $code = 'define("REPAIR_LIBRARY_ONLY", true); require '.var_export($script, true).'; '.$body;
        $process = new Process([PHP_BINARY, '-r', $code], base_path());
        $process->run();
        return $process;
    }

    private function removeFixtureTree(string $path): void
    {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path.'/'.$entry;
            if (is_link($child) || !is_dir($child)) unlink($child);
            else $this->removeFixtureTree($child);
        }
        rmdir($path);
    }
}
