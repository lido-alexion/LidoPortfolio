<?php

namespace App\Services;

use App\Models\ArtifactBinding;
use App\Models\ArtifactBindingRevision;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PortfolioCloneService
{
    public function __construct(
        protected CashManagementService $cash,
        protected WatchlistService $watchlists,
    ) {}

    public function cloneAsPaper(
        User $user,
        PortfolioProfile $source,
        string $name,
        bool $copyHoldings,
        float $startingCash,
        string $priceMethod,
    ): PortfolioProfile {
        return DB::transaction(function () use ($user, $source, $name, $copyHoldings, $startingCash, $priceMethod): PortfolioProfile {
            $paper = PortfolioProfile::query()->create([
                'user_id' => $user->id,
                'name' => $name,
                'is_default' => false,
                'portfolio_type' => PortfolioProfile::TYPE_PAPER,
                'simulation_state' => PortfolioProfile::SIMULATION_ACTIVE,
                'simulation_price_method' => $priceMethod,
                'simulation_evidence' => [
                    'provenance' => 'clone_as_paper',
                    'source_profile_id' => $source->id,
                    'source_profile_name' => $source->name,
                    'copy_holdings' => $copyHoldings,
                    'starting_cash' => $startingCash,
                    'created_at' => now()->toISOString(),
                ],
            ]);
            $this->watchlists->ensureDefaultWatchlist($paper);
            $this->cash->deposit($paper, $startingCash, 'Paper clone starting simulated cash', $user);

            if ($copyHoldings) {
                $this->copyHoldings($source, $paper);
            }
            $this->copyActiveArtifactBindings($source, $paper, $user);

            return $paper->fresh();
        });
    }

    protected function copyHoldings(PortfolioProfile $source, PortfolioProfile $paper): void
    {
        Holding::query()
            ->where('profile_id', $source->id)
            ->where('quantity', '>', 0)
            ->orderBy('id')
            ->each(function (Holding $holding) use ($paper): void {
                Holding::query()->create([
                    'profile_id' => $paper->id,
                    'stock_id' => $holding->stock_id,
                    'strategy_id' => null,
                    'owner_key' => Holding::OWNER_UNMANAGED,
                    'quantity' => $holding->quantity,
                    'avg_buy_price' => $holding->avg_buy_price,
                    'invested_amount' => $holding->invested_amount,
                    'target_amount' => $holding->target_amount,
                    'filled_amount' => $holding->filled_amount,
                    'total_fees' => $holding->total_fees,
                    'realized_profit' => 0,
                    'updated_at' => now(),
                ]);
            });
    }

    protected function copyActiveArtifactBindings(PortfolioProfile $source, PortfolioProfile $paper, User $actor): void
    {
        ArtifactBinding::query()
            ->with('activeRevision')
            ->where('profile_id', $source->id)
            ->whereNotNull('active_revision_id')
            ->orderBy('id')
            ->each(function (ArtifactBinding $binding) use ($paper, $actor): void {
                $sourceRevision = $binding->activeRevision;
                if (! $sourceRevision) {
                    return;
                }

                $clone = ArtifactBinding::query()->create([
                    'binding_uuid' => (string) Str::uuid(),
                    'profile_id' => $paper->id,
                    'artifact_id' => $binding->artifact_id,
                    'status' => $binding->status,
                    'usability_state' => $binding->usability_state,
                    'usability_reasons_json' => $binding->usability_reasons_json,
                    'lock_version' => 0,
                ]);

                $revision = ArtifactBindingRevision::query()->create([
                    'binding_id' => $clone->id,
                    'revision_number' => 1,
                    'artifact_version_id' => $sourceRevision->artifact_version_id,
                    'settings_json' => $sourceRevision->settings_json,
                    'binding_status' => $sourceRevision->binding_status,
                    'usability_state' => $sourceRevision->usability_state,
                    'usability_reasons_json' => $sourceRevision->usability_reasons_json,
                    'action' => 'clone_as_paper',
                    'change_summary' => 'Pinned from source portfolio clone.',
                    'activated_by_user_id' => $actor->id,
                    'activated_at' => now(),
                ]);

                $clone->forceFill(['active_revision_id' => $revision->id])->save();
            });
    }
}
