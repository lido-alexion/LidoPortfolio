import React from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import RightUtilityRail from '../../../resources/js/src/components/navigation/RightUtilityRail.jsx';
import {
    addPageVisit,
    getPageVisitDescriptor,
    pageHistoryStorageKey,
    readPageVisitHistory,
} from '../../../resources/js/src/utils/pageVisitHistory.js';

const user = { id: 41, is_admin: false };

function visit(pathname) {
    return getPageVisitDescriptor(pathname, user);
}

function LocationProbe() {
    const location = useLocation();
    return <output data-testid="location">{location.pathname}</output>;
}

function renderRail(initialEntries = ['/']) {
    return render(
        <MemoryRouter initialEntries={initialEntries}>
            <RightUtilityRail user={user} />
            <LocationProbe />
        </MemoryRouter>,
    );
}

describe('page visit history semantics', () => {
    it('records MRU visits, collapses only consecutive duplicates, and keeps twelve entries', () => {
        let history = [];
        history = addPageVisit(history, visit('/'));
        history = addPageVisit(history, visit('/'));
        history = addPageVisit(history, visit('/holdings'));
        history = addPageVisit(history, visit('/'));

        expect(history.map((item) => item.visitKey)).toEqual(['/', '/holdings', '/']);

        for (let index = 0; index < 12; index += 1) {
            history = addPageVisit(history, visit(`/backtests/${index}`));
        }
        expect(history).toHaveLength(12);
        expect(history[0].visitKey).toBe('/backtests/11');
        expect(history.at(-1).visitKey).toBe('/backtests/0');
    });

    it('normalizes query/hash variants and excludes redirect, public, documentation, and admin paths', () => {
        expect(visit('/holdings/?sort=name#table').visitKey).toBe('/holdings');
        expect(getPageVisitDescriptor('/evaluations', user)).toBeNull();
        expect(getPageVisitDescriptor('/documentation', user)).toBeNull();
        expect(getPageVisitDescriptor('/settings/users', user)).toBeNull();
        expect(getPageVisitDescriptor('/settings/users', { id: 1, is_admin: true })).toBeNull();
        expect(getPageVisitDescriptor('/wiki/shared/token', user)).toBeNull();
    });

    it('loads only valid, current-schema records from the user-specific session key', () => {
        window.sessionStorage.setItem(pageHistoryStorageKey(41), JSON.stringify({
            version: 1,
            visits: [visit('/holdings'), { visitKey: '/bad' }, visit('/backtests/4')],
        }));
        expect(readPageVisitHistory(window.sessionStorage, 41).map((item) => item.visitKey))
            .toEqual(['/holdings', '/backtests/4']);
        expect(readPageVisitHistory(window.sessionStorage, 42)).toEqual([]);

        window.sessionStorage.setItem(pageHistoryStorageKey(41), '{broken');
        expect(readPageVisitHistory(window.sessionStorage, 41)).toEqual([]);

        const unavailableStorage = {
            getItem: () => { throw new Error('blocked'); },
            setItem: () => { throw new Error('blocked'); },
        };
        expect(readPageVisitHistory(unavailableStorage, 41)).toEqual([]);
    });
});

describe('page history rail', () => {
    it('renders accessible current links and navigates through React Router', async () => {
        window.sessionStorage.setItem(pageHistoryStorageKey(41), JSON.stringify({
            version: 1,
            visits: [visit('/'), visit('/holdings')],
        }));
        const { container } = renderRail(['/holdings']);

        await waitFor(() => expect(screen.getAllByRole('link', { name: 'Holdings' }).length).toBeGreaterThan(0));
        expect(screen.getAllByRole('link', { name: 'Holdings' })[0]).toHaveAttribute('aria-current', 'page');

        const dashboard = screen.getByRole('link', { name: 'Dashboard' });
        fireEvent.click(dashboard);
        expect(screen.getByTestId('location')).toHaveTextContent('/');
        expect(container.querySelector('.lido-page-history-rail')).toBeInTheDocument();
    });

    it('opens the mobile sheet, closes on Escape, and restores focus to the trigger', async () => {
        renderRail(['/holdings']);
        const trigger = await screen.findByRole('button', { name: 'Open page history' });
        fireEvent.click(trigger);
        expect(screen.getByRole('dialog', { name: 'History' })).toBeInTheDocument();
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(screen.queryByRole('dialog', { name: 'History' })).not.toBeInTheDocument();
        await act(async () => {});
        expect(document.activeElement).toBe(trigger);
    });
});
