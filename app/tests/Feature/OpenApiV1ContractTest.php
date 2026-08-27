<?php

namespace Tests\Feature;

use App\Support\OpenApi\V1DocumentBuilder;
use Tests\TestCase;

class OpenApiV1ContractTest extends TestCase
{
    public function test_canonical_document_exists_parses_and_is_openapi_3_0(): void
    {
        $path = app(V1DocumentBuilder::class)->canonicalPath();
        $this->assertFileExists($path);

        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);
        $spec = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertSame('Stox /api/v1', $spec['info']['title']);
        $this->assertIsArray($spec['paths']);
        $this->assertNotEmpty($spec['paths']);
        $this->assertArrayHasKey('sanctumCookie', $spec['components']['securitySchemes']);
        $this->assertSame('cookie', $spec['components']['securitySchemes']['sanctumCookie']['in']);
        $this->assertSame('laravel_session', $spec['components']['securitySchemes']['sanctumCookie']['name']);
        $this->assertArrayHasKey('XProfileId', $spec['components']['parameters']);
        $this->assertArrayHasKey('EnvelopeSuccess', $spec['components']['schemas']);
        $this->assertArrayHasKey('EnvelopeError', $spec['components']['schemas']);
        $this->assertArrayHasKey('DatasetStatusData', $spec['components']['schemas']);
        app(V1DocumentBuilder::class)->assertValidDocument($spec);
    }

    public function test_document_matches_generated_spec_from_live_routes(): void
    {
        $builder = app(V1DocumentBuilder::class);
        $path = $builder->canonicalPath();
        $this->assertSame(
            $builder->encode($builder->build()),
            file_get_contents($path),
            'app/openapi/v1.json is stale; run php artisan openapi:v1',
        );
    }

    public function test_every_api_v1_route_is_documented_and_no_other_api_paths_appear(): void
    {
        $builder = app(V1DocumentBuilder::class);
        $spec = json_decode(file_get_contents($builder->canonicalPath()), true, 512, JSON_THROW_ON_ERROR);

        $fromLaravel = $builder->laravelOperationKeys();
        $fromSpec = $builder->specOperationKeys($spec);

        $this->assertNotEmpty($fromLaravel);
        $this->assertSame($fromLaravel, $fromSpec);

        foreach ($fromSpec as $key) {
            $this->assertMatchesRegularExpression('#^(GET|POST|PUT|PATCH|DELETE) /api/v1/#', $key);
            $this->assertStringNotContainsString('/api/auth/', $key);
        }

        foreach (array_keys($spec['paths']) as $path) {
            $this->assertStringStartsWith('/api/v1/', $path);
            $this->assertStringNotContainsString('/api/v2/', $path);
        }
    }

    public function test_all_v1_operations_require_sanctum_and_document_admin_flag(): void
    {
        $builder = app(V1DocumentBuilder::class);
        $spec = json_decode(file_get_contents($builder->canonicalPath()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([['sanctumCookie' => []]], $spec['security']);

        $adminKeys = [];
        foreach ($builder->v1Routes() as $route) {
            if ($route['admin']) {
                $adminKeys[] = $route['key'];
            }
            $this->assertContains('auth:sanctum', $route['middleware']);
            $this->assertContains('active.portfolio', $route['middleware']);
        }

        foreach ($adminKeys as $key) {
            [$method, $path] = explode(' ', $key, 2);
            $op = $spec['paths'][$path][strtolower($method)];
            $this->assertTrue($op['x-stox-admin'] ?? false, $key.' should be marked admin');
            $this->assertArrayHasKey('403', $op['responses']);
        }
    }

    public function test_important_v4_contracts_are_represented(): void
    {
        $spec = json_decode(file_get_contents(app(V1DocumentBuilder::class)->canonicalPath()), true, 512, JSON_THROW_ON_ERROR);

        $dataset = $spec['paths']['/api/v1/dataset/status']['get'];
        $this->assertSame('#/components/schemas/EnvelopeDatasetStatus', $dataset['responses']['200']['content']['application/json']['schema']['$ref']);
        $this->assertArrayHasKey('dataset_version', $spec['components']['schemas']['DatasetStatusData']['properties']);
        $this->assertStringContainsString('version_key', $spec['components']['schemas']['DatasetStatusData']['properties']['dataset_version']['description']);

        $pipeline = $spec['paths']['/api/v1/pipeline/run']['post'];
        $this->assertArrayHasKey('422', $pipeline['responses']);
        $this->assertStringContainsString('DATASET_NOT_FRESH', json_encode($pipeline['responses']['422']));
        $this->assertStringContainsString('freshness', $pipeline['description']);

        $eval = $spec['paths']['/api/v1/evaluation/runs']['post'];
        $this->assertStringContainsString('EvaluationParameterResolver', $eval['description']);
        $this->assertStringContainsString('market_regime', $eval['description']);

        $execute = $spec['paths']['/api/v1/orders/{id}/execute']['post'];
        $this->assertStringContainsString('markExecuted', $execute['description']);
        $this->assertArrayHasKey('price', $execute['requestBody']['content']['application/json']['schema']['properties']);

        $review = $spec['paths']['/api/v1/recommendations/{id}/review']['post'];
        $this->assertContains('approved', $review['requestBody']['content']['application/json']['schema']['properties']['decision']['enum']);
    }

    public function test_openapi_v1_artisan_check_passes(): void
    {
        $this->artisan('openapi:v1', ['--check' => true])->assertSuccessful();
    }

    public function test_operation_overlays_only_reference_live_v1_routes(): void
    {
        $builder = app(V1DocumentBuilder::class);
        $live = array_flip($builder->laravelOperationKeys());
        foreach (array_keys(\App\Support\OpenApi\V1OperationOverlays::all()) as $key) {
            $this->assertArrayHasKey($key, $live, 'Overlay key is not a live /api/v1 route: '.$key);
        }
    }
}
