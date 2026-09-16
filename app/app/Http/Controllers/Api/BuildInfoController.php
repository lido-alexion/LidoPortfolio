<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;

class BuildInfoController extends Controller
{
    public function show(): JsonResponse
    {
        $path = base_path('bootstrap/build-info.json');

        if (! File::exists($path)) {
            return response()->json([
                'data' => [
                    'build_id' => 'local',
                    'commit_sha' => null,
                    'short_sha' => null,
                    'ref' => null,
                    'workflow' => null,
                    'run_id' => null,
                    'run_number' => null,
                    'run_attempt' => null,
                    'built_at' => null,
                ],
            ]);
        }

        $info = json_decode(File::get($path), true);

        if (! is_array($info)) {
            return response()->json([
                'message' => 'Build metadata is unreadable.',
            ], 500);
        }

        return response()->json(['data' => $info]);
    }
}
