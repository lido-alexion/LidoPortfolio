<?php

namespace Tests\Feature\Backtest;

use App\Models\BacktestRun;
use App\Models\ReusableArtifactVersion;
use App\Models\User;
use App\Services\Artifacts\ArtifactType;
use App\Services\Artifacts\ArtifactValidationService;
use App\Services\Artifacts\ReusableArtifactLifecycleService;
use App\Services\Artifacts\StrategyParameterSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class V5BacktestParameterOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_declared_typed_bounded_parameters_can_be_overridden(): void
    {
        $schema = app(StrategyParameterSchema::class);
        $envelope = $this->envelope();

        $modified = $schema->apply($envelope, ['open_score' => 91]);
        $this->assertSame(91, $modified['definition']['thresholds']['open_position']);
        $this->assertSame(85, $envelope['definition']['thresholds']['open_position']);

        foreach ([['unknown' => 1], ['open_score' => '91'], ['open_score' => 101]] as $invalid) {
            try {
                $schema->apply($envelope, $invalid);
                $this->fail('Invalid override was accepted.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_publication_rejects_a_declaration_that_does_not_target_definition(): void
    {
        $envelope = $this->envelope();
        $envelope['configurable_parameters'][0]['path'] = 'thresholds.missing';
        $result = app(ArtifactValidationService::class)->validateEnvelope($envelope);

        $this->assertFalse($result->ok);
        $this->assertContains('STRATEGY_CONFIGURABLE_PARAMETER_SCHEMA', array_column($result->toArray()['errors'], 'code'));
    }

    public function test_completed_modified_backtest_creates_unpublished_next_draft_with_provenance(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $published = $lifecycle->publish(
            $lifecycle->createDraft($user, ArtifactType::STRATEGY, 'parameterized', 'Parameterized', $this->envelope()),
            $user,
        );
        $run = BacktestRun::query()->create([
            'profile_id' => $profile->id, 'user_id' => $user->id,
            'reusable_artifact_version_id' => $published->id,
            'name' => 'Modified run', 'range_key' => '1y', 'from_date' => '2024-01-01', 'to_date' => '2024-12-31',
            'initial_capital' => 100000, 'status' => BacktestRun::STATUS_COMPLETED, 'stage' => BacktestRun::STAGE_COMPLETED,
            'execution_assumptions_json' => ['parameter_overrides' => ['open_score' => 92], 'parameters_modified' => true],
        ]);

        $data = $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson("/api/v1/backtests/{$run->id}/strategy-draft")
            ->assertOk()->assertJsonPath('data.status', ReusableArtifactVersion::STATUS_DRAFT)
            ->assertJsonPath('data.semver', '1.0.1')->json('data');

        $draft = ReusableArtifactVersion::query()->findOrFail($data['draft_version_id']);
        $this->assertSame(92, $draft->content_json['definition']['thresholds']['open_position']);
        $this->assertSame($run->id, $draft->content_json['metadata']['backtest_provenance']['backtest_run_id']);
        $this->assertSame(85, $published->fresh()->content_json['definition']['thresholds']['open_position']);
    }

    /** @return array<string,mixed> */
    private function envelope(): array
    {
        return [
            'schema_version' => '1.0', 'artifact_type' => 'strategy', 'slug' => 'parameterized', 'name' => 'Parameterized',
            'metadata' => ['scope' => 'account', 'status' => 'draft', 'origin' => 'user'],
            'configurable_parameters' => [[
                'key' => 'open_score', 'path' => 'thresholds.open_position', 'type' => 'integer',
                'minimum' => 0, 'maximum' => 100, 'label' => 'Open score',
            ]],
            'definition' => [
                'scoring_model' => [['key' => 'momentum_score', 'enabled' => true, 'weight' => 100, 'parameters' => []]],
                'eligibility_sources' => [], 'thresholds' => ['open_position' => 85],
            ],
        ];
    }
}
