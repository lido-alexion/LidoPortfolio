import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import GlobalSearch from '../../../resources/js/src/components/GlobalSearch.jsx';
import { apiMock } from './helpers/mockApi.js';
import { TEST_USER } from './fixtures/tosApi.js';

function LocationProbe() {
    const location = useLocation();
    return <output data-testid="location">{location.pathname}</output>;
}

function renderSearch(user = TEST_USER, route = '/') {
    return render(
        <MemoryRouter initialEntries={[route]}>
            <GlobalSearch user={user} />
            <Routes><Route path="*" element={<LocationProbe />} /></Routes>
        </MemoryRouter>,
    );
}

function desktopMatchMedia() {
    window.matchMedia = vi.fn().mockImplementation(() => ({
        matches: false,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    }));
}

function mobileMatchMedia() {
    window.matchMedia = vi.fn().mockImplementation(() => ({
        matches: true,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    }));
}

describe('Global Search', () => {
    it('uses role-filtered navigation metadata and ranks exact/prefix/substring matches', async () => {
        desktopMatchMedia();
        const user = userEvent.setup();
        renderSearch();

        await user.click(screen.getByRole('button', { name: 'Open global search' }));
        const input = screen.getByRole('searchbox', { name: 'Search pages or stocks' });
        await user.type(input, 'hold');

        expect(await screen.findByRole('link', { name: 'Holdings, Portfolio' })).toBeInTheDocument();
        expect(screen.queryByText('Administration')).not.toBeInTheDocument();
    });

    it('does not render for Admin users', () => {
        desktopMatchMedia();
        renderSearch({ ...TEST_USER, is_admin: true });
        expect(screen.queryByRole('button', { name: 'Open global search' })).not.toBeInTheDocument();
    });

    it('debounces stock lookup and navigates to the existing stock-price route', async () => {
        desktopMatchMedia();
        const user = userEvent.setup();
        apiMock.get.mockResolvedValue({ data: { data: [{ id: 7, symbol: 'INFY', name: 'Infosys Limited', exchange: 'NSE' }] } });
        renderSearch();

        await user.click(screen.getByRole('button', { name: 'Open global search' }));
        const input = screen.getByRole('searchbox', { name: 'Search pages or stocks' });
        await user.type(input, 'I');
        await new Promise((resolve) => setTimeout(resolve, 350));
        expect(apiMock.get).not.toHaveBeenCalled();
        await user.type(input, 'NF');

        const result = await screen.findByRole('link', { name: /INFY, Infosys Limited, NSE/ });
        expect(apiMock.get).toHaveBeenCalledWith('/stocks/search', {
            params: { q: 'inf', limit: 20 },
            skipErrorToast: true,
        });
        await user.click(result);
        expect(screen.getByTestId('location')).toHaveTextContent('/holdings/7/prices');
    });

    it('ignores an older stock response after the query changes', async () => {
        desktopMatchMedia();
        const user = userEvent.setup();
        let resolveFirst;
        let resolveSecond;
        apiMock.get
            .mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve; }))
            .mockImplementationOnce(() => new Promise((resolve) => { resolveSecond = resolve; }));
        renderSearch();

        await user.click(screen.getByRole('button', { name: 'Open global search' }));
        const input = screen.getByRole('searchbox', { name: 'Search pages or stocks' });
        await user.type(input, 'INF');
        await waitFor(() => expect(apiMock.get).toHaveBeenCalledTimes(1));
        await user.type(input, 'Y');
        await waitFor(() => expect(apiMock.get).toHaveBeenCalledTimes(2));

        resolveFirst({ data: { data: [{ id: 1, symbol: 'INF', name: 'Old result', exchange: 'NSE' }] } });
        await new Promise((resolve) => setTimeout(resolve, 20));
        expect(screen.queryByText('Old result')).not.toBeInTheDocument();

        resolveSecond({ data: { data: [{ id: 7, symbol: 'INFY', name: 'Current result', exchange: 'NSE' }] } });
        expect(await screen.findByRole('link', { name: /INFY, Current result, NSE/ })).toBeInTheDocument();
    });

    it('keeps page results when stock lookup fails', async () => {
        desktopMatchMedia();
        const user = userEvent.setup();
        apiMock.get.mockRejectedValue(new Error('provider unavailable'));
        renderSearch();

        await user.click(screen.getByRole('button', { name: 'Open global search' }));
        await user.type(screen.getByRole('searchbox', { name: 'Search pages or stocks' }), 'hold');

        expect(await screen.findByRole('link', { name: 'Holdings, Portfolio' })).toBeInTheDocument();
        await waitFor(() => expect(screen.getByText(/Stock search is temporarily unavailable/i)).toBeInTheDocument());
    });

    it('supports keyboard navigation, Escape, and focus restoration on desktop', async () => {
        desktopMatchMedia();
        const user = userEvent.setup();
        renderSearch();

        const trigger = screen.getByRole('button', { name: 'Open global search' });
        await user.click(trigger);
        const input = screen.getByRole('searchbox', { name: 'Search pages or stocks' });
        expect(input).toHaveFocus();
        await user.type(input, 'hold');
        await screen.findByRole('link', { name: 'Holdings, Portfolio' });
        await user.keyboard('{ArrowDown}{Enter}');
        expect(screen.getByTestId('location')).toHaveTextContent('/holdings');

        await user.click(trigger);
        expect(screen.getByRole('searchbox', { name: 'Search pages or stocks' })).toHaveFocus();
        await user.keyboard('{Escape}');
        expect(trigger).toHaveFocus();
        expect(screen.queryByRole('searchbox', { name: 'Search pages or stocks' })).not.toBeInTheDocument();
    });

    it('uses a modal mobile surface with focus containment and restoration', async () => {
        mobileMatchMedia();
        const user = userEvent.setup();
        renderSearch();

        const trigger = screen.getByRole('button', { name: 'Open global search' });
        await user.click(trigger);
        const dialog = screen.getByRole('dialog', { name: 'Search StoX' });
        const input = screen.getByRole('searchbox', { name: 'Search pages or stocks' });
        expect(dialog).toBeInTheDocument();
        expect(input).toHaveFocus();

        const close = screen.getByRole('button', { name: 'Close search' });
        fireEvent.keyDown(document, { key: 'Tab' });
        expect(document.activeElement).toBe(close);
        fireEvent.keyDown(document, { key: 'Tab', shiftKey: true });
        expect(document.activeElement).toBe(input);
        await user.keyboard('{Escape}');
        expect(trigger).toHaveFocus();
    });
});
