<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DashboardLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardLayoutController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => DashboardLayout::query()->where('user_id', $request->user()->id)->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'definition' => ['required', 'array'], 'is_default' => ['sometimes', 'boolean']]);
        $layout = DashboardLayout::create(['user_id' => $request->user()->id, ...$data, 'schema_version' => (int) data_get($data, 'definition.version', 1)]);
        if ($layout->is_default) DashboardLayout::query()->where('user_id', $request->user()->id)->where('id', '!=', $layout->id)->update(['is_default' => false]);
        return response()->json(['data' => $layout], 201);
    }

    public function update(Request $request, DashboardLayout $dashboardLayout): JsonResponse
    {
        abort_unless($dashboardLayout->user_id === $request->user()->id, 404);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'definition' => ['sometimes', 'array'], 'is_default' => ['sometimes', 'boolean']]);
        $dashboardLayout->fill($data);
        if (array_key_exists('definition', $data)) $dashboardLayout->schema_version = (int) data_get($data, 'definition.version', $dashboardLayout->schema_version);
        $dashboardLayout->save();
        if ($dashboardLayout->is_default) DashboardLayout::query()->where('user_id', $request->user()->id)->where('id', '!=', $dashboardLayout->id)->update(['is_default' => false]);
        return response()->json(['data' => $dashboardLayout->fresh()]);
    }

    public function destroy(Request $request, DashboardLayout $dashboardLayout): JsonResponse
    {
        abort_unless($dashboardLayout->user_id === $request->user()->id, 404);
        $dashboardLayout->delete();
        return response()->json(['message' => 'Dashboard deleted.']);
    }
}
