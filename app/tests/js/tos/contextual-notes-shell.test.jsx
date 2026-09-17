import React from 'react';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter, useLocation, useNavigate } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import RightUtilityRail from '../../../resources/js/src/components/navigation/RightUtilityRail.jsx';
import PortfolioContext from '../../../resources/js/src/context/PortfolioContext.jsx';
import { apiMock } from './helpers/mockApi.js';

const user = { id: 41, is_admin: false };
const portfolioValue = {
    activePortfolio: { id: 7 },
    activePortfolioId: 7,
};

function NavigationProbe() {
    const navigate = useNavigate();
    const location = useLocation();
    return (
        <>
            <button type="button" onClick={() => navigate('/strategies')}>Go strategies</button>
            <output data-testid="route">{location.pathname}</output>
        </>
    );
}

function renderRail({ mobile = false, route = '/holdings' } = {}) {
    const previousMatchMedia = window.matchMedia;
    window.matchMedia = vi.fn((query) => ({
        matches: mobile ? query.includes('max-width') : query.includes('min-width: 1200px'),
        media: query,
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
    }));
    const result = render(
        <MemoryRouter initialEntries={[route]}>
            <PortfolioContext.Provider value={portfolioValue}>
                <RightUtilityRail user={user} />
                <NavigationProbe />
            </PortfolioContext.Provider>
        </MemoryRouter>,
    );
    window.matchMedia = previousMatchMedia;
    return result;
}

afterEach(() => {
    window.matchMedia = vi.fn().mockImplementation((query) => ({
        matches: String(query).includes('min-width: 1200px'),
        media: query,
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
    }));
});

describe('Contextual Notes right utility integration', () => {
    it('opens a non-modal desktop pane and restores focus to the rail utility', async () => {
        apiMock.get.mockResolvedValue({ data: { data: [{ id: 1, body: 'Holdings note' }] } });
        renderRail();

        const trigger = screen.getAllByRole('button', { name: 'Open contextual notes' })
            .find((button) => button.classList.contains('lido-notes-utility-action--desktop'));
        fireEvent.click(trigger);

        const pane = await screen.findByRole('complementary', { name: 'Notes' });
        expect(pane).not.toHaveAttribute('aria-modal');
        expect(screen.getByLabelText('Contextual note')).toHaveValue('Holdings note');
        expect(trigger).toHaveAttribute('aria-expanded', 'true');

        fireEvent.keyDown(screen.getByLabelText('Contextual note'), { key: 'Escape' });
        await waitFor(() => expect(screen.queryByRole('complementary', { name: 'Notes' })).not.toBeInTheDocument());
        expect(document.activeElement).toBe(trigger);
    });

    it('uses a mobile modal sheet with focus containment and trigger restoration', async () => {
        apiMock.get.mockResolvedValue({ data: { data: [] } });
        renderRail({ mobile: true });

        const trigger = screen.getAllByRole('button', { name: 'Open contextual notes' })
            .find((button) => button.classList.contains('lido-notes-utility-action--mobile'));
        fireEvent.click(trigger);
        const dialog = await screen.findByRole('dialog', { name: 'Notes' });
        const close = within(dialog).getByRole('button', { name: 'Close notes' });
        const save = within(dialog).getByRole('button', { name: /Save/ });
        expect(document.activeElement).toBe(close);

        save.focus();
        fireEvent.keyDown(save, { key: 'Tab' });
        expect(document.activeElement).toBe(close);

        close.focus();
        fireEvent.keyDown(close, { key: 'Tab', shiftKey: true });
        expect(document.activeElement).toBe(save);

        fireEvent.keyDown(close, { key: 'Escape' });
        await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Notes' })).not.toBeInTheDocument());
        expect(document.activeElement).toBe(trigger);
    });

    it('allows only one mobile utility sheet at a time', async () => {
        apiMock.get.mockResolvedValue({ data: { data: [] } });
        renderRail({ mobile: true });

        fireEvent.click(screen.getAllByRole('button', { name: 'Open contextual notes' })
            .find((button) => button.classList.contains('lido-notes-utility-action--mobile')));
        expect(await screen.findByRole('dialog', { name: 'Notes' })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Open page history' }));
        expect(screen.queryByRole('dialog', { name: 'Notes' })).not.toBeInTheDocument();
        expect(screen.getByRole('dialog', { name: 'History' })).toBeInTheDocument();
    });

    it('reloads the current context after navigation without retaining the old note', async () => {
        apiMock.get.mockImplementation(async (url, config) => ({
            data: {
                data: [{
                    id: 1,
                    body: config.params.context_key === 'holdings' ? 'Holdings note' : 'Strategies note',
                }],
            },
        }));
        renderRail();

        fireEvent.click(screen.getAllByRole('button', { name: 'Open contextual notes' })
            .find((button) => button.classList.contains('lido-notes-utility-action--desktop')));
        const editor = await screen.findByLabelText('Contextual note');
        await waitFor(() => expect(editor).toHaveValue('Holdings note'));

        fireEvent.click(screen.getByRole('button', { name: 'Go strategies' }));
        await waitFor(() => expect(screen.getByTestId('route')).toHaveTextContent('/strategies'));
        await waitFor(() => expect(editor).toHaveValue('Strategies note'));
        expect(editor).not.toHaveValue('Holdings note');
    });
});
