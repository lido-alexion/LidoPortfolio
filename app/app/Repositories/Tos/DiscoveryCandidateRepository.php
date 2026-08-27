<?php

namespace App\Repositories\Tos;

use App\Models\Candidate;
use App\Models\DiscoveryRun;
use App\Models\PortfolioProfile;
use Illuminate\Support\Collection;

/**
 * V4-FEAT-032 — Discovery run + candidate list/find queries.
 * Ranking of candidates by evaluation rank stays in DiscoveryEngine.
 */
class DiscoveryCandidateRepository
{
    public function latestCompleted(PortfolioProfile $profile): ?DiscoveryRun
    {
        return DiscoveryRun::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->first();
    }

    public function latestCompletedId(PortfolioProfile $profile): ?int
    {
        $id = DiscoveryRun::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @return Collection<int, Candidate>
     */
    public function forDiscoveryRun(int $discoveryRunId): Collection
    {
        return Candidate::query()
            ->where('discovery_run_id', $discoveryRunId)
            ->with('security')
            ->get();
    }

    /**
     * @return Collection<int, Candidate>
     */
    public function listFiltered(
        ?int $discoveryRunId = null,
        ?PortfolioProfile $profile = null,
        ?string $source = null,
        ?string $search = null,
    ): Collection {
        $query = Candidate::query()->with(['security', 'discoveryRun', 'evaluationResult']);

        if ($discoveryRunId) {
            $query->where('discovery_run_id', $discoveryRunId);
        } elseif ($profile) {
            $latest = $this->latestCompletedId($profile);
            if (! $latest) {
                return new Collection;
            }
            $query->where('discovery_run_id', $latest);
        }

        if ($source !== null && trim($source) !== '') {
            $query->where('source', trim($source));
        }

        if ($search !== null && trim($search) !== '') {
            $like = '%'.addcslashes(trim($search), '%_\\').'%';
            $query->whereHas('security', function ($q) use ($like) {
                $q->where('symbol', 'like', $like)->orWhere('name', 'like', $like);
            });
        }

        return $query->orderBy('id')->get();
    }
}
