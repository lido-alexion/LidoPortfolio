import { afterEach, expect, it, vi } from 'vitest';

afterEach(() => vi.unstubAllGlobals());

it('delivers portfolio deletion broadcasts only to the matching account', async () => {
    const channels = [];
    vi.stubGlobal('BroadcastChannel', class {
        listeners = new Set();
        constructor(name) { this.name = name; channels.push(this); }
        addEventListener(_name, listener) { this.listeners.add(listener); }
        removeEventListener(_name, listener) { this.listeners.delete(listener); }
        postMessage(data) { this.lastMessage = data; }
    });

    const events = await import('../../../resources/js/src/utils/portfolioEvents');
    const received = [];
    const unsubscribe = events.subscribePortfolioCrossTab(7, (message) => received.push(message));
    const [channel] = channels;

    for (const listener of channel.listeners) {
        listener({ data: { type: 'deleted', portfolioId: '3', userId: '8' } });
        listener({ data: { type: 'deleted', portfolioId: '4', userId: '7' } });
    }

    expect(received).toHaveLength(1);
    expect(received[0].portfolioId).toBe('4');
    unsubscribe();
});
