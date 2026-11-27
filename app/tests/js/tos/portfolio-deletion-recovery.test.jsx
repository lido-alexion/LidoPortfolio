import React from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import AuthContext from '../../../resources/js/src/context/AuthContext';
import { PortfolioProvider } from '../../../resources/js/src/context/PortfolioContext';
import PortfolioSwitcher from '../../../resources/js/src/components/PortfolioSwitcher';

vi.mock('../../../resources/js/src/api', () => ({ default: { get: vi.fn() } }));

const channels = [];

class TestBroadcastChannel {
    listeners = new Set();

    constructor(name) {
        this.name = name;
        channels.push(this);
    }

    addEventListener(_type, listener) { this.listeners.add(listener); }
    removeEventListener(_type, listener) { this.listeners.delete(listener); }
    postMessage() {}

    send(data) {
        for (const listener of this.listeners) listener({ data });
    }
}

beforeEach(() => {
    cleanup();
    vi.resetAllMocks();
    sessionStorage.clear();
    sessionStorage.setItem('portfolio_active_id', '2');
    channels.length = 0;
    vi.stubGlobal('BroadcastChannel', TestBroadcastChannel);
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

it('clears a deleted active tab selection and recovers to the user default', async () => {
    let portfolios = [
        { id: 1, name: 'Main', is_default: true, portfolio_type: 'paper' },
        { id: 2, name: 'Research', is_default: false, portfolio_type: 'paper' },
    ];
    api.get.mockImplementation(async (path) => {
        if (path !== '/portfolios') throw new Error(`Unexpected GET ${path}`);
        return { data: { data: portfolios } };
    });

    render(
        <MemoryRouter>
            <AuthContext.Provider value={{
                loading: false,
                isAuthenticated: true,
                user: { id: 7, default_portfolio_id: 1 },
            }}>
                <PortfolioProvider><PortfolioSwitcher /></PortfolioProvider>
            </AuthContext.Provider>
        </MemoryRouter>,
    );

    expect(await screen.findByText('Research · PAPER')).toBeVisible();
    portfolios = [portfolios[0]];

    await act(async () => {
        channels[0].send({ type: 'deleted', portfolioId: '2', userId: '8' });
    });
    expect(sessionStorage.getItem('portfolio_active_id')).toBe('2');
    expect(api.get).toHaveBeenCalledTimes(1);

    await act(async () => {
        channels[0].send({ type: 'deleted', portfolioId: '2', userId: '7' });
    });

    await waitFor(() => expect(sessionStorage.getItem('portfolio_active_id')).toBe('1'));
    await waitFor(() => expect(screen.queryByTitle('Switch portfolio (per tab)')).toBeNull());
    expect(api.get).toHaveBeenCalledTimes(2);
});
