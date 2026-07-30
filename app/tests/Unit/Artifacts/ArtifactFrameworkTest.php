<?php

namespace Tests\Unit\Artifacts;

use App\Services\Artifacts\ArtifactEnvelope;
use App\Services\Artifacts\ArtifactType;
use App\Services\Artifacts\ArtifactValidationService;
use App\Services\Artifacts\DefinitionHasher;
use App\Services\Artifacts\IndicatorArtifactRegistry;
use App\Services\Indicators\IndicatorRegistryFactory;
use PHPUnit\Framework\TestCase;

class ArtifactFrameworkTest extends TestCase
{
    public function test_definition_hasher_is_stable(): void
    {
        $a = DefinitionHasher::hash(['b' => 1, 'a' => 2]);
        $b = DefinitionHasher::hash(['a' => 2, 'b' => 1]);
        $this->assertSame($a, $b);
        $this->assertStringStartsWith('sha256:', $a);
    }

    public function test_indicator_registry_lists_envelopes(): void
    {
        $indicators = (new IndicatorRegistryFactory)->make();
        $registry = new IndicatorArtifactRegistry(
            $indicators,
            new ArtifactValidationService($indicators),
        );
        $list = $registry->list();
        $this->assertNotEmpty($list);
        $rsi = $registry->get('rsi');
        $this->assertNotNull($rsi);
        $this->assertSame(ArtifactType::INDICATOR, $rsi['artifact_type']);
        $this->assertSame('rsi', $rsi['slug']);
        $this->assertSame('rsi', $rsi['definition']['registry_id']);
        $this->assertArrayHasKey('definition_hash', $rsi);
    }

    public function test_screener_envelope_validation(): void
    {
        $indicators = (new IndicatorRegistryFactory)->make();
        $validator = new ArtifactValidationService($indicators);
        $envelope = ArtifactEnvelope::make(
            ArtifactType::SCREENER,
            'test_liq',
            'Test',
            [
                'root' => [
                    'type' => 'group',
                    'op' => 'AND',
                    'children' => [
                        [
                            'type' => 'condition',
                            'left' => ['indicator' => 'close', 'params' => []],
                            'operator' => 'gt',
                            'weight_factor' => 1,
                            'right' => ['type' => 'constant', 'value' => 0],
                        ],
                    ],
                ],
            ],
            ['scope' => 'portfolio', 'status' => 'draft', 'origin' => 'user'],
        );
        $result = $validator->validateEnvelope($envelope);
        $this->assertTrue($result->ok, json_encode($result->toArray()));
    }

    public function test_rejects_unknown_screener_indicator(): void
    {
        $indicators = (new IndicatorRegistryFactory)->make();
        $validator = new ArtifactValidationService($indicators);
        $envelope = ArtifactEnvelope::make(
            ArtifactType::SCREENER,
            'bad',
            'Bad',
            [
                'root' => [
                    'type' => 'condition',
                    'left' => ['indicator' => 'not_a_real_indicator', 'params' => []],
                    'operator' => 'gt',
                    'weight_factor' => 1,
                    'right' => ['type' => 'constant', 'value' => 1],
                ],
            ],
            ['scope' => 'portfolio', 'status' => 'draft', 'origin' => 'user'],
        );
        $result = $validator->validateEnvelope($envelope);
        $this->assertFalse($result->ok);
    }

    public function test_rejects_code_fields_on_indicator(): void
    {
        $indicators = (new IndicatorRegistryFactory)->make();
        $validator = new ArtifactValidationService($indicators);
        $envelope = ArtifactEnvelope::make(
            ArtifactType::INDICATOR,
            'x',
            'X',
            [
                'registry_id' => 'x',
                'indicator_kind' => 'primary',
                'registry_category' => 'price',
                'definition_version' => '1.0.0',
                'parameters' => [],
                'depends_on' => [],
                'capabilities' => [],
                'formula' => 'close * 2',
            ],
            ['scope' => 'portfolio', 'status' => 'draft', 'origin' => 'user'],
        );
        $result = $validator->validateEnvelope($envelope);
        $this->assertFalse($result->ok);
    }
}
