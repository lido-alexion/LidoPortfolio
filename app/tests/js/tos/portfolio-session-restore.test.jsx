import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import AuthContext from '../../../resources/js/src/context/AuthContext.jsx';
import { PortfolioProvider, usePortfolio } from '../../../resources/js/src/context/PortfolioContext.jsx';
import { apiMock } from './helpers/mockApi.js';
import { TEST_USER } from './fixtures/tosApi.js';

const portfolios = [{ id: 1, name: 'Main', is_default: true }, { id: 2, name: 'Long Term', is_default: false }];
function SelectedPortfolio() {
    const { activePortfolio } = usePortfolio();
    return <output aria-label="Active portfolio">{activePortfolio?.name || 'None'}</output>;
}
function session(loading, authenticated) {
    return <AuthContext.Provider value={{ loading, isAuthenticated: authenticated, user: authenticated ? TEST_USER : null }}>
        <PortfolioProvider><SelectedPortfolio /></PortfolioProvider>
    </AuthContext.Provider>;
}

describe('portfolio selection through session restoration', () => {
    beforeEach(() => apiMock.get.mockResolvedValue({ data: { data: portfolios } }));

    it('keeps the tab selection while authentication is unresolved', () => {
        sessionStorage.setItem('portfolio_active_id', '2');
        render(session(true, false));
        expect(sessionStorage.getItem('portfolio_active_id')).toBe('2');
        expect(apiMock.get).not.toHaveBeenCalled();
    });

    it('restores a valid tab selection instead of replacing it with the account default', async () => {
        sessionStorage.setItem('portfolio_active_id', '2');
        const view = render(session(true, false));
        view.rerender(session(false, true));
        await waitFor(() => expect(screen.getByLabelText('Active portfolio')).toHaveTextContent('Long Term'));
        expect(sessionStorage.getItem('portfolio_active_id')).toBe('2');
    });

    it('clears the prior tab selection once a signed-out session is confirmed', async () => {
        sessionStorage.setItem('portfolio_active_id', '2');
        const view = render(session(true, false));
        view.rerender(session(false, false));
        await waitFor(() => expect(sessionStorage.getItem('portfolio_active_id')).toBeNull());
        expect(screen.getByLabelText('Active portfolio')).toHaveTextContent('None');
        expect(apiMock.get).not.toHaveBeenCalled();
    });

    it('replaces a removed portfolio with the valid default after authentication', async () => {
        sessionStorage.setItem('portfolio_active_id', '99');
        const view = render(session(true, false));
        view.rerender(session(false, true));
        await waitFor(() => expect(screen.getByLabelText('Active portfolio')).toHaveTextContent('Main'));
        expect(sessionStorage.getItem('portfolio_active_id')).toBe('1');
    });
});
