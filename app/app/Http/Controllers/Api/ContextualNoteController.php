<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContextualNote;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContextualNoteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'context_key' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'profile_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'subject_id' => ['nullable', 'string', 'max:120'],
        ]);

        $query = ContextualNote::query()
            ->where('user_id', $request->user()->id)
            ->where('context_key', $validated['context_key'])
            ->orderByDesc('updated_at');

        $this->applyOptionalScope($query, $validated);

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'context_key' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'profile_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'subject_id' => ['nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:10000'],
        ]);

        $profileId = $this->authorizedProfileId($request, $validated['profile_id'] ?? null);
        $note = ContextualNote::query()->updateOrCreate([
            'user_id' => $request->user()->id,
            'profile_id' => $profileId,
            'context_key' => $validated['context_key'],
            'subject_type' => $validated['subject_type'] ?? null,
            'subject_id' => $validated['subject_id'] ?? null,
        ], [
            'body' => $validated['body'] ?? '',
        ]);

        return response()->json(['data' => $note->fresh()], $note->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, ContextualNote $contextualNote): JsonResponse
    {
        $this->authorizeNote($request, $contextualNote);
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $contextualNote->update(['body' => $validated['body']]);

        return response()->json(['data' => $contextualNote->fresh()]);
    }

    public function destroy(Request $request, ContextualNote $contextualNote): JsonResponse
    {
        $this->authorizeNote($request, $contextualNote);
        $contextualNote->delete();

        return response()->json(['message' => 'Note deleted.']);
    }

    /**
     * @param  Builder<ContextualNote>  $query
     * @param  array<string,mixed>  $filters
     */
    protected function applyOptionalScope($query, array $filters): void
    {
        foreach (['profile_id', 'subject_type', 'subject_id'] as $key) {
            if (array_key_exists($key, $filters)) {
                $filters[$key] === null
                    ? $query->whereNull($key)
                    : $query->where($key, $filters[$key]);
            }
        }
    }

    protected function authorizedProfileId(Request $request, mixed $profileId): ?int
    {
        if ($profileId === null || $profileId === '') {
            return null;
        }

        $id = (int) $profileId;
        $owns = $request->user()->portfolios()->whereKey($id)->exists();
        abort_unless($owns, 404, 'Portfolio not found.');

        return $id;
    }

    protected function authorizeNote(Request $request, ContextualNote $note): void
    {
        abort_unless((int) $note->user_id === (int) $request->user()->id, 404, 'Note not found.');
    }
}
