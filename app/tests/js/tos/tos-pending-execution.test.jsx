import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import PendingExecutionPanel from '../../../resources/js/src/components/PendingExecutionPanel.jsx';
import { apiEnvelope, axiosOk, PENDING_BUY_RECOMMENDATION } from './fixtures/tosApi.js';
import { apiMock, axiosError, installDefaultTosHandlers } from './helpers/mockApi.js';

vi.mock('../../../resources/js/src/toast.js', () => ({ showToast: vi.fn() }));
import { showToast } from '../../../resources/js/src/toast.js';

beforeEach(() => showToast.mockReset());

function renderPanel() {
    return render(
        <MemoryRouter>
            <PendingExecutionPanel />
        </MemoryRouter>,
    );
}

const pendingPath = '/v1/recommendations/pending-execution';
const pendingReads = () => apiMock.get.mock.calls.filter(([url]) => url === pendingPath);
const expectReadAttempts = count => {
    const oneAttempt = [pendingPath, '/v1/execution/mode', '/v1/orders'];
    expect(apiMock.get.mock.calls.map(([url]) => url)).toEqual(Array.from({ length: count }, () => oneAttempt).flat());
};
const expectReadOnly = () => {
    expect(apiMock.post).not.toHaveBeenCalled();
    expect(apiMock.put).not.toHaveBeenCalled();
    expect(apiMock.patch).not.toHaveBeenCalled();
    expect(apiMock.delete).not.toHaveBeenCalled();
};
const expectCash = ({ balance, reserved, available }) => {
    const displayedAmount = label => screen.getByText(new RegExp(`^${label}:`)).textContent.replace(/\D/g, '');
    expect(displayedAmount('Cash balance')).toBe(String(balance));
    expect(displayedAmount('Reserved')).toBe(String(reserved));
    expect(displayedAmount('Available')).toBe(String(available));
};
function servePendingResponses(responses) {
    const defaultGet = apiMock.get.getMockImplementation();
    apiMock.get.mockImplementation(async (url, config) => {
        if (url === pendingPath) {
            const next = responses.shift();
            if (!next) throw new Error('Unexpected pending execution read.');
            return next();
        }
        return defaultGet(url, config);
    });
}

describe('Pending Execution TOS smoke', () => {
    it('distinguishes an unavailable initial read from a confirmed empty queue and retries only on request', async () => {
        const user = userEvent.setup();
        installDefaultTosHandlers({ recommendations: [] });
        servePendingResponses([
            () => { throw axiosError('Private server detail', { status: 503 }); },
            () => axiosOk(apiEnvelope([], { cash: { cash_balance: 0, reserved_cash: 0, available_investable_cash: 0 } })),
        ]);
        renderPanel();

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('Pending executions could not be loaded. Retry to check the current list.');
        expect(alert).not.toHaveTextContent('Private server detail');
        expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();
        expect(screen.queryByText(/^No recommendations awaiting execution\./)).not.toBeInTheDocument();
        expect(pendingReads()).toHaveLength(1);

        await user.click(screen.getByRole('button', { name: 'Retry' }));
        expect(await screen.findByText(/^No recommendations awaiting execution\./)).toBeInTheDocument();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(pendingReads()).toHaveLength(2);
        expectReadAttempts(2);
        expectReadOnly();
    });

    it('keeps the last successful rows and cash visible as stale after refresh failure until an explicit recovery', async () => {
        const user = userEvent.setup();
        installDefaultTosHandlers({ recommendations: [PENDING_BUY_RECOMMENDATION] });
        servePendingResponses([
            () => axiosOk(apiEnvelope([PENDING_BUY_RECOMMENDATION], { cash: { cash_balance: 5000, reserved_cash: 1200, available_investable_cash: 3800 } })),
            () => { throw axiosError('Private server detail', { status: 503 }); },
            () => axiosOk(apiEnvelope([
                { ...PENDING_BUY_RECOMMENDATION, id: 802, symbol: 'TCS', name: 'Tata Consultancy Services' },
            ], { cash: { cash_balance: 6000, reserved_cash: 1300, available_investable_cash: 4700 } })),
        ]);
        renderPanel();
        expect(await screen.findByText('INFY')).toBeInTheDocument();
        expectCash({ balance: 5000, reserved: 1200, available: 3800 });

        await user.click(screen.getByRole('button', { name: 'Refresh' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Could not refresh pending executions. Showing the last successfully loaded list; it may be out of date.');
        expect(screen.getByText('INFY')).toBeInTheDocument();
        expectCash({ balance: 5000, reserved: 1200, available: 3800 });
        expect(screen.queryByText(/^No recommendations awaiting execution\./)).not.toBeInTheDocument();
        expect(pendingReads()).toHaveLength(2);

        await user.click(screen.getByRole('button', { name: 'Retry' }));
        expect(await screen.findByText('TCS')).toBeInTheDocument();
        expect(screen.queryByText('INFY')).not.toBeInTheDocument();
        expectCash({ balance: 6000, reserved: 1300, available: 4700 });
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(pendingReads()).toHaveLength(3);
        expectReadAttempts(3);
        expectReadOnly();
    });

    it('does not call a previously empty queue genuinely empty when its refresh fails', async () => {
        const user = userEvent.setup();
        installDefaultTosHandlers({ recommendations: [] });
        servePendingResponses([
            () => axiosOk(apiEnvelope([], { cash: { cash_balance: 0, reserved_cash: 0, available_investable_cash: 0 } })),
            () => { throw axiosError('Private server detail', { status: 503 }); },
            () => axiosOk(apiEnvelope([], { cash: { cash_balance: 0, reserved_cash: 0, available_investable_cash: 0 } })),
        ]);
        renderPanel();
        expect(await screen.findByText(/^No recommendations awaiting execution\./)).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Refresh' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Could not refresh pending executions. Showing the last successfully loaded list; it may be out of date.');
        expect(screen.queryByText(/^No recommendations awaiting execution\./)).not.toBeInTheDocument();
        expect(pendingReads()).toHaveLength(2);

        await user.click(screen.getByRole('button', { name: 'Retry' }));
        expect(await screen.findByText(/^No recommendations awaiting execution\./)).toBeInTheDocument();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(pendingReads()).toHaveLength(3);
        expectReadAttempts(3);
        expectReadOnly();
    });

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
        await user.type(screen.getByLabelText('StoX execution code — Authenticator app'), '123456');
        expect(submit).toBeEnabled();

        await user.click(submit);
        expect(apiMock.post).toHaveBeenCalledWith(
            '/v1/execution/submit-selected',
            { recommendation_ids: [PENDING_BUY_RECOMMENDATION.id], totp: '123456' },
            { skipErrorToast: true },
        );
        expect(showToast).toHaveBeenCalledWith('Submitted to broker', 'success');
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

    it('does not report broker success when every selected row is blocked', async () => {
        const user = userEvent.setup();
        installDefaultTosHandlers({
            recommendations: [PENDING_BUY_RECOMMENDATION],
            executionMode: 'semi_automatic',
            entitled: true,
            totpEnabled: true,
            modeBlockers: [],
            canSubmitSemiAutomatic: true,
            executionSummary: {
                submitted: 0,
                skipped: 0,
                blocked: 1,
                results: [{ outcome: 'blocked', reason: 'BROKER_REJECTED' }],
            },
        });
        renderPanel();
        await screen.findByRole('button', { name: 'Accept / Execute Selected' });
        await user.click(screen.getByRole('checkbox', { name: 'Select INFY' }));
        await user.type(screen.getByLabelText('StoX execution code — Authenticator app'), '123456');
        await user.click(screen.getByRole('button', { name: 'Accept / Execute Selected' }));
        expect(showToast).toHaveBeenCalledWith(expect.stringMatching(/No orders submitted/i), 'warning');
        expect(showToast).not.toHaveBeenCalledWith('Submitted to broker', 'success');
    });

    it('reports submitted, blocked, and skipped counts for mixed results', async () => {
        const user = userEvent.setup();
        installDefaultTosHandlers({
            recommendations: [PENDING_BUY_RECOMMENDATION],
            executionMode: 'semi_automatic',
            entitled: true,
            totpEnabled: true,
            modeBlockers: [],
            canSubmitSemiAutomatic: true,
            executionSummary: {
                submitted: 1,
                skipped: 2,
                blocked: 1,
                results: [{ outcome: 'submitted' }, { outcome: 'blocked' }, { outcome: 'skipped' }],
            },
        });
        renderPanel();
        await screen.findByRole('button', { name: 'Accept / Execute Selected' });
        await user.click(screen.getByRole('checkbox', { name: 'Select INFY' }));
        await user.type(screen.getByLabelText('StoX execution code — Authenticator app'), '123456');
        await user.click(screen.getByRole('button', { name: 'Accept / Execute Selected' }));

        expect(showToast).toHaveBeenCalledWith('Submitted 1; blocked 1; skipped 2.', 'warning');
    });
});
