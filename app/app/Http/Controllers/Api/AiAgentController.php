<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiAgentRun;
use App\Services\AI\{AiAgentService, AiToolFailure};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class AiAgentController extends Controller
{
    private function respond(callable $action)
    {
        try { return response()->json(['success' => true, 'data' => $action()]); }
        catch (AiToolFailure $error) { return response()->json(['success' => false, 'error' => ['code' => $error->reason]], $error->httpStatus); }
        catch (\Illuminate\Database\Eloquent\ModelNotFoundException) { return response()->json(['success' => false, 'error' => ['code' => 'not_found']], 404); }
        catch (\InvalidArgumentException) { return response()->json(['success' => false, 'error' => ['code' => 'domain_validation_failed']], 422); }
        catch (\Illuminate\Validation\ValidationException) { return response()->json(['success' => false, 'error' => ['code' => 'malformed_input']], 422); }
        catch (\Throwable $error) { report($error); return response()->json(['success' => false, 'error' => ['code' => 'backend_unavailable']], 503); }
    }

    public function gateway(Request $request, AiAgentService $agent)
    {
        return $this->respond(function () use ($request, $agent) {
            $data = $request->validate(['run_id' => ['required', 'uuid'], 'delegation' => ['required', 'string', 'size:64'], 'operation' => ['required', Rule::in(['catalog', 'read', 'preview', 'complete'])], 'tool' => ['sometimes', 'string', 'max:80'], 'arguments' => ['sometimes', 'array'], 'plan' => ['sometimes', 'array', 'max:5'], 'answer' => ['sometimes', 'string', 'max:6000']]);
            if (array_diff(array_keys($request->all()), array_keys($data))) throw new AiToolFailure('malformed_input');
            return $agent->gateway($data);
        });
    }

    public function index(Request $request)
    {
        return $this->respond(function () use ($request) { $this->reconcile($request); return AiAgentRun::query()->where('user_id', $request->user()->id)->latest()->limit(30)->get()->toArray(); });
    }
    public function show(Request $request, string $run)
    {
        return $this->respond(function () use ($request, $run) { $this->reconcile($request); return $this->owned($request, $run)->toArray(); });
    }
    private function reconcile(Request $request): void
    {
        AiAgentRun::query()->where('user_id', $request->user()->id)->where('status', 'awaiting_approval')->where('approval_expires_at', '<=', now())->update(['status' => 'expired']);
        AiAgentRun::query()->where('user_id', $request->user()->id)->where('status', 'investigating')->where('delegation_expires_at', '<=', now())->update(['status' => 'failed', 'answer' => 'This investigation was interrupted. Build a fresh plan to retry.']);
    }

    private function owned(Request $request, string $run): AiAgentRun
    {
        return AiAgentRun::query()->where('user_id', $request->user()->id)->findOrFail($run);
    }
    public function store(Request $request, AiAgentService $agent)
    {
        return $this->respond(function () use ($request, $agent) {
            $data = $request->validate(['objective' => ['required', 'string', 'max:4000'], 'retry_run_id' => ['sometimes', 'uuid']]);
            if (isset($data['retry_run_id'])) $data['objective'] = $this->owned($request, $data['retry_run_id'])->objective;
            [$run, $delegation] = $agent->create($request, $data['objective']);
            try {
                if (! config('ai_runtime.enabled')) throw new \RuntimeException('disabled');
                $response = Http::baseUrl(rtrim(config('ai_runtime.base_url'), '/'))->withHeaders(['X-StoX-AI-Service-Key' => config('ai_runtime.shared_secret')])->timeout(65)->post('/internal/v1/agent/investigate', ['run_id' => $run->id, 'delegation' => $delegation, 'objective' => $run->objective, 'user_id' => $run->user_id]);
                if ($response->failed()) throw new \RuntimeException('runtime unavailable');
            } catch (\Throwable $error) {
                $run->refresh();
                if ($run->status === 'investigating') $run->update(['status' => 'failed', 'answer' => 'The assistant could not finish this investigation. Retry starts a fresh run.']);
            }
            return $run->fresh()->toArray();
        });
    }
    public function approve(Request $request, string $run, AiAgentService $agent)
    {
        return $this->respond(function () use ($request, $run, $agent) {
            $data = $request->validate(['plan_hash' => ['required', 'string', 'size:64'], 'destructive_confirmation' => ['sometimes', 'accepted']]);
            return $agent->approveAndExecute($this->owned($request, $run), $data['plan_hash'], $request->boolean('destructive_confirmation'))->toArray();
        });
    }
    public function reject(Request $request, string $run)
    {
        return $this->respond(function () use ($request, $run) {
            return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $run) {
                $row = AiAgentRun::query()->where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($run);
                if ($row->status !== 'awaiting_approval') throw new AiToolFailure('run_state_conflict', 409);
                $row->update(['status' => 'rejected']);
                return $row->toArray();
            });
        });
    }
}
