<?php

namespace Tests\Unit;

use App\Services\AI\AssistantStreamProjection;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

class AssistantStreamProjectionTest extends TestCase
{
    public function test_private_fields_are_removed_from_every_event(): void
    {
        $projection = new AssistantStreamProjection;
        $private = ['provider' => 'secret-provider', 'model' => 'secret-model', 'routing_trace' => ['secret'], 'error_message' => 'secret-error', 'path_id' => 'secret-path'];
        $stream = '';
        foreach (['message.start', 'message.delta', 'usage', 'message.completed', 'error'] as $event) {
            $stream .= 'event: '.$event."\ndata: ".json_encode($private + ['text' => 'Supported answer', 'provenance' => [['url' => 'javascript:alert(1)', 'title' => 'unsafe']]])."\n\n";
        }
        $output = implode('', iterator_to_array($projection->events(Utils::streamFor($stream))));
        self::assertStringNotContainsString('secret', $output);
        self::assertStringNotContainsString('javascript', $output);
        self::assertStringNotContainsString('event: usage', $output);
        self::assertStringContainsString('Supported answer', $output);
        self::assertStringContainsString('assistant_unavailable', $output);
    }
}
