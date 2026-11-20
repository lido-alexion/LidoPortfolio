<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

return new class extends Migration
{
    public function up(): void
    {
        $setting = DB::table('portfolio_settings')->where('setting_key', 'fee_components')->first();
        $components = $setting ? json_decode((string) $setting->setting_value, true) : null;
        if (! is_array($components)) {
            $components = \App\Services\FeeCalculatorService::defaultComponents();
        }
        foreach ($components as $component) {
            if (($component['id'] ?? null) === 'dp_zerodha') {
                return;
            }
        }
        $components[] = [
            'id' => 'dp_zerodha', 'label' => 'Depository participant (DP)', 'value' => '13',
            'mode' => 'fixed', 'applies_buy' => false, 'applies_sell' => true,
            'exchange' => 'both', 'gst_percent' => '18', 'aggregation' => 'isin_day_sell',
        ];
        DB::table('portfolio_settings')->updateOrInsert(['setting_key' => 'fee_components'], [
            'setting_value' => json_encode($components), 'updated_at' => now(),
        ]);
        Cache::forget('setting.fee_components');
    }

    public function down(): void
    {
        // Preserve administrator edits; fee component settings are user-managed data.
    }
};
