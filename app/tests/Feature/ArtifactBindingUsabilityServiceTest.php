<?php

namespace Tests\Feature;

use App\Models\ArtifactBinding;
use App\Models\NotificationSource;
use App\Models\User;
use App\Services\Artifacts\ArtifactBindingService;
use App\Services\Artifacts\ArtifactBindingUsabilityService;
use App\Services\Artifacts\ArtifactType;
use App\Services\Artifacts\ReusableArtifactLifecycleService;
use App\Services\Indicators\IndicatorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtifactBindingUsabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_binding_blocks_and_publishes_one_active_action_required_condition(): void
    {
        [$owner, $binding] = $this->boundIndicatorArtifact();
        $this->app->instance(IndicatorRegistry::class, new IndicatorRegistry);
        $service = app(ArtifactBindingUsabilityService::class);

        $first = $service->refresh($binding);
        $second = $service->refresh($binding);

        $this->assertSame(ArtifactBinding::BLOCKED, $first->usability_state);
        $this->assertSame($first->usability_reasons_json, $second->usability_reasons_json);
        $source = NotificationSource::query()->where('notification_type', 'artifact.binding_blocked')->sole();
        $this->assertSame('action_required', $source->severity);
        $this->assertSame('active', $source->condition_state);
        $this->assertSame(2, $source->occurrence_count);
        $this->assertSame([$owner->id], $source->recipients->pluck('user_id')->all());
    }

    public function test_recovery_resolves_condition_and_disabled_binding_does_not_raise_one(): void
    {
        [, $binding] = $this->boundIndicatorArtifact();
        $originalRegistry = app(IndicatorRegistry::class);
        $this->app->instance(IndicatorRegistry::class, new IndicatorRegistry);
        app(ArtifactBindingUsabilityService::class)->refresh($binding);

        $this->app->instance(IndicatorRegistry::class, $originalRegistry);
        $recovered = app(ArtifactBindingUsabilityService::class)->refresh($binding);
        $this->assertSame(ArtifactBinding::USABLE, $recovered->usability_state);
        $this->assertSame('resolved', NotificationSource::query()->sole()->condition_state);

        $recovered->forceFill(['status' => ArtifactBinding::STATUS_DISABLED])->save();
        $this->app->instance(IndicatorRegistry::class, new IndicatorRegistry);
        app(ArtifactBindingUsabilityService::class)->refresh($recovered);
        $this->assertSame(1, NotificationSource::query()->count());
    }

    public function test_refresh_command_reports_binding_counts(): void
    {
        $this->boundIndicatorArtifact();

        $this->artisan('portfolio:refresh-artifact-binding-usability')
            ->expectsOutput('Artifact bindings: 1 checked; 1 usable; 0 warning; 0 blocked.')
            ->assertSuccessful();
    }

    /** @return array{0:User,1:ArtifactBinding} */
    private function boundIndicatorArtifact(): array
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $version = $lifecycle->publish(
            $lifecycle->createDraft($owner, ArtifactType::SCREENER, 'momentum', 'Momentum', $this->envelope()),
            $owner,
            [['kind' => 'uses_indicator', 'indicator_id' => 'rsi', 'indicator_version' => '1.0.0']],
        );
        $binding = app(ArtifactBindingService::class)->bind($profile, $version, $owner, [], true);

        return [$owner, $binding];
    }

    /** @return array<string, mixed> */
    private function envelope(): array
    {
        return [
            'schema_version' => '1.0',
            'artifact_type' => 'screener',
            'slug' => 'momentum',
            'name' => 'Momentum',
            'metadata' => ['scope' => 'account', 'status' => 'draft', 'origin' => 'user'],
            'definition' => [
                'root' => [
                    'type' => 'condition',
                    'left' => ['indicator' => 'rsi', 'params' => ['period' => 14]],
                    'operator' => 'gte',
                    'right' => ['type' => 'constant', 'value' => 50],
                ],
            ],
        ];
    }
}
