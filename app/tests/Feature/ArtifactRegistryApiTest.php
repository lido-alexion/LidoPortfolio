<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Artifacts\ArtifactType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtifactRegistryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_artifacts(): void
    {
        $this->getJson('/api/v1/artifacts')->assertUnauthorized();
    }

    public function test_user_can_list_and_get_indicators(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->defaultPortfolioFor($user);

        $this->actingAs($user)
            ->getJson('/api/v1/artifacts/indicator')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($user)
            ->getJson('/api/v1/artifacts/indicator/rsi')
            ->assertOk()
            ->assertJsonPath('data.slug', 'rsi')
            ->assertJsonPath('data.artifact_type', ArtifactType::INDICATOR);
    }

    public function test_validate_screener_envelope(): void
    {
        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);

        $payload = [
            'schema_version' => '1.0',
            'artifact_type' => 'screener',
            'slug' => 'tmp',
            'name' => 'Tmp',
            'artifact_version' => 1,
            'metadata' => [
                'scope' => 'portfolio',
                'status' => 'draft',
                'origin' => 'user',
                'description' => 'test',
            ],
            'definition' => [
                'root' => [
                    'type' => 'group',
                    'op' => 'AND',
                    'children' => [
                        [
                            'type' => 'condition',
                            'left' => ['indicator' => 'close', 'params' => []],
                            'operator' => 'gt',
                            'weight_factor' => 1,
                            'right' => ['type' => 'constant', 'value' => 10],
                        ],
                    ],
                ],
            ],
            'dependencies' => [],
        ];

        $this->actingAs($user)
            ->postJson('/api/v1/artifacts/screener/validate', $payload)
            ->assertOk()
            ->assertJsonPath('data.ok', true);
    }

    public function test_non_admin_cannot_create_indicator_draft(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->defaultPortfolioFor($user);

        $this->actingAs($user)
            ->postJson('/api/v1/artifacts/indicator', [
                'slug' => 'my_draft',
                'name' => 'My Draft',
                'metadata' => ['scope' => 'portfolio', 'status' => 'draft', 'origin' => 'user'],
                'definition' => [
                    'registry_id' => 'my_draft',
                    'indicator_kind' => 'metric',
                    'registry_category' => 'descriptive',
                    'definition_version' => '0.1.0',
                    'parameters' => [],
                    'depends_on' => [],
                    'capabilities' => [],
                ],
            ])
            ->assertForbidden();
    }

    public function test_admin_can_create_indicator_draft(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->defaultPortfolioFor($admin);

        $this->actingAs($admin)
            ->postJson('/api/v1/artifacts/indicator', [
                'slug' => 'my_draft',
                'name' => 'My Draft',
                'metadata' => ['scope' => 'portfolio', 'status' => 'draft', 'origin' => 'user'],
                'definition' => [
                    'registry_id' => 'my_draft',
                    'indicator_kind' => 'metric',
                    'registry_category' => 'descriptive',
                    'definition_version' => '0.1.0',
                    'parameters' => [],
                    'depends_on' => [],
                    'capabilities' => [],
                    'status' => 'planned',
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'my_draft')
            ->assertJsonPath('data.metadata.storage', 'draft');
    }
}
