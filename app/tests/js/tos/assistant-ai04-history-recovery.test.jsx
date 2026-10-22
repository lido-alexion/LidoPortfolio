import React from 'react';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import AssistantDrawer from '../../../resources/js/src/components/AssistantDrawer';
import api from '../../../resources/js/src/api';

vi.mock('../../../resources/js/src/api', () => ({ default: { get: vi.fn(), post: vi.fn() } }));

const showModalDescriptor = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'showModal');
const closeDescriptor = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'close');

const objective = 'How concentrated are my holdings, and which prices are unavailable?';
const savedRun = {
    id: 'dc5f0b8c-ece8-4d17-a1fb-2a2b3b889e01',
    profile_id: 1,
    objective,
    status: 'completed',
    plan: [],
    preview: [],
    steps: [],
    answer: 'The largest holding is 18% of the portfolio. Prices for two holdings are unavailable, so the price evidence is incomplete.',
    trace: [
        { tool: 'portfolio.holdings', status: 'available', result_summary: '8 holdings' },
        { tool: 'portfolio.concentration', status: 'available', result_summary: 'Largest holding: 18%' },
        { tool: 'market.prices', status: 'partial', result_summary: '2 prices unavailable' },
    ],
};

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}

function requestFailure(status) {
    return Object.assign(new Error(`Request failed with status code ${status}`), {
        response: { status, data: { success: false, error: { code: 'backend_unavailable' } } },
    });
}

beforeEach(() => {
    vi.clearAllMocks();
    HTMLDialogElement.prototype.showModal = function showModal() { this.open = true; };
    HTMLDialogElement.prototype.close = function close() { this.open = false; };
});

afterEach(() => {
    if (showModalDescriptor) {
        Object.defineProperty(HTMLDialogElement.prototype, 'showModal', showModalDescriptor);
    } else {
        delete HTMLDialogElement.prototype.showModal;
    }
    if (closeDescriptor) {
        Object.defineProperty(HTMLDialogElement.prototype, 'close', closeDescriptor);
    } else {
        delete HTMLDialogElement.prototype.close;
    }
    vi.restoreAllMocks();
});

it('recovers an uncertain account investigation from saved read-only run history without resubmitting', async () => {
    const investigation = deferred();
    const firstHistoryRead = deferred();
    api.post.mockReturnValueOnce(investigation.promise);
    api.get.mockReturnValueOnce(firstHistoryRead.promise).mockResolvedValueOnce({ data: { success: true, data: [savedRun] } });

    render(<MemoryRouter><AssistantDrawer /></MemoryRouter>);
    fireEvent.click(screen.getByRole('button', { name: 'Open StoX assistant' }));
    fireEvent.change(screen.getByLabelText('Assistant mode'), { target: { value: 'account' } });
    fireEvent.change(screen.getByLabelText('Ask about StoX'), { target: { value: objective } });
    fireEvent.click(screen.getByRole('button', { name: 'Ask', exact: true }));

    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1));
    expect(screen.getByText('Investigating account evidence…')).toBeInTheDocument();
    investigation.reject(requestFailure(503));
    expect(await screen.findByText('The investigation could not finish. Check run history for its current state.')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Run history' }));
    await waitFor(() => expect(api.get).toHaveBeenCalledTimes(1));
    firstHistoryRead.reject(requestFailure(503));
    expect(await screen.findByText('Run history is temporarily unavailable.')).toBeInTheDocument();
    expect(screen.queryByText('The investigation could not finish. Check run history for its current state.')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Run history' }));
    const runArticle = await screen.findByRole('article', { name: 'Assistant run' });
    expect(runArticle).toHaveTextContent(objective);
    expect(runArticle).toHaveTextContent('Completed');
    expect(runArticle).toHaveTextContent('The largest holding is 18% of the portfolio.');
    expect(runArticle).toHaveTextContent('Prices for two holdings are unavailable');
    expect(screen.getByText('Run history is temporarily unavailable.')).toBeInTheDocument();

    fireEvent.click(within(runArticle).getByText('Investigation trace'));
    expect(within(runArticle).getByText('portfolio holdings — available')).toBeInTheDocument();
    expect(within(runArticle).getByText('portfolio concentration — available')).toBeInTheDocument();
    expect(within(runArticle).getByText('market prices — partial')).toBeInTheDocument();
    expect(within(runArticle).queryByRole('region', { name: 'Mutation preview' })).not.toBeInTheDocument();
    expect(within(runArticle).queryByRole('button', { name: 'Approve changes' })).not.toBeInTheDocument();
    expect(within(runArticle).queryByRole('button', { name: 'Reject plan' })).not.toBeInTheDocument();
    expect(within(runArticle).queryByRole('list', { name: 'Action results' })).not.toBeInTheDocument();

    expect(api.post).toHaveBeenCalledTimes(1);
    expect(api.post).toHaveBeenCalledWith('/ai/assistant/runs', { objective }, expect.objectContaining({ signal: expect.any(AbortSignal) }));
    expect(api.get).toHaveBeenCalledTimes(2);
    expect(api.get).toHaveBeenNthCalledWith(1, '/ai/assistant/runs');
    expect(api.get).toHaveBeenNthCalledWith(2, '/ai/assistant/runs');
});
