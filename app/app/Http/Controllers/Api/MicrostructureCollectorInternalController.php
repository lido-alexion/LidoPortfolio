<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Microstructure\MicrostructureCollectorBootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MicrostructureCollectorInternalController extends Controller
{
    public function bootstrap(Request $request, MicrostructureCollectorBootstrapService $bootstrap): JsonResponse
    {
        $refresh = $request->boolean('refresh_universe');

        return response()->json([
            'data' => $bootstrap->bootstrap($refresh),
        ]);
    }
}
