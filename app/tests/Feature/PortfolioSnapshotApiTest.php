<?php

namespace Tests\Feature;

use App\Models\PortfolioSnapshot;
use App\Models\User;
use App\Services\Export\ExportDatasetRegistry;
use App\Services\PortfolioCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortfolioSnapshotApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_provider_resolves_current_range_in_requested_sort_order_with_stable_ids(): void
    {
        $user = User::query()->create(['name' => 'Snapshot Export User', 'email' => 'snap-export-'.Str::random(8).'@example.com', 'password' => 'password123']);
        $profile = $this->defaultPortfolioFor($user);
        foreach ([now()->subDays(4), now()->subDays(2), now()->subDays(1)] as $date) {
            PortfolioSnapshot::query()->create(['profile_id' => $profile->id, 'snapshot_date' => $date->toDateString(), 'portfolio_value' => '123.456789', 'invested_value' => '100.000001', 'created_at' => now()]);
        }
        $registry = new ExportDatasetRegistry($this->createMock(PortfolioCalculationService::class));
        $resolved = $registry->resolve('portfolio-snapshots', $profile, ['range' => '90d', 'sort_by' => 'snapshot_date', 'sort_direction' => 'desc']);
        $this->assertSame([now()->subDays(1)->toDateString(), now()->subDays(2)->toDateString(), now()->subDays(4)->toDateString()], array_column($resolved['rows'], 'snapshot_date'));
        $this->assertCount(3, array_unique($resolved['identities']));
        $this->assertSame('desc', $resolved['metadata']['sort']['direction']);

        $ascending = $registry->resolve('portfolio-growth', $profile, ['range' => '90d', 'sort_by' => 'snapshot_date', 'sort_direction' => 'asc']);
        $this->assertSame(array_reverse(array_column($resolved['rows'], 'snapshot_date')), array_column($ascending['rows'], 'snapshot_date'));
        $this->assertSame($resolved['identities'], array_reverse($ascending['identities']));
    }

    public function test_current_export_rejects_missing_or_unsupported_filters_and_sort(): void
    {
        $user = User::query()->create(['name' => 'Snapshot Export Validation', 'email' => 'snap-export-validation-'.Str::random(8).'@example.com', 'password' => 'password123']);
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user)->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson('/api/exports', ['dataset' => 'portfolio-snapshots', 'format' => 'csv', 'scope' => 'current'])
            ->assertUnprocessable();
        $this->actingAs($user)->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson('/api/exports', ['dataset' => 'portfolio-snapshots', 'format' => 'csv', 'scope' => 'current', 'filters' => ['range' => '90d', 'sort_by' => 'portfolio_value', 'sort_direction' => 'desc']])
            ->assertUnprocessable();
    }

    public function test_snapshots_endpoint_requires_auth(): void
    {
        $this->getJson('/api/portfolio/snapshots')->assertUnauthorized();
    }

    public function test_snapshots_returns_empty_list_when_none_exist(): void
    {
        $user = User::query()->create([
            'name' => 'Snapshot API User',
            'email' => 'snap-api-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $response = $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $profile->id)
            ->getJson('/api/portfolio/snapshots');

        $response->assertOk()
            ->assertJsonPath('meta.count', 0)
            ->assertJsonPath('snapshots', []);
    }

    public function test_snapshots_are_scoped_to_active_profile_and_ordered_ascending(): void
    {
        $user = User::query()->create([
            'name' => 'Snapshot Scope User',
            'email' => 'snap-scope-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $other = $this->createPortfolioProfile($user, 'Other', false);

        foreach ([
            ['date' => '2026-02-01', 'value' => 1000, 'invested' => 900],
            ['date' => '2026-02-03', 'value' => 1100, 'invested' => 900],
            ['date' => '2026-02-02', 'value' => 1050, 'invested' => 900],
        ] as $row) {
            PortfolioSnapshot::query()->create([
                'profile_id' => $profile->id,
                'snapshot_date' => $row['date'],
                'portfolio_value' => $row['value'],
                'invested_value' => $row['invested'],
                'created_at' => now(),
            ]);
        }

        PortfolioSnapshot::query()->create([
            'profile_id' => $other->id,
            'snapshot_date' => '2026-02-04',
            'portfolio_value' => 5000,
            'invested_value' => 4000,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $profile->id)
            ->getJson('/api/portfolio/snapshots');

        $response->assertOk()
            ->assertJsonPath('meta.count', 3)
            ->assertJsonPath('meta.from_date', '2026-02-01')
            ->assertJsonPath('meta.to_date', '2026-02-03');

        $dates = collect($response->json('snapshots'))->pluck('snapshot_date')->all();
        $this->assertSame(['2026-02-01', '2026-02-02', '2026-02-03'], $dates);
        $this->assertNotEmpty($response->json('snapshots.0.id'));
        $this->assertCount(3, collect($response->json('snapshots'))->pluck('id')->unique());
    }

    public function test_snapshots_support_from_date_filter(): void
    {
        $user = User::query()->create([
            'name' => 'Snapshot Filter User',
            'email' => 'snap-filter-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        for ($i = 1; $i <= 5; $i++) {
            PortfolioSnapshot::query()->create([
                'profile_id' => $profile->id,
                'snapshot_date' => sprintf('2026-03-%02d', $i),
                'portfolio_value' => 1000 + ($i * 10),
                'invested_value' => 1000,
                'created_at' => now(),
            ]);
        }

        $response = $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $profile->id)
            ->getJson('/api/portfolio/snapshots?from_date=2026-03-03&limit=2000');

        $response->assertOk();
        $dates = collect($response->json('snapshots'))->pluck('snapshot_date')->all();
        $this->assertSame(['2026-03-03', '2026-03-04', '2026-03-05'], $dates);
    }

    public function test_snapshots_limit_returns_most_recent_rows(): void
    {
        $user = User::query()->create([
            'name' => 'Snapshot Limit User',
            'email' => 'snap-limit-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        for ($i = 1; $i <= 5; $i++) {
            PortfolioSnapshot::query()->create([
                'profile_id' => $profile->id,
                'snapshot_date' => sprintf('2026-04-%02d', $i),
                'portfolio_value' => 1000 + ($i * 10),
                'invested_value' => 1000,
                'created_at' => now(),
            ]);
        }

        $response = $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $profile->id)
            ->getJson('/api/portfolio/snapshots?limit=2');

        $response->assertOk()
            ->assertJsonPath('meta.count', 2);

        $dates = collect($response->json('snapshots'))->pluck('snapshot_date')->all();
        $this->assertSame(['2026-04-04', '2026-04-05'], $dates);
    }
}
