<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ComboChartPreferenceController extends Controller
{
    private const KEY = 'stock_details_combo_chart_default';

    private const PRESETS = ['price-volume', 'price-pe', 'price-pb', 'price-ps', 'price-eps', 'price-revenue', 'price-net-profit'];

    public function show(Request $request): JsonResponse
    {
        $value = DB::table('portfolio_account_settings')
            ->where('user_id', $request->user()->id)
            ->where('setting_key', self::KEY)
            ->value('setting_value');

        return ApiEnvelope::success(['default_preset_id' => $value ?: null, 'scope' => 'account']);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'default_preset_id' => ['required', 'string', Rule::in(self::PRESETS)],
        ]);
        DB::table('portfolio_account_settings')->updateOrInsert(
            ['user_id' => $request->user()->id, 'setting_key' => self::KEY],
            ['setting_value' => $validated['default_preset_id'], 'updated_at' => now()],
        );

        return ApiEnvelope::success(['default_preset_id' => $validated['default_preset_id'], 'scope' => 'account']);
    }
}
