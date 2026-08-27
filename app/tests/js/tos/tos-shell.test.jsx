import { describe, expect, it } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { installDefaultTosHandlers } from './helpers/mockApi.js';
import { renderSessionRestore, renderTosApp } from './helpers/renderTosApp.jsx';

describe('TOS primary shell', () => {
    it('shows the session-restore chrome while auth is loading', () => {
        renderSessionRestore();
        expect(screen.getByText('Restoring your session…')).toBeInTheDocument();
        expect(screen.getByRole('status')).toBeInTheDocument();
    });

    it('renders authenticated Trading OS chrome and Recommendations', async () => {
        installDefaultTosHandlers({ recommendations: [] });
        renderTosApp({ route: '/recommendations' });

        expect(await screen.findByRole('button', { name: 'Run decision pipeline' })).toBeInTheDocument();
        expect(screen.getAllByRole('heading', { name: 'Recommendations' }).length).toBeGreaterThan(0);
        expect(screen.getByRole('link', { name: 'StoX by Lido Alexion' })).toBeInTheDocument();
        await waitFor(() => {
            expect(screen.getAllByRole('link', { name: 'Recommendations' }).length).toBeGreaterThan(0);
        });
    });
});
