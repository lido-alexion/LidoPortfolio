import { describe, expect, it } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { installDefaultTosHandlers } from './helpers/mockApi.js';
import { renderTosApp } from './helpers/renderTosApp.jsx';
import { DISCOVERY_CANDIDATE } from './fixtures/tosApi.js';

describe('Discovery TOS smoke', () => {
    it('renders Discovery with representative candidate data', async () => {
        installDefaultTosHandlers({ candidates: [DISCOVERY_CANDIDATE] });
        renderTosApp({ route: '/candidates' });

        expect(await screen.findByRole('button', { name: 'Run discovery' })).toBeInTheDocument();
        expect(screen.getAllByRole('heading', { name: 'Discovery' }).length).toBeGreaterThan(0);
        expect(await screen.findByText('INFY')).toBeInTheDocument();
        expect(screen.getByText('Infosys Limited')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Run discovery' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Evidence' })).toBeInTheDocument();
        expect(screen.getByRole('region', { name: 'Default screener' })).toHaveTextContent('Minervini Trend Template');
        expect(screen.getByText('12 matched / 500 scanned')).toBeInTheDocument();
    });

    it('runs the default screener inline and keeps Discovery available', async () => {
        const user = userEvent.setup();
        installDefaultTosHandlers({ candidates: [DISCOVERY_CANDIDATE] });
        renderTosApp({ route: '/candidates' });

        await user.click(await screen.findByRole('button', { name: 'Run default screener' }));

        expect(await screen.findByRole('alert')).toHaveTextContent(/14 match\(es\) \/ 500 scanned/i);
        expect(screen.getByRole('button', { name: 'Run discovery' })).toBeInTheDocument();
    });

    it('shows a recovery action when no default screener exists', async () => {
        installDefaultTosHandlers({ candidates: [], screeners: [] });
        renderTosApp({ route: '/candidates' });

        expect(await screen.findByText('Default screener unavailable')).toBeInTheDocument();
        expect(screen.getAllByRole('link', { name: 'Open Screeners' }).length).toBeGreaterThan(0);
    });

    it('shows the empty candidate state', async () => {
        installDefaultTosHandlers({ candidates: [] });
        renderTosApp({ route: '/candidates' });

        expect(await screen.findByText(/No candidates yet/i)).toBeInTheDocument();
    });

    it('shows loading then candidate data', async () => {
        installDefaultTosHandlers({
            candidates: [DISCOVERY_CANDIDATE],
            delayCandidatesMs: 80,
        });
        renderTosApp({ route: '/candidates' });

        expect(screen.getByText('Loading…')).toBeInTheDocument();
        expect(await screen.findByText('INFY')).toBeInTheDocument();
        expect(screen.queryByText('Loading…')).not.toBeInTheDocument();
    });

    it('surfaces a candidates API failure as a toast and does not crash', async () => {
        installDefaultTosHandlers({ failCandidates: true });
        renderTosApp({ route: '/candidates' });

        expect(await screen.findByRole('alert')).toHaveTextContent(/Candidates unavailable|Failed to load candidates/i);
        expect(screen.getAllByRole('heading', { name: 'Discovery' }).length).toBeGreaterThan(0);
        expect(screen.getByText(/No candidates yet/i)).toBeInTheDocument();
    });

    it('opens candidate evidence without crashing', async () => {
        const user = userEvent.setup();
        installDefaultTosHandlers({ candidates: [DISCOVERY_CANDIDATE] });
        renderTosApp({ route: '/candidates' });

        await screen.findByText('INFY');
        await user.click(screen.getByRole('button', { name: 'Evidence' }));
        expect(await screen.findByText(/discovery evidence/i)).toBeInTheDocument();
    });
});
