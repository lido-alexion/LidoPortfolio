<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiCapability;
use App\Models\AiProviderPath;
use App\Models\AiPrompt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiPlatformAdminController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'enabled' => (bool) config('ai_runtime.enabled', false),
            'capabilities' => AiCapability::query()->orderBy('capability_id')->get(),
            'provider_paths' => AiProviderPath::query()->orderBy('priority')->orderBy('path_id')->get()->map(fn (AiProviderPath $path) => $this->safePath($path)),
            'prompts' => AiPrompt::query()->orderBy('prompt_id')->orderByDesc('version')->get(),
        ]);
    }

    public function upsertCapability(Request $request, string $capability): JsonResponse
    {
        $data = $request->validate([
            'owner' => ['required', 'string', 'max:120'],
            'path_order' => ['nullable', 'array'],
            'output_schema' => ['nullable', 'array'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        $record = AiCapability::query()->updateOrCreate(['capability_id' => $capability], $data);
        return response()->json($record);
    }

    public function upsertProviderPath(Request $request, string $path): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:120'],
            'model' => ['required', 'string', 'max:180'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'config' => ['nullable', 'array'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        $record = AiProviderPath::query()->updateOrCreate(['path_id' => $path], $data);
        return response()->json($this->safePath($record));
    }

    public function publishPrompt(Request $request, string $prompt): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'template' => ['required', 'string'],
            'input_schema' => ['nullable', 'array'],
            'output_schema' => ['nullable', 'array'],
            'active' => ['sometimes', 'boolean'],
        ]);
        if (($data['active'] ?? false) === true) {
            AiPrompt::query()->where('prompt_id', $prompt)->update(['active' => false]);
        }
        $record = AiPrompt::query()->updateOrCreate(
            ['prompt_id' => $prompt, 'version' => $data['version']],
            $data + ['prompt_id' => $prompt],
        );
        return response()->json($record);
    }

    private function safePath(AiProviderPath $path): AiProviderPath
    {
        $safe = clone $path;
        $config = $safe->config ?: [];
        foreach (['api_key', 'token', 'secret', 'password'] as $key) {
            if (array_key_exists($key, $config)) { $config[$key] = '********'; }
        }
        $safe->setAttribute('config', $config);
        return $safe;
    }
}
