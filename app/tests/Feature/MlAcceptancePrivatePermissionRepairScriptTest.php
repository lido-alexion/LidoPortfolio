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
        $campaignId = '42345678-1234-4234-8234-123456789abc';
        $campaignRoot = $this->fixtureRoot.'/campaigns';
        $sourcesRoot = $this->fixtureRoot.'/sources';
        mkdir($campaignRoot, 0700, true);
        mkdir($sourcesRoot, 0700, true);
        $campaign = $campaignRoot.'/'.$campaignId;
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
            file_put_contents($horizon.'/pit-evidence.jsonl.gz', gzencode("journal fixture bytes\n"));
        }

        // This mirrors the producer's paths returned by buildStreamed and its finally-cleaned dataset child.
        $planner = '$campaign = (object) '.var_export(['id' => $campaignId, 'status' => 'failed'], true).'; $plan = planRegisteredObjects([], [$campaign], '.var_export($this->fixtureRoot, true).', '.var_export($sourcesRoot, true).', '.var_export($campaignRoot, true).', 33, 33); echo json_encode(["objects" => $plan, "fingerprint" => planFingerprint($plan)]);';
        $first = $this->runPlanner($planner);
        $second = $this->runPlanner($planner);

        $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
        $firstPlan = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $secondPlan = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($firstPlan, $secondPlan);
        $this->assertSame($firstPlan['fingerprint'], $secondPlan['fingerprint']);
        $plan = $firstPlan['objects'];
        $this->assertCount(19, $plan); // campaign, three horizons, three datasets, nine partitions and three journals
        $datasetDirectories = array_values(array_filter($plan, fn ($item) => $item['kind'] === 'retained-temporary-dataset-directory'));
        $this->assertCount(3, $datasetDirectories);
        foreach ($datasetDirectories as $directory) {
            $this->assertSame(0700, $directory['after_mode']);
            $this->assertSame(33, $directory['after_uid']);
            $this->assertSame(33, $directory['after_gid']);
        }
        $partitionObjects = array_values(array_filter($plan, fn ($item) => $item['kind'] === 'retained-temporary-dataset-partition'));
        $this->assertCount(9, $partitionObjects);
        foreach ($partitionObjects as $object) {
            $this->assertSame(0400, $object['after_mode']);
            $this->assertSame(33, $object['after_uid']);
            $this->assertSame(33, $object['after_gid']);
            $this->assertSame(hash_file('sha256', $object['path']), $object['sha256']);
        }
        $gzipJournals = array_values(array_filter($plan, fn ($item) => $item['kind'] === 'sealed-evidence-journal'));
        $this->assertCount(3, $gzipJournals);
        foreach ($gzipJournals as $journal) {
            $this->assertSame(hash_file('sha256', $journal['path']), $journal['sha256']);
            $this->assertSame("journal fixture bytes\n", gzdecode(file_get_contents($journal['path'])));
        }
        $after = [];
        foreach (['1m', '3m', '6m'] as $horizonName) {
            foreach (array_keys($payloads) as $name) $after[$horizonName.'/'.$name] = hash_file('sha256', $campaign.'/'.$horizonName.'/dataset/'.$name);
        }
        $this->assertSame($before, $after);
    }

    public function test_terminal_campaign_preserves_historical_plain_jsonl_journals_as_immutable_files(): void
    {
        $campaignId = '62345678-1234-4234-8234-123456789abc';
        $campaignRoot = $this->fixtureRoot.'/campaigns';
        $sourcesRoot = $this->fixtureRoot.'/sources';
        mkdir($campaignRoot, 0700, true);
        mkdir($sourcesRoot, 0700, true);
        $campaignPath = $campaignRoot.'/'.$campaignId;
        $legacyBytes = json_encode(['stock_id' => 71, 'partition' => 'train', 'fact_inventory' => [['id' => 9, 'fact_key' => 'revenue']]], JSON_THROW_ON_ERROR)."\n"
            .json_encode(['stock_id' => 71, 'reference_date' => '2026-01-05', 'partition' => 'train', 'fundamentals' => []], JSON_THROW_ON_ERROR)."\n";
        foreach (['1m', '3m', '6m'] as $horizon) {
            mkdir($campaignPath.'/'.$horizon, 0700, true);
            file_put_contents($campaignPath.'/'.$horizon.'/pit-evidence.jsonl', $legacyBytes);
        }

        $planner = '$campaign = (object) '.var_export(['id' => $campaignId, 'status' => 'blocked'], true).'; $plan = planRegisteredObjects([], [$campaign], '.var_export($this->fixtureRoot, true).', '.var_export($sourcesRoot, true).', '.var_export($campaignRoot, true).', 33, 33); echo json_encode($plan);';
        $planned = $this->runPlanner($planner);
        $this->assertSame(0, $planned->getExitCode(), $planned->getErrorOutput());
        $objects = json_decode($planned->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $journals = array_values(array_filter($objects, fn ($item) => $item['kind'] === 'legacy-plain-evidence-journal'));
        $this->assertCount(3, $journals);
        foreach ($journals as $journal) {
            $this->assertSame(0400, $journal['after_mode']);
            $this->assertSame(33, $journal['after_uid']);
            $this->assertSame(33, $journal['after_gid']);
            $this->assertSame(hash('sha256', $legacyBytes), $journal['sha256']);
            $this->assertSame($legacyBytes, file_get_contents($journal['path']));
        }

        file_put_contents($campaignPath.'/1m/pit-evidence.jsonl.gz', gzencode($legacyBytes));
        $duplicate = $this->runPlanner($planner);
        $this->assertSame(2, $duplicate->getExitCode());
        $this->assertStringContainsString('multiple campaign evidence journals', $duplicate->getErrorOutput());
    }

    public function test_empty_and_partial_retained_dataset_layouts_are_supported_and_hash_stable(): void
    {
        $campaignId = '72345678-1234-4234-8234-123456789abc';
        $campaignRoot = $this->fixtureRoot.'/campaigns';
        $sourcesRoot = $this->fixtureRoot.'/sources';
        mkdir($campaignRoot, 0700, true);
        mkdir($sourcesRoot, 0700, true);
        $campaignPath = $campaignRoot.'/'.$campaignId;
        mkdir($campaignPath.'/1m/dataset', 0700, true); // Interrupted after creating the directory.
        mkdir($campaignPath.'/3m/dataset', 0700, true);
        file_put_contents($campaignPath.'/3m/dataset/train.jsonl', ''); // Exact producer name, zero bytes.
        mkdir($campaignPath.'/6m/dataset', 0700, true);
        file_put_contents($campaignPath.'/6m/dataset/validation.jsonl', "{}\n"); // Partial output set.
        file_put_contents($campaignPath.'/6m/dataset/test.jsonl', '');

        $planner = '$campaign = (object) '.var_export(['id' => $campaignId, 'status' => 'cancelled'], true).'; $plan = planRegisteredObjects([], [$campaign], '.var_export($this->fixtureRoot, true).', '.var_export($sourcesRoot, true).', '.var_export($campaignRoot, true).', 33, 33); echo json_encode($plan);';
        $planned = $this->runPlanner($planner);
        $this->assertSame(0, $planned->getExitCode(), $planned->getErrorOutput());
        $objects = json_decode($planned->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $datasets = array_values(array_filter($objects, fn ($item) => $item['kind'] === 'retained-temporary-dataset-directory'));
        $partitions = array_values(array_filter($objects, fn ($item) => $item['kind'] === 'retained-temporary-dataset-partition'));
        $this->assertCount(3, $datasets);
        $this->assertCount(3, $partitions);
        foreach ($partitions as $partition) $this->assertSame(hash_file('sha256', $partition['path']), $partition['sha256']);
        $this->assertSame(hash('sha256', ''), collect($partitions)->firstWhere('path', $campaignPath.'/3m/dataset/train.jsonl')['sha256']);
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
        unlink($campaign.'/1m/dataset/validation.jsonl');

        $this->assertTrue(posix_mkfifo($campaign.'/1m/dataset/validation.jsonl', 0600));
        $nonregular = $this->runPlanner('walkCampaign('.var_export($campaign, true).', '.var_export($this->fixtureRoot, true).', 33, 33, "cancelled");');
        $this->assertSame(2, $nonregular->getExitCode());
        $this->assertStringContainsString('unrecognized retained dataset object', $nonregular->getErrorOutput());
        unlink($campaign.'/1m/dataset/validation.jsonl');

        file_put_contents($campaign.'/1m/unexpected.jsonl', '{}');
        $unknown = $this->runPlanner('walkCampaign('.var_export($campaign, true).', '.var_export($this->fixtureRoot, true).', 33, 33, "cancelled");');
        $this->assertSame(2, $unknown->getExitCode());
        $this->assertStringContainsString('unrecognized campaign evidence object', $unknown->getErrorOutput());
        unlink($campaign.'/1m/unexpected.jsonl');

        mkdir($this->fixtureRoot.'/sources', 0700, true);
        $campaignRoot = $this->fixtureRoot.'/campaigns';
        $campaignId = basename($campaign);
        $active = $this->runPlanner('$activeRow = (object) '.var_export(['id' => $campaignId, 'status' => 'training'], true).'; planRegisteredObjects([], [$activeRow], '.var_export($this->fixtureRoot, true).', '.var_export($this->fixtureRoot.'/sources', true).', '.var_export($campaignRoot, true).', 33, 33);');
        $this->assertSame(2, $active->getExitCode());
        $this->assertStringContainsString('active campaign has private objects', $active->getErrorOutput());
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
