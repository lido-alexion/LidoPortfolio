<?php

namespace App\Services\AI;

use Psr\Http\Message\StreamInterface;

/** Fail-closed projection of private runtime SSE onto the browser contract. */
class AssistantStreamProjection
{
    public function events(StreamInterface $body): \Generator
    {
        $buffer = '';
        while (! $body->eof()) {
            $buffer .= str_replace("\r", '', $body->read(8192));
            if (strlen($buffer) > 1048576) { throw new \RuntimeException('Oversized runtime event'); }
            while (($end = strpos($buffer, "\n\n")) !== false) {
                $frame = substr($buffer, 0, $end);
                $buffer = substr($buffer, $end + 2);
                $event = ''; $lines = [];
                foreach (explode("\n", $frame) as $line) {
                    if (str_starts_with($line, 'event:')) { $event = trim(substr($line, 6)); }
                    if (str_starts_with($line, 'data:')) { $lines[] = trim(substr($line, 5)); }
                }
                $data = json_decode(implode("\n", $lines), true);
                if (! is_array($data)) { continue; }
                $safe = $this->project($event, $data);
                if ($safe !== null) { yield 'event: '.$event."\ndata: ".json_encode($safe, JSON_THROW_ON_ERROR)."\n\n"; }
            }
        }
        if (trim($buffer) !== '') { throw new \RuntimeException('Incomplete runtime event'); }
    }

    public function project(string $event, array $data): ?array
    {
        if ($event === 'message.start') { return []; }
        if ($event === 'message.delta') { return ['text' => (string) ($data['text'] ?? '')]; }
        if (! in_array($event, ['error', 'message.completed'], true)) { return null; }
        $sources = [];
        foreach (array_slice($data['provenance'] ?? [], 0, 5) as $source) {
            if (! is_array($source) || ! preg_match('~^/docs/(?:journeys/)?[a-zA-Z0-9_-]+\.html(?:#[a-zA-Z0-9_-]+)?$~D', $source['url'] ?? '')) { continue; }
            $sources[] = array_filter(array_intersect_key($source, array_flip(['source_id', 'title', 'section', 'snippet', 'url'])), fn ($value) => is_string($value));
        }
        return [
            'request_id' => (string) ($data['request_id'] ?? ''),
            'status' => $event === 'error' ? 'failure' : 'success',
            'grounding' => $event === 'error' ? 'insufficient' : 'grounded',
            'provenance' => $sources,
            'code' => $event === 'error' ? (in_array($data['error_code'] ?? '', ['grounding_insufficient', 'read_only_scope'], true) ? $data['error_code'] : 'assistant_unavailable') : null,
        ];
    }
}
