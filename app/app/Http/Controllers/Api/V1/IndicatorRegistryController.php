<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Services\Indicators\IndicatorCategory;
use App\Services\Indicators\IndicatorConsumer;
use App\Services\Indicators\IndicatorRegistry;
use App\Services\Indicators\IndicatorStatus;
use App\Services\Indicators\IndicatorType;
use Illuminate\Http\Request;

/**
 * Read-only Indicator Registry discovery APIs (admin-only).
 */
class IndicatorRegistryController extends Controller
{
    public function __construct(
        private IndicatorRegistry $registry,
    ) {}

    public function index(Request $request)
    {
        $criteria = [];
        foreach (['type', 'category', 'status', 'consumer'] as $key) {
            if ($request->filled($key)) {
                $criteria[$key] = (string) $request->input($key);
            }
        }
        foreach (['screenable', 'chartable', 'visible', 'strategy_scorable'] as $key) {
            if ($request->has($key) && $request->input($key) !== null && $request->input($key) !== '') {
                $criteria[$key] = filter_var($request->input($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            }
        }

        $items = $this->registry->search($request->input('q'), $criteria);
        usort($items, fn ($a, $b) => strcmp($a->id, $b->id));

        return ApiEnvelope::success(
            array_map(fn ($d) => $d->toArray(), $items),
            [
                'count' => count($items),
                'filters' => $criteria,
                'q' => $request->input('q'),
            ],
        );
    }

    public function meta()
    {
        return ApiEnvelope::success([
            'types' => array_map(
                fn (string $id) => ['id' => $id, 'label' => IndicatorType::labels()[$id] ?? $id],
                IndicatorType::all(),
            ),
            'categories' => array_map(
                fn (string $id) => ['id' => $id, 'label' => IndicatorCategory::labels()[$id] ?? $id],
                IndicatorCategory::all(),
            ),
            'statuses' => array_map(
                fn (string $id) => ['id' => $id, 'label' => IndicatorStatus::labels()[$id] ?? $id],
                IndicatorStatus::all(),
            ),
            'consumers' => array_map(
                fn (string $id) => ['id' => $id, 'label' => IndicatorConsumer::labels()[$id] ?? $id],
                IndicatorConsumer::all(),
            ),
            'counts' => [
                'total' => $this->registry->count(),
                'primary' => count($this->registry->byType(IndicatorType::PRIMARY)),
                'composite' => count($this->registry->byType(IndicatorType::COMPOSITE)),
                'metric' => count($this->registry->byType(IndicatorType::METRIC)),
            ],
        ]);
    }

    public function show(string $id)
    {
        $resolved = $this->registry->resolveId($id);
        if ($resolved === null) {
            return ApiEnvelope::error('INDICATOR_NOT_FOUND', "Unknown indicator: {$id}", 404);
        }

        $definition = $this->registry->get($resolved);

        return ApiEnvelope::success([
            'indicator' => $definition->toArray(),
            'dependency_tree' => $this->registry->dependencyTreeDetailed($resolved),
            'dependencies' => array_map(
                function (string $depId) {
                    $dep = $this->registry->find($depId);

                    return $dep ? [
                        'id' => $dep->id,
                        'display_name' => $dep->displayName,
                        'type' => $dep->type,
                        'status' => $dep->status,
                    ] : ['id' => $depId, 'missing' => true];
                },
                $definition->dependsOn,
            ),
        ]);
    }
}
