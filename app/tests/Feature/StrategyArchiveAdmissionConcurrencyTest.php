<?php

namespace Tests\Feature;

use App\Models\PortfolioProfile;
use App\Models\PendingSaleProceeds;
use App\Models\TradingStrategy;
use App\Models\User;
use App\Services\Artifacts\StrategyArtifactRegistry;
use App\Services\Lending\SaleProceedsAvailabilityService;
use App\Services\Strategy\StrategyArchiveBlocked;
use App\Services\Strategy\StrategyLifecycleAdmission;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** MySQL serial interleavings for the shared archive admission row lock. */
class StrategyArchiveAdmissionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_writer_then_archive_and_archive_then_writer_use_separate_mysql_transactions(): void
    {
        $primary = DB::getDefaultConnection();
        $this->assertSame('mysql', DB::connection($primary)->getDriverName());
        $alternate = 'str14_archive_interleaving';
        config(['database.connections.'.$alternate => config('database.connections.'.$primary)]);
        DB::purge($alternate);

        try {
            [$writerProfile, $writerStrategy] = $this->strategyFixture('writer-first');
            $this->useConnection($primary);
            app(SaleProceedsAvailabilityService::class)->scheduleForObligation(
                $writerProfile, $writerStrategy, 125.0, 125.0, PendingSaleProceeds::OBLIGATION_RECALL,
            ); // Writer transaction commits before the archive transaction starts.

            $this->useConnection($alternate);
            $writerProfile = PortfolioProfile::query()->findOrFail($writerProfile->id);
            $writerStrategy = TradingStrategy::query()->findOrFail($writerStrategy->id);
            try {
                app(StrategyArtifactRegistry::class)->archive($writerStrategy->id, $writerProfile, 'Retirement requested');
                $this->fail('Archive must observe the settlement committed by the preceding writer transaction.');
            } catch (StrategyArchiveBlocked $blocked) {
                $this->assertContains('settlements', array_column($blocked->blockers, 'code'));
            }
            $this->assertSame(TradingStrategy::STATUS_ACTIVE, $writerStrategy->fresh()->status);

            [$archiveProfile, $archiveStrategy] = $this->strategyFixture('archive-first');
            $archiveProfile = PortfolioProfile::query()->findOrFail($archiveProfile->id);
            $archiveStrategy = TradingStrategy::query()->findOrFail($archiveStrategy->id);
            app(StrategyArtifactRegistry::class)->archive($archiveStrategy->id, $archiveProfile, 'Retirement requested');
            $this->assertSame(TradingStrategy::STATUS_ARCHIVED, $archiveStrategy->fresh()->status);

            $this->useConnection($primary);
            $archiveProfile = PortfolioProfile::query()->findOrFail($archiveProfile->id);
            $archiveStrategy = TradingStrategy::query()->findOrFail($archiveStrategy->id);
            try {
                app(SaleProceedsAvailabilityService::class)->scheduleForObligation(
                    $archiveProfile, $archiveStrategy, 125.0, 125.0, PendingSaleProceeds::OBLIGATION_RECALL,
                );
                $this->fail('A writer must re-read Archived after acquiring the same barrier.');
            } catch (ValidationException) {
                $this->assertSame(0, PendingSaleProceeds::query()->where('profile_id', $archiveProfile->id)->count());
            }
        } finally {
            $this->useConnection($primary);
            DB::purge($alternate);
        }
    }

    public function test_bilateral_admission_locks_strategy_rows_in_ascending_id_order(): void
    {
        $primary = DB::getDefaultConnection();
        $this->assertSame('mysql', DB::connection($primary)->getDriverName());
        [$profile, $borrower] = $this->strategyFixture('bilateral-borrower');
        $lender = TradingStrategy::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Bilateral lender',
            'slug' => 'bilateral-lender-'.$profile->id,
            'status' => TradingStrategy::STATUS_DISABLED,
        ]);

        $locked = DB::transaction(fn () => app(StrategyLifecycleAdmission::class)->lockForNewObligation(
            (int) $profile->id, [(int) $lender->id, (int) $borrower->id],
        ));

        $this->assertSame(
            collect([$borrower->id, $lender->id])->sort()->values()->all(),
            $locked->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    /** @return array{PortfolioProfile,TradingStrategy} */
    private function strategyFixture(string $name): array
    {
        $user = User::factory()->create(['name' => $name]);
        $profile = $this->defaultPortfolioFor($user);
        $version = app(\App\Services\StrategyConfigurationService::class)->ensureActive($profile);

        return [$profile, TradingStrategy::query()->findOrFail($version->strategy_id)];
    }

    private function useConnection(string $name): void
    {
        DB::setDefaultConnection($name);
        DB::purge($name);
    }
}
