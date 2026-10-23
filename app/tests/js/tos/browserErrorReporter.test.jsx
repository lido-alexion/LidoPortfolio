import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

let listenerSpy;

beforeEach(() => {
    listenerSpy = vi.spyOn(window, 'addEventListener');
});

async function loadReporter() {
    vi.resetModules();
    return import('../../../resources/js/src/telemetry/browserErrorReporter');
}

function dispatchRejection(reason) {
    const event = new Event('unhandledrejection');
    Object.defineProperty(event, 'reason', { value: reason });
    window.dispatchEvent(event);
}

afterEach(() => {
    for (const [type, listener, options] of listenerSpy.mock.calls) {
        if (type === 'error' || type === 'unhandledrejection') window.removeEventListener(type, listener, options);
    }
    listenerSpy.mockRestore();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    document.querySelector('meta[name="app-base"]')?.remove();
});

describe('browser runtime error reporter', () => {
    it('drains distinct typed faults with base-aware URL and CSRF, without client evidence', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(10_000);
        document.head.insertAdjacentHTML('beforeend', '<meta name="app-base" content="/portfolio">');
        document.cookie = 'XSRF-TOKEN=csrf%2Dsafe';
        const fetch = vi.fn().mockResolvedValue({ status: 202 });
        vi.stubGlobal('fetch', fetch);

        const reporter = await loadReporter();
        expect(reporter.registerBrowserErrorReporter()).toBe(true);
        expect(reporter.registerBrowserErrorReporter()).toBe(false);
        reporter.reportReactRenderFailure();
        reporter.reportReactRenderFailure(); // bounded duplicate
        window.dispatchEvent(new ErrorEvent('error', {
            error: new Error('Private message with Bearer secret'),
            filename: `${window.location.origin}/build/assets/app.js`,
        }));
        dispatchRejection(new Error('Private rejected value'));
        await vi.advanceTimersByTimeAsync(250);

        expect(fetch).toHaveBeenCalledTimes(3);
        expect(fetch.mock.calls.map(([url]) => url)).toEqual(Array(3).fill('/portfolio/api/ops/browser-errors'));
        const types = fetch.mock.calls.map(([, options]) => JSON.parse(options.body).type).sort();
        expect(types).toEqual(['react_render', 'uncaught_exception', 'unhandled_rejection']);
        for (const [, options] of fetch.mock.calls) {
            expect(options.headers['X-XSRF-TOKEN']).toBe('csrf-safe');
            expect(options.headers['X-StoX-Skip-Api-Failure-Reporting']).toBe('1');
            expect(options.body).not.toMatch(/Private|Bearer|secret|pathname|Alice/);
        }
    });

    it('ignores resource, cross-origin, cancellation, auth/validation and unknown rejection noise', async () => {
        vi.useFakeTimers();
        const fetch = vi.fn().mockResolvedValue({ status: 401 });
        vi.stubGlobal('fetch', fetch);
        const reporter = await loadReporter();
        reporter.registerBrowserErrorReporter();
        window.dispatchEvent(new Event('error'));
        const resource = document.createElement('img');
        document.body.append(resource);
        resource.dispatchEvent(new ErrorEvent('error', {
            bubbles: true, error: new Error('resource'),
            filename: `${window.location.origin}/build/assets/app.js`,
        }));
        resource.remove();
        window.dispatchEvent(new ErrorEvent('error', {
            error: new Error('external'), filename: 'chrome-extension://private-id/file.js',
        }));
        dispatchRejection('unknown prose');
        dispatchRejection(Object.assign(new Error('cancel'), { code: 'ERR_CANCELED' }));
        dispatchRejection(Object.assign(new Error('security'), { name: 'SecurityError' }));
        dispatchRejection(Object.assign(new Error('auth'), { response: { status: 401 } }));
        dispatchRejection(Object.assign(new Error('validation'), { response: { status: 422 } }));
        dispatchRejection(Object.assign(new Error('request'), { isAxiosError: true }));
        dispatchRejection(Object.assign(new Error('extension'), { stack: 'at fn (moz-extension://private-id/file.js:1:1)' }));
        await vi.advanceTimersByTimeAsync(500);

        expect(fetch).not.toHaveBeenCalled();

        reporter.reportReactRenderFailure();
        await vi.advanceTimersByTimeAsync(250);
        expect(fetch).toHaveBeenCalledTimes(1); // 401 is dropped, with no retry or recursive log
        await vi.advanceTimersByTimeAsync(60_000);
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('bounds and drains a queued burst when transport rejects', async () => {
        vi.useFakeTimers();
        const fetch = vi.fn().mockRejectedValue(new Error('network private detail'));
        vi.stubGlobal('fetch', fetch);
        const reporter = await loadReporter();
        reporter.registerBrowserErrorReporter();
        reporter.reportReactRenderFailure();
        window.dispatchEvent(new ErrorEvent('error', {
            error: new Error('first'), filename: `${window.location.origin}/build/assets/app.js`,
        }));
        dispatchRejection(new Error('second'));
        reporter.reportReactRenderFailure();
        await vi.advanceTimersByTimeAsync(250);
        expect(fetch).toHaveBeenCalledTimes(3);
        await vi.advanceTimersByTimeAsync(5_000);
        expect(fetch).toHaveBeenCalledTimes(3); // bounded queue; no retry storm
    });
});
