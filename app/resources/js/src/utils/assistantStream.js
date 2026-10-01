import { appUrl } from '../appBase';
import { ensureCsrfCookie, getRequestCsrfToken, isPlainCsrfToken } from '../auth/csrf';

export async function streamAssistant(input, signal, onEvent) {
    await ensureCsrfCookie();
    const response = await fetch(appUrl('/api/ai/assistant/stream'), {
        method: 'POST', credentials: 'same-origin', signal,
        headers: { Accept: 'text/event-stream', 'Content-Type': 'application/json',
            [isPlainCsrfToken() ? 'X-CSRF-TOKEN' : 'X-XSRF-TOKEN']: getRequestCsrfToken() || '' },
        body: JSON.stringify(input),
    });
    if (!response.ok || !response.body) throw new Error('Assistant unavailable');
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = ''; let completed = false;
    try {
        while (true) {
            const { value, done } = await reader.read();
            if (done) break;
            buffer += decoder.decode(value, { stream: true }).replace(/\r/g, '');
            let end;
            while ((end = buffer.indexOf('\n\n')) >= 0) {
                const frame = buffer.slice(0, end); buffer = buffer.slice(end + 2);
                const type = frame.match(/^event:\s*(.+)$/m)?.[1];
                const data = JSON.parse(frame.split('\n').filter(line => line.startsWith('data:')).map(line => line.slice(5).trim()).join('\n'));
                if (type === 'message.completed' || type === 'error') completed = true;
                onEvent(type, data);
            }
        }
        if (!completed) throw new Error('Stream interrupted');
    } finally { await reader.cancel(); reader.releaseLock(); }
}

export function safeSourceUrl(url) {
    return /^\/docs\/(?:journeys\/)?[a-zA-Z0-9_-]+\.html(?:#[a-zA-Z0-9_-]+)?$/.test(url || '') ? appUrl(url) : null;
}
