import { expect, it, vi } from 'vitest';
import { streamAssistant, safeSourceUrl } from '../../../resources/js/src/utils/assistantStream';

it('parses split SSE frames, sends bounded input through Laravel and rejects incomplete streams', async () => {
    const text = 'event: message.delta\ndata: {"text":"Grounded text"}\n\nevent: message.completed\ndata: {"grounding":"grounded"}\n\n';
    const events = [];
    const mockResponse = value => ({ ok: true, body: new ReadableStream({ start(controller) { const bytes = new TextEncoder().encode(value); controller.enqueue(bytes.slice(0, 27)); controller.enqueue(bytes.slice(27)); controller.close(); } }) });
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(mockResponse(text));
    try {
        await streamAssistant({ question: 'Help' }, new AbortController().signal, (type, data) => events.push([type, data]));
        expect(fetchMock.mock.calls[0][0]).toBe('/api/ai/assistant/stream');
        expect(events[0]).toEqual(['message.delta', { text: 'Grounded text' }]);
        expect(events[1][0]).toBe('message.completed');
        fetchMock.mockResolvedValue(mockResponse('event: message.delta\ndata: {"text":"unfinished"}\n\n'));
        await expect(streamAssistant({ question: 'Help' }, undefined, () => {})).rejects.toThrow('Stream interrupted');
    } finally { fetchMock.mockRestore(); }
});

it('allows only local maintained documentation links', () => {
    expect(safeSourceUrl('/docs/journeys/06-assistant.html#ai-01')).toBe('/docs/journeys/06-assistant.html#ai-01');
    for (const url of ['https://evil.example', '//evil.example', 'javascript:alert(1)', '/api/delete', '/docs/../settings']) expect(safeSourceUrl(url)).toBeNull();
});
