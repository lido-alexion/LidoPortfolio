import React from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import { showToast } from '../../../resources/js/src/toast';
import ClosedTransactionsPage from '../../../resources/js/src/pages/ClosedTransactionsPage.jsx';
import { EvaluationHistory } from '../../../resources/js/src/pages/CandidatesPage.jsx';
vi.mock('../../../resources/js/src/api', async original => ({ ...(await original()), default: { get: vi.fn(), delete: vi.fn() } }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
vi.mock('../../../resources/js/src/utils/transactionTableColumns', () => ({ buildTransactionTableColumns: options => [options] }));
vi.mock('../../../resources/js/src/components/DataTable', () => ({ DataTableCard: ({ data, columns, emptyMessage, headerExtra }) => <section aria-label="Stored transaction rows">{headerExtra}{data.length ? data.map(row => <div key={row.id}>{row.stock.symbol}<button onClick={() => columns[0].onDelete(row.id)}>Delete recorded transaction</button></div>) : <span>{emptyMessage}</span>}</section> }));
vi.mock('../../../resources/js/src/components/TablePagination', () => ({ default: () => null }));
const rejection = () => Promise.reject({ response: { status: 503, data: { message: 'Synthetic history unavailable.' } } });
const payload = { data: { data: [{ id: 91, stock: { symbol: 'TCS' } }], current_page: 1, last_page: 1, total: 1, from: 1, to: 1 } };
beforeEach(() => { vi.clearAllMocks(); vi.spyOn(window, 'confirm').mockReturnValue(true); });
it('catches a failed initial closed-history read without reporting no transactions and permits retry', async () => {
 api.get.mockImplementationOnce(rejection).mockResolvedValue(payload); render(<MemoryRouter><ClosedTransactionsPage /></MemoryRouter>);
 await screen.findByText(/Squared-off transactions could not be loaded/); expect(screen.queryByText('No squared-off transactions yet.')).toBeNull(); fireEvent.click(screen.getByRole('button', { name: 'Retry squared-off transactions' })); await screen.findByText('TCS'); expect(api.delete).not.toHaveBeenCalled();
});
it('retains stored evidence and catches an unconfirmed delete without a success notification', async () => {
 api.get.mockResolvedValue(payload); api.delete.mockImplementation(rejection); render(<MemoryRouter><ClosedTransactionsPage /></MemoryRouter>); await screen.findByText('TCS'); fireEvent.click(screen.getByRole('button', { name: 'Delete recorded transaction' })); await screen.findByText(/Transaction deletion could not be confirmed/); expect(screen.getByText('TCS')).toBeInTheDocument(); expect(showToast).not.toHaveBeenCalledWith('Transaction deleted'); expect(api.get).toHaveBeenCalledTimes(1);
});
it('distinguishes failed evaluation history from an authoritative zero-run result', () => {
 const onRetryRuns = vi.fn(); render(<EvaluationHistory runs={[]} loading={false} selectedRunId={null} onSelect={vi.fn()} results={[]} resultsLoading={false} runsError={new Error('unavailable')} onRetryRuns={onRetryRuns} />);
 expect(screen.queryByText('No evaluation runs have been recorded yet.')).toBeNull(); fireEvent.click(screen.getByRole('button', { name: 'Retry evaluation history' })); expect(onRetryRuns).toHaveBeenCalledTimes(1);
});
it('keeps a failed run selection distinct from no results and retries that same identity', () => {
 const onRetryResults = vi.fn(); render(<EvaluationHistory runs={[{ id: 73, status: 'completed', result_count: 2 }]} loading={false} selectedRunId={73} onSelect={vi.fn()} results={[]} resultsLoading={false} resultsError={new Error('unavailable')} onRetryResults={onRetryResults} />);
 expect(screen.queryByText('This run has no results.')).toBeNull(); expect(screen.getByLabelText('Evaluation run')).toHaveValue('73'); fireEvent.click(screen.getByRole('button', { name: 'Retry selected evaluation run' })); expect(onRetryResults).toHaveBeenCalledTimes(1);
});

it('clears the prior Portfolio financial rows when the new Portfolio read is unavailable', async () => {
 let other = false; api.get.mockImplementation(() => other ? rejection() : Promise.resolve(payload));
 render(<MemoryRouter><ClosedTransactionsPage /></MemoryRouter>); await screen.findByText('TCS');
 other = true; fireEvent(window, new CustomEvent('portfolio-changed', { detail: { portfolioId: 2 } }));
 await screen.findByText(/Squared-off transactions could not be loaded/);
 expect(screen.queryByText('TCS')).not.toBeInTheDocument();
 expect(screen.queryByRole('button', { name: 'Delete recorded transaction' })).not.toBeInTheDocument();
 expect(screen.queryByText('No squared-off transactions yet.')).not.toBeInTheDocument();
 expect(api.delete).not.toHaveBeenCalled();
});
it('does not publish or refresh a prior Portfolio delete response into the current scope', async () => {
 let other = false, finishDelete; const alternate = { data: { ...payload.data, data: [{ id: 92, stock: { symbol: 'INFY' } }] } };
 api.get.mockImplementation(() => Promise.resolve(other ? alternate : payload));
 api.delete.mockImplementation(() => new Promise(resolve => { finishDelete = resolve; }));
 render(<MemoryRouter><ClosedTransactionsPage /></MemoryRouter>); await screen.findByText('TCS');
 fireEvent.click(screen.getByRole('button', { name: 'Delete recorded transaction' })); await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/transactions/91'));
 other = true; fireEvent(window, new CustomEvent('portfolio-changed', { detail: { portfolioId: 2 } })); await screen.findByText('INFY');
 await act(async () => finishDelete({ data: {} }));
 expect(screen.getByText('INFY')).toBeVisible(); expect(screen.queryByText('TCS')).not.toBeInTheDocument();
 expect(showToast).not.toHaveBeenCalledWith('Transaction deleted'); expect(api.get).toHaveBeenCalledTimes(2); expect(api.delete).toHaveBeenCalledTimes(1);
});
