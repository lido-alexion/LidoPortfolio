<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\V7\BootstrapPreset;
use App\Models\V7\BootstrapSchedule;
use App\Services\Fundamentals\BootstrapScheduleOperationsService;
use App\Services\Fundamentals\Scheduling\BootstrapScheduleLaunchAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapScheduleOperationsController extends Controller
{
    public function __construct(private BootstrapScheduleOperationsService $operations) {}

    public function presets(Request $request): JsonResponse { return response()->json(['data' => $this->operations->presets((int) $request->query('per_page', 25))]); }
    public function createPreset(Request $request): JsonResponse
    {
        $this->assertOnlyRequestKeys($request, ['name', 'configuration']);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'configuration' => ['required', 'array:scope,stock_ids,force,reason'], 'configuration.scope' => ['required', 'in:all,stocks'], 'configuration.stock_ids' => ['sometimes', 'array', 'max:500'], 'configuration.stock_ids.*' => ['integer', 'distinct', 'min:1', 'exists:portfolio_stocks,id'], 'configuration.force' => ['sometimes', 'boolean'], 'configuration.reason' => ['sometimes', 'string', 'max:2000']]);
        return response()->json(['data' => $this->operations->presentPreset($this->operations->savePreset($data, (int) $request->user()->id))], 201);
    }
    public function updatePreset(Request $request, BootstrapPreset $preset): JsonResponse
    {
        $this->assertOnlyRequestKeys($request, ['name', 'configuration']);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'configuration' => ['required', 'array:scope,stock_ids,force,reason'], 'configuration.scope' => ['required', 'in:all,stocks'], 'configuration.stock_ids' => ['sometimes', 'array', 'max:500'], 'configuration.stock_ids.*' => ['integer', 'distinct', 'min:1', 'exists:portfolio_stocks,id'], 'configuration.force' => ['sometimes', 'boolean'], 'configuration.reason' => ['sometimes', 'string', 'max:2000']]);
        return response()->json(['data' => $this->operations->presentPreset($this->operations->savePreset($data, (int) $request->user()->id, $preset))]);
    }
    public function clonePreset(Request $request, BootstrapPreset $preset): JsonResponse
    {
        $this->assertOnlyRequestKeys($request, ['name']);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        return response()->json(['data' => $this->operations->presentPreset($this->operations->clonePreset($preset, $data['name'], (int) $request->user()->id))], 201);
    }
    public function deletePreset(Request $request, BootstrapPreset $preset): JsonResponse { $this->operations->deletePreset($preset, (int) $request->user()->id); return response()->json(['data' => ['deleted' => true]]); }

    public function schedules(Request $request): JsonResponse { return response()->json(['data' => $this->operations->schedules((int) $request->query('per_page', 25))]); }
    public function createSchedule(Request $request): JsonResponse
    {
        $data = $this->scheduleInput($request, true);
        return response()->json(['data' => $this->operations->presentSchedule($this->operations->createSchedule($data, (int) $request->user()->id))], 201);
    }
    public function updateSchedule(Request $request, BootstrapSchedule $schedule): JsonResponse
    {
        return response()->json(['data' => $this->operations->presentSchedule($this->operations->editSchedule($schedule, $this->scheduleInput($request, false), (int) $request->user()->id))]);
    }
    public function enable(Request $request, BootstrapSchedule $schedule): JsonResponse
    {
        $this->assertOnlyRequestKeys($request, ['enabled']);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        return response()->json(['data' => $this->operations->presentSchedule($this->operations->setEnabled($schedule, (bool) $data['enabled'], (int) $request->user()->id))]);
    }
    public function cloneSchedule(Request $request, BootstrapSchedule $schedule): JsonResponse
    {
        $this->assertOnlyRequestKeys($request, ['name']);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        return response()->json(['data' => $this->operations->presentSchedule($this->operations->cloneSchedule($schedule, $data['name'], (int) $request->user()->id))], 201);
    }
    public function occurrences(Request $request, BootstrapSchedule $schedule): JsonResponse
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        return response()->json(['data' => $this->operations->occurrences($schedule, (int) ($data['per_page'] ?? 25))]);
    }
    public function runNow(Request $request, BootstrapSchedule $schedule, BootstrapScheduleLaunchAdapter $adapter): JsonResponse
    {
        $this->assertOnlyRequestKeys($request, []);
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key), 422, 'A valid Idempotency-Key header is required.');
        $result = $this->operations->runNow($schedule, (int) $request->user()->id, (string) $request->user()->name, $key, $adapter);
        return response()->json(['data' => ['run_id' => $result->run->id, 'created' => $result->created]], $result->created ? 201 : 200);
    }

    private function scheduleInput(Request $request, bool $creating): array
    {
        $this->assertOnlyRequestKeys($request, ['name', 'preset_id', 'configuration', 'frequency', 'interval', 'weekday', 'monthday', 'local_time', 'timezone', 'starts_at_local', 'ends_on_local', 'max_occurrences']);
        $rules = [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'], 'preset_id' => [$creating ? 'sometimes' : 'prohibited', 'integer', 'min:1', 'exists:stox_bootstrap_presets,id'],
            'configuration' => [$creating ? 'required_without:preset_id' : 'sometimes', 'array:scope,stock_ids,force,reason'], 'configuration.scope' => ['required_with:configuration', 'in:all,stocks'],
            'configuration.stock_ids' => ['sometimes', 'array', 'max:500'], 'configuration.stock_ids.*' => ['integer', 'distinct', 'min:1', 'exists:portfolio_stocks,id'],
            'configuration.force' => ['sometimes', 'boolean'], 'configuration.reason' => ['sometimes', 'string', 'max:2000'],
            'frequency' => ['required', 'in:daily,weekly,monthly'], 'interval' => ['sometimes', 'integer', 'min:1', 'max:365'], 'weekday' => ['required_if:frequency,weekly', 'integer', 'min:1', 'max:7'],
            'monthday' => ['required_if:frequency,monthly', 'integer', 'min:1', 'max:31'], 'local_time' => ['sometimes', 'date_format:H:i,H:i:s'], 'timezone' => ['required', 'timezone'],
            'starts_at_local' => ['required', 'date'], 'ends_on_local' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'max_occurrences' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
        ];
        return $request->validate($rules);
    }

    private function assertOnlyRequestKeys(Request $request, array $allowed): void
    {
        $unsupported = array_diff(array_keys($request->all()), $allowed);
        if ($unsupported !== []) throw \Illuminate\Validation\ValidationException::withMessages(['request' => 'Unsupported fields: '.implode(', ', $unsupported).'.']);
    }
}
