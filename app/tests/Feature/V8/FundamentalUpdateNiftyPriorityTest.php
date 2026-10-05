<?php

namespace Tests\Feature\V8;

use App\Models\Setting;
use App\Models\Stock;
use App\Models\V7\FundamentalUpdateJob;
use App\Services\Fundamentals\FundamentalUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundamentalUpdateNiftyPriorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_nifty500_stocks_are_selected_before_other_stocks(): void
    {
        $outside = Stock::query()->create([
            'symbol' => 'OUTSIDE500', 'exchange' => 'NSE', 'name' => 'Outside 500',
            'is_active' => true, 'is_benchmark' => false,
        ]);
        $outside->forceFill(['admin_deactivated' => false])->save();
        $member = Stock::query()->create([
            'symbol' => 'IN500', 'exchange' => 'NSE', 'name' => 'In 500',
            'is_active' => true, 'is_benchmark' => false,
        ]);
        $member->forceFill(['admin_deactivated' => false])->save();
        Setting::setValue('nifty500_constituents_json', json_encode(['IN500']));
        Setting::setValue('nifty500_constituents_cached_at', now()->toIso8601String());

        $run = app(FundamentalUpdateService::class)->createRun('scheduled', 'incremental', null, 1);
        $selected = FundamentalUpdateJob::query()->where('run_id', $run->id)->value('stock_id');

        $this->assertSame($member->id, (int) $selected);
        $this->assertNotSame($outside->id, (int) $selected);
    }
}
