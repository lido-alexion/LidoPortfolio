<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DashboardLayout;
use App\Support\DashboardLayoutDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardLayoutController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $layouts = DashboardLayout::query()->where('user_id', $request->user()->id)->orderBy('name')->get();
        foreach ($layouts as $layout) {
            $normalized = DashboardLayoutDefinition::normalize($layout->definition);
            if ($normalized !== $layout->definition || $layout->schema_version !== $normalized['version']) {
                $layout->definition = $normalized;
                $layout->schema_version = $normalized['version'];
                $layout->save();
            }
        }
        return response()->json(['data' => $layouts]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'definition' => ['required', 'array'], 'is_default' => ['sometimes', 'boolean']]);
        $data['definition'] = $this->definition($data['definition']);
        $layout = DB::transaction(function () use ($request, $data) {
            $this->lockAccountAndEnsureCapacity($request->user()->id);
            if ($data['is_default'] ?? false) DashboardLayout::query()->where('user_id', $request->user()->id)->update(['is_default' => false]);
            return DashboardLayout::create(['user_id' => $request->user()->id, ...$data, 'schema_version' => $data['definition']['version']]);
        });
        return response()->json(['data' => $layout], 201);
    }

    public function update(Request $request, DashboardLayout $dashboardLayout): JsonResponse
    {
        abort_unless((int) $dashboardLayout->user_id === (int) $request->user()->id, 404);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'definition' => ['sometimes', 'array'], 'is_default' => ['sometimes', 'boolean']]);
        if (array_key_exists('definition', $data)) $data['definition'] = $this->definition($data['definition']);
        DB::transaction(function () use ($dashboardLayout, $request, $data) {
            DB::table('portfolio_users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $current = DashboardLayout::query()->where('user_id', $request->user()->id)->whereKey($dashboardLayout->id)->lockForUpdate()->firstOrFail();
            $current->fill($data);
            if (array_key_exists('definition', $data)) $current->schema_version = $data['definition']['version'];
            if ($current->is_default) DashboardLayout::query()->where('user_id', $request->user()->id)->where('id', '!=', $current->id)->update(['is_default' => false]);
            $current->save();
            $dashboardLayout->setRawAttributes($current->getAttributes(), true);
        });
        return response()->json(['data' => $dashboardLayout->fresh()]);
    }

    public function duplicate(Request $request, DashboardLayout $dashboardLayout): JsonResponse
    {
        abort_unless((int) $dashboardLayout->user_id === (int) $request->user()->id, 404);
        $name = $request->validate(['name' => ['required', 'string', 'max:120']])['name'];
        $copy = DB::transaction(function () use ($request, $dashboardLayout, $name) {
            $this->lockAccountAndEnsureCapacity($request->user()->id);
            $source = DashboardLayout::query()->where('user_id', $request->user()->id)->whereKey($dashboardLayout->id)->firstOrFail();
            $definition = $this->definition($source->definition);
            return DashboardLayout::create(['user_id' => $request->user()->id, 'name' => $name, 'definition' => $definition, 'schema_version' => $definition['version'], 'is_default' => false]);
        });
        return response()->json(['data' => $copy], 201);
    }

    public function destroy(Request $request, DashboardLayout $dashboardLayout): JsonResponse
    {
        abort_unless($dashboardLayout->user_id === $request->user()->id, 404);
        $dashboardLayout->delete();
        return response()->json(['message' => 'Dashboard deleted.']);
    }

    private function definition(array $definition): array
    {
        abort_if(strlen(json_encode($definition, JSON_THROW_ON_ERROR)) > 32768, 422, 'Dashboard definition exceeds the 32 KB limit.');
        return DashboardLayoutDefinition::normalize($definition);
    }

    private function lockAccountAndEnsureCapacity(int $userId): void
    {
        DB::table('portfolio_users')->where('id', $userId)->lockForUpdate()->first();
        abort_if(DashboardLayout::query()->where('user_id', $userId)->count() >= 20, 422, 'A maximum of 20 named dashboards is allowed.');
    }
}
