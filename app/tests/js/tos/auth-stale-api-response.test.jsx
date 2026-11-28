import { afterEach, expect, it, vi } from 'vitest';
import axios from 'axios';
vi.unmock('../../../resources/js/src/api');
const { default: api } = await vi.importActual('../../../resources/js/src/api');
import { setAuthSessionUserId } from '../../../resources/js/src/auth/sessionContext';
import * as csrf from '../../../resources/js/src/auth/csrf';

afterEach(() => {
    setAuthSessionUserId(null);
    vi.restoreAllMocks();
});

it.each([401, 419])('does not run stale %s response recovery or retry after identity changes', async (status) => {
    setAuthSessionUserId(7);
    const resetCsrf = vi.spyOn(csrf, 'resetCsrfCookie');
    const unauthorized = vi.fn();
    window.addEventListener('portfolio-unauthorized', unauthorized);
    let attempts = 0;

    const request = api.get('/portfolio/stale-check', {
        adapter: async (config) => {
            attempts += 1;
            setAuthSessionUserId(8);
            throw new axios.AxiosError('stale response', undefined, config, null, {
                status,
                data: { message: 'expired session' },
                headers: {},
                config,
            });
        },
    });

    await expect(request).rejects.toMatchObject({ code: 'ERR_CANCELED' });
    expect(attempts).toBe(1);
    expect(resetCsrf).not.toHaveBeenCalled();
    expect(unauthorized).not.toHaveBeenCalled();
    window.removeEventListener('portfolio-unauthorized', unauthorized);
});
