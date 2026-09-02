import { describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axiosError, installDefaultTosHandlers } from './helpers/mockApi.js';
import { renderTosApp } from './helpers/renderTosApp.jsx';
import { HOLD_INSIGHT, OPEN_BUY_RECOMMENDATION, WATCH_INSIGHT } from './fixtures/tosApi.js';

describe('Recommendations TOS smoke', () => {
    it('shows loading then representative API data', async () => {
        installDefaultTosHandlers({
            recommendations: [OPEN_BUY_RECOMMENDATION, HOLD_INSIGHT, WATCH_INSIGHT],
            delayRecommendationsMs: 40,
        });

        renderTosApp({ route: '/recommendations' });

        expect(await screen.findByText('Loading…')).toBeInTheDocument();
        expect(await screen.findByText('INFY')).toBeInTheDocument();
        expect(screen.getByText('Infosys Limited')).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Trade recommendations' })).toBeInTheDocument();
        expect(screen.queryByText('TCS')).not.toBeInTheDocument();
        expect(screen.getByText('RADICO')).toBeInTheDocument();
        expect(screen.getByText('No position → Watch')).toBeInTheDocument();
        expect(screen.getByText('Minervini Strategy')).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Market insights' })).toBeInTheDocument();
        expect(screen.getByText(/1 HOLD record is hidden/)).toBeInTheDocument();
        await userEvent.click(screen.getByRole('checkbox', { name: 'Show HOLD insights' }));
        expect(screen.getByText('TCS')).toBeInTheDocument();
        expect(screen.getByText('12.50% · Hold')).toBeInTheDocument();
        expect(screen.getAllByText('informational')).toHaveLength(2);
        expect(screen.queryByText('published')).not.toBeInTheDocument();
        expect(screen.queryByText('Loading…')).not.toBeInTheDocument();
    });

    it('shows the empty trade-recommendation state', async () => {
        installDefaultTosHandlers({ recommendations: [] });
        renderTosApp({ route: '/recommendations' });

        expect(await screen.findByText(/No trade recommendations/i)).toBeInTheDocument();
        expect(screen.getByText('No insights right now.')).toBeInTheDocument();
    });

    it('surfaces an API failure as a toast and does not crash', async () => {
        installDefaultTosHandlers({ failRecommendations: true });
        renderTosApp({ route: '/recommendations' });

        expect(await screen.findByRole('alert')).toHaveTextContent(/Recommendations unavailable|Failed to load recommendations/i);
        expect(screen.getAllByRole('heading', { name: 'Recommendations' }).length).toBeGreaterThan(0);
        expect(screen.getByText(/No trade recommendations/i)).toBeInTheDocument();
    });

    it('opens review and can approve without crashing', async () => {
        const user = userEvent.setup();
        installDefaultTosHandlers({ recommendations: [OPEN_BUY_RECOMMENDATION] });
        renderTosApp({ route: '/recommendations' });

        expect(await screen.findByText('INFY')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Review', exact: true }));

        const dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByText(/INFY/)).toBeInTheDocument();
        expect(within(dialog).getByRole('button', { name: 'Approve' })).toBeInTheDocument();

        await user.click(within(dialog).getByRole('button', { name: 'Approve' }));

        await waitFor(() => {
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        });
        expect(await screen.findByText(/No trade recommendations/i)).toBeInTheDocument();
        expect(screen.getByRole('alert')).toHaveTextContent(/Pending Execution/i);
    });

    it('shows a pipeline freshness error from POST /api/v1/pipeline/run', async () => {
        const user = userEvent.setup();
        installDefaultTosHandlers({
            recommendations: [],
            pipelineError: axiosError('Market dataset is not within the allowed freshness window.', {
                status: 422,
                body: {
                    success: false,
                    error: {
                        code: 'DATASET_NOT_FRESH',
                        message: 'Market dataset is not within the allowed freshness window.',
                    },
                },
            }),
        });
        renderTosApp({ route: '/recommendations' });

        await screen.findByRole('button', { name: 'Run decision pipeline' });
        await user.click(screen.getByRole('button', { name: 'Run decision pipeline' }));

        expect(await screen.findByRole('alert')).toHaveTextContent(/not within the allowed freshness window/i);
        expect(screen.getAllByRole('heading', { name: 'Recommendations' }).length).toBeGreaterThan(0);
    });
});
