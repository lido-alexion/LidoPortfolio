<?php

namespace Tests\Unit;

use App\Services\BseIdentityReconciliationPlan;
use PHPUnit\Framework\TestCase;

class BseIdentityReconciliationPlanTest extends TestCase
{
    private function stock(int $id = 1, array $changes = []): array
    {
        return $changes + [
            'id' => $id, 'symbol' => 'AAA', 'exchange' => 'BSE', 'isin' => null,
            'bse_scrip_code' => '500001', 'is_active' => true, 'is_benchmark' => false,
            'admin_deactivated' => false, 'updated_at' => '2026-10-26 00:00:00',
        ];
    }

    private function row(array $changes = []): array
    {
        return $changes + ['symbol' => 'AAA', 'scrip_code' => '500001', 'isin' => 'INE000A01010'];
    }

    public function test_only_exact_unique_missing_identity_is_proposed(): void
    {
        $planner = new BseIdentityReconciliationPlan;
        $stocks = [$this->stock()];
        $source = [$this->row()];
        $plan = $planner->build($stocks, $source);

        $this->assertSame([[
            'stock_id' => 1, 'symbol' => 'AAA', 'scrip_code' => '500001',
            'old_isin' => null, 'new_isin' => 'INE000A01010',
        ]], $plan['targets']);
        $this->assertSame(['proposed' => 1], $plan['reason_counts']);
        $this->assertNull($stocks[0]['isin']);
        $this->assertSame($this->row(), $source[0]);

        foreach ([
            [$this->stock(1, ['isin' => 'INE000A01010']), [$this->row()], 'already_identified'],
            [$this->stock(1, ['isin' => 'INE999A01010']), [$this->row()], 'existing_isin_conflict'],
            [$this->stock(1, ['isin' => ' ']), [$this->row()], 'existing_isin_conflict'],
            [$this->stock(), [$this->row(['symbol' => 'NEWNAME'])], 'symbol_change_requires_review'],
            [$this->stock(), [], 'source_code_absent'],
            [$this->stock(), [$this->row(['isin' => 'NA'])], 'source_isin_unavailable'],
            [$this->stock(1, ['bse_scrip_code' => null]), [$this->row()], 'master_code_unavailable'],
        ] as [$stock, $rows, $reason]) {
            $plan = $planner->build([$stock], $rows);
            $this->assertSame([], $plan['targets'], $reason);
            $this->assertSame([$reason => 1], $plan['reason_counts']);
        }
    }

    public function test_ambiguity_and_other_master_isins_fail_closed(): void
    {
        $planner = new BseIdentityReconciliationPlan;
        foreach ([
            [[$this->stock()], [$this->row(), $this->row()], 'ambiguous_source_code'],
            [[$this->stock()], [$this->row(), $this->row(['scrip_code' => '500002', 'symbol' => 'BBB'])], 'ambiguous_source_isin'],
            [[$this->stock(), $this->stock(2, ['symbol' => 'OLD', 'is_active' => false])], [$this->row()], 'ambiguous_master_code'],
            [[$this->stock(), $this->stock(2, ['symbol' => 'NSEOTHER', 'exchange' => 'NSE', 'isin' => 'INE000A01010'])], [$this->row()], 'existing_master_isin_collision'],
            [[$this->stock(), $this->stock(2, ['symbol' => 'OLD', 'bse_scrip_code' => '500002', 'is_active' => false, 'isin' => 'INE000A01010'])], [$this->row()], 'existing_master_isin_collision'],
        ] as [$stocks, $source, $reason]) {
            $plan = $planner->build($stocks, $source);
            $this->assertSame([], $plan['targets'], $reason);
            $this->assertSame($reason, $plan['outcomes'][0]['reason']);
        }
    }

    public function test_inactive_admin_deactivated_benchmarks_and_nse_are_never_candidates(): void
    {
        foreach ([
            ['is_active' => false], ['admin_deactivated' => true],
            ['is_benchmark' => true], ['exchange' => 'NSE'],
        ] as $flags) {
            $plan = (new BseIdentityReconciliationPlan)->build([$this->stock(1, $flags)], [$this->row()]);
            $this->assertSame([], $plan['targets']);
            $this->assertSame([], $plan['outcomes']);
        }
    }

    public function test_hash_is_order_independent_but_binds_source_and_current_master(): void
    {
        $planner = new BseIdentityReconciliationPlan;
        $stocks = [$this->stock(), $this->stock(2, ['symbol' => 'BBB', 'bse_scrip_code' => '500002'])];
        $rows = [$this->row(), $this->row(['symbol' => 'BBB', 'scrip_code' => '500002', 'isin' => 'INE001A01010'])];
        $plan = $planner->build($stocks, $rows);
        $this->assertSame($plan['plan_sha256'], $planner->build(array_reverse($stocks), array_reverse($rows))['plan_sha256']);
        $unchangedRows = $rows;
        $stocks[0]['updated_at'] = '2026-10-27 00:00:00';
        $rows[0]['Mktcap'] = '999999';
        $this->assertSame($plan['plan_sha256'], $planner->build($stocks, $rows)['plan_sha256']);
        $rows[0]['isin'] = 'INE999A01010';
        $this->assertNotSame($plan['plan_sha256'], $planner->build($stocks, $rows)['plan_sha256']);
        $stocks[0]['admin_deactivated'] = true;
        $this->assertNotSame($plan['plan_sha256'], $planner->build($stocks, array_reverse($unchangedRows))['plan_sha256']);
    }

    public function test_plan_hash_survives_native_json_object_key_reordering(): void
    {
        $planner = new BseIdentityReconciliationPlan;
        $plan = $planner->build([$this->stock()], [$this->row()]);
        $reorder = function (mixed $value) use (&$reorder): mixed {
            if (! is_array($value)) {
                return $value;
            }
            $value = array_map($reorder, $value);

            return array_is_list($value) ? $value : array_reverse($value, true);
        };
        $stored = json_decode(json_encode($reorder($plan), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($plan['plan_sha256'], $planner->hashPlan($stored));
        $stored['targets'][0]['stock_id'] = (string) $stored['targets'][0]['stock_id'];
        $this->assertNotSame($plan['plan_sha256'], $planner->hashPlan($stored));
    }
}
