import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { apiMock } from './helpers/mockApi.js';
import PortfolioReplayPanel from '../../../resources/js/src/components/portfolio/PortfolioReplayPanel.jsx';

const readiness = {
    status: 'ready',
    limitations: [],
    pinned_world: {
        binding_revisions: [{
            binding_id: 4,
            strategy_id: 9,
            strategy_name: 'Momentum Strategy',
            artifact_version_id: 12,
            historical_artifact_version_id: 12,
            selected_artifact_version_id: 12,
            is_counterfactual: false,
        }],
        counterfactual: { is_counterfactual: false, overrides: [] },
    },
    counterfactual_options: {
        '4': [
            { artifact_version_id: 12, semver: '1.0.0', name: 'Momentum Strategy', is_historical: true },
            { artifact_version_id: 13, semver: '2.0.0', name: 'Momentum Strategy', is_historical: false },
        ],
    },
};

describe('Portfolio Replay counterfactual version selection', () => {
    it('submits an explicit published version override for a historical binding', async () => {
        const user = userEvent.setup();
        apiMock.get.mockResolvedValue({ data: { data: [] } });
        apiMock.post.mockResolvedValue({ data: { data: readiness } });
        render(<PortfolioReplayPanel portfolio={{ id: 1, name: 'Live Portfolio' }} />);

        await user.selectOptions(screen.getAllByRole('combobox')[0], 'historical_branch');
        await user.click(screen.getByRole('button', { name: 'Check' }));
        const selector = await screen.findByLabelText('Version for Momentum Strategy');
        await user.selectOptions(selector, '13');
        await user.click(screen.getByRole('button', { name: 'Check' }));

        await waitFor(() => expect(apiMock.post).toHaveBeenLastCalledWith('/replays/readiness', expect.objectContaining({
            starting_mode: 'historical_branch',
            strategy_version_overrides: { 4: 13 },
        })));
    });

    it('discloses historical and selected versions in Replay detail', async () => {
        apiMock.get.mockImplementation(async (url) => url === '/replays'
            ? { data: { data: [{ id: 7, created_at: '2026-09-18T00:00:00Z', period_start: '2026-01-02', period_end: '2026-01-02', starting_mode: 'historical_branch', status: 'queued', checkpoint_date: null, price_method: 'next_open', adverse_slippage_percent: 0 }] } }
            : { data: { data: {
                id: 7,
                status: 'queued',
                price_method: 'next_open',
                adverse_slippage_percent: 0,
                pinned_world: {
                    counterfactual: {
                        is_counterfactual: true,
                        overrides: [{ binding_id: 4, strategy_name: 'Momentum Strategy', historical_semver: '1.0.0', selected_semver: '2.0.0' }],
                    },
                },
                evidence_summary: {},
            } } });
        render(<PortfolioReplayPanel portfolio={{ id: 1, name: 'Live Portfolio' }} />);
        await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith('/replays'));
        fireEvent.click(screen.getByRole('button', { name: 'Details' }));

        expect(await screen.findByText('Counterfactual Strategy versions')).toBeInTheDocument();
        expect(screen.getByText(/historical\s+1\.0\.0\s+-\> used\s+2\.0\.0/)).toBeInTheDocument();
    });
});
