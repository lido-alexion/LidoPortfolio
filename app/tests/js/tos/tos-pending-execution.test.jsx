import React from 'react';
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import PendingExecutionPanel from '../../../resources/js/src/components/PendingExecutionPanel.jsx';
import { PENDING_BUY_RECOMMENDATION } from './fixtures/tosApi.js';
import { apiMock, installDefaultTosHandlers } from './helpers/mockApi.js';

function renderPanel() {
    return render(
        <MemoryRouter>
            <PendingExecutionPanel />
        </MemoryRouter>,
    );
}

describe('Pending Execution TOS smoke', () => {
    it('manual mode records a ledger fill and does not show broker submit', async () => {
        installDefaultTosHandlers({
            recommendations: [PENDING_BUY_RECOMMENDATION],
            executionMode: 'manual',
        });
        renderPanel();

        expect(await screen.findByRole('button', { name: 'Execute manually' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Accept / Execute Selected' })).not.toBeInTheDocument();
        expect(screen.getByText('INFY')).toBeInTheDocument();
    });

    it('semi-automatic requires an explicit Accept / Execute Selected action', async () => {
        const user = userEvent.setup();
        installDefaultTosHandlers({
            recommendations: [PENDING_BUY_RECOMMENDATION],
            executionMode: 'semi_automatic',
            entitled: true,
            totpEnabled: true,
            modeBlockers: [],
            canSubmitSemiAutomatic: true,
        });
        renderPanel();

        const submit = await screen.findByRole('button', { name: 'Accept / Execute Selected' });
        expect(submit).toBeDisabled();

        await user.click(screen.getByRole('checkbox', { name: 'Select INFY' }));
        await user.type(screen.getByLabelText('Authenticator code'), '123456');
        expect(submit).toBeEnabled();

        await user.click(submit);
        expect(apiMock.post).toHaveBeenCalledWith(
            '/v1/execution/submit-selected',
            { recommendation_ids: [PENDING_BUY_RECOMMENDATION.id], totp: '123456' },
            { skipErrorToast: true },
        );
    });

    it('automatic mode does not require a per-order broker submit control', async () => {
        installDefaultTosHandlers({
            recommendations: [PENDING_BUY_RECOMMENDATION],
            executionMode: 'automatic',
            entitled: true,
            totpEnabled: true,
            modeBlockers: [],
            canSubmitAutomatic: true,
        });
        renderPanel();

        expect(await screen.findByText(/without a per-order confirmation/i)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Accept / Execute Selected' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Execute manually' })).toBeInTheDocument();
    });
});
