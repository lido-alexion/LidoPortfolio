<?php

namespace Tests\Unit;

use App\Engines\Strategy\MinerviniTrendTemplateScreener;
use App\Services\Screener\ScreenerDefinitionValidator;
use Tests\TestCase;

class MinerviniTrendTemplateScreenerTest extends TestCase
{
    public function test_definition_validates(): void
    {
        $definition = MinerviniTrendTemplateScreener::definition();
        $validator = app(ScreenerDefinitionValidator::class);
        // Should not throw
        $validator->validate($definition);
        $this->assertSame('group', $definition['root']['type']);
        $this->assertSame('AND', $definition['root']['op']);
        $this->assertGreaterThanOrEqual(5, count($definition['root']['children']));
    }
}
