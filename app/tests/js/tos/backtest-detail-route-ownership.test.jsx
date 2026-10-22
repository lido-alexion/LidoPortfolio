import React from 'react';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { MemoryRouter, Route, Routes, useNavigate } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import BacktestDetailPage from '../../../resources/js/src/pages/BacktestDetailPage';
import { showToast } from '../../../resources/js/src/toast';

vi.mock('../../../resources/js/src/api', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
vi.mock('../../../resources/js/src/components/backtest/BacktestPortfolioChart', () => ({ default: () => <div data-testid="portfolio-chart" /> }));
vi.mock('../../../resources/js/src/components/backtest/BacktestTradeTimeline', () => ({ default: () => <div data-testid="trade-timeline" /> }));

const deferred = () => {
    let resolve; let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
};

const run = (id, name, status = 'completed') => ({
    id, name, status, strategy_name: `Strategy ${id}`, strategy_version: '1.0.0',
    from_date: '2026-01-01', to_date: '2026-02-01', range_key: '1m', tags: [`tag-${id}`],
    notes: `notes-${id}`, execution_assumptions: { parameters_modified: true, parameter_overrides: { threshold: id } },
    statistics: {}, snapshots: [], trades: [], transactions: [], timeline: [], chart: [],
});
const ok = (data) => ({ data: { data } });
const deferredGetById = () => {
    const reads = new Map();
    api.get.mockImplementation((path) => {
        const id = path.split('/').at(-1);
        const request = deferred();
        const queue = reads.get(id) || [];
        queue.push(request);
        reads.set(id, queue);
        return request.promise;
    });
    return (id) => reads.get(String(id)) || [];
};
function RouteDriver() {
    const navigate = useNavigate();
    return <nav aria-label="Test routes">
        <button onClick={() => navigate('/backtests/1')}>Route one</button>
        <button onClick={() => navigate('/backtests/2')}>Route two</button>
    </nav>;
}
function renderRoutes(initial = '/backtests/1', strict = true) {
    const tree = <MemoryRouter initialEntries={[initial]}><RouteDriver /><Routes>
        <Route path="/backtests/:id" element={<BacktestDetailPage />} />
    </Routes></MemoryRouter>;
    return render(strict ? <React.StrictMode>{tree}</React.StrictMode> : tree);
}

beforeEach(() => { cleanup(); api.get.mockReset(); api.post.mockReset(); api.put.mockReset(); showToast.mockClear(); });
afterEach(() => cleanup());

describe('E2E-23 Backtest detail route ownership', () => {
    it.each([
        ['success', (request) => request.resolve(ok(run(1, 'Ordinary late one')))],
        ['error', (request) => request.reject({ message: 'Ordinary old read failure' })],
    ])('keeps route two evidence and metadata after an ordinary held route one %s', async (_outcome, settleOld) => {
        const reads = deferredGetById();
        renderRoutes('/backtests/1', false);
        await waitFor(() => expect(reads(1)).toHaveLength(1));
        const heldOne = reads(1)[0];
        fireEvent.click(screen.getByRole('button', { name: 'Route two' }));
        await waitFor(() => expect(reads(2)).toHaveLength(1));
        await act(async () => reads(2)[0].resolve(ok(run(2, 'Ordinary current two'))));
        expect(await screen.findByRole('heading', { name: 'Ordinary current two' })).toBeVisible();
        await act(async () => settleOld(heldOne));
        expect(screen.getByRole('heading', { name: 'Ordinary current two' })).toBeVisible();
        expect(screen.getByLabelText('Name')).toHaveValue('Ordinary current two');
        expect(screen.getByLabelText('Notes')).toHaveValue('notes-2');
        expect(screen.queryByRole('alert')).toBeNull();
        expect(showToast).not.toHaveBeenCalled();
        api.put.mockResolvedValueOnce(ok(run(2, 'Ordinary current two')));
        fireEvent.click(screen.getByRole('button', { name: 'Save name / notes / tags' }));
        await waitFor(() => expect(api.put).toHaveBeenCalledWith('/v1/backtests/2', {
            name: 'Ordinary current two', notes: 'notes-2', tags: ['tag-2'],
        }));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Save name / notes / tags' })).toBeEnabled());
        expect(api.post).not.toHaveBeenCalled();
    });

    it('keeps current continuation ownership across status progress and unlocks after the final detail refresh', async () => {
        const reads = deferredGetById();
        const firstProgress = deferred(); const finalProgress = deferred();
        api.post.mockReturnValueOnce(firstProgress.promise).mockReturnValueOnce(finalProgress.promise);
        renderRoutes();
        await waitFor(() => expect(reads(1).length).toBeGreaterThan(1));
        await act(async () => reads(1).at(-1).resolve(ok(run(1, 'Current running run', 'running'))));
        await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1));
        await act(async () => firstProgress.resolve(ok({ run: run(1, 'Current preparing run', 'preparing'), continued: true, completed: false })));
        await waitFor(() => expect(api.post).toHaveBeenCalledTimes(2));
        expect(screen.getByRole('heading', { name: 'Current preparing run' })).toBeVisible();
        const readsBeforeFinal = reads(1).length;
        await act(async () => finalProgress.resolve(ok({ run: run(1, 'Current completed run'), continued: false, completed: true })));
        await waitFor(() => expect(reads(1).length).toBeGreaterThan(readsBeforeFinal));
        await act(async () => reads(1).at(-1).resolve(ok(run(1, 'Confirmed current completed run'))));
        expect(await screen.findByRole('heading', { name: 'Confirmed current completed run' })).toBeVisible();
        await waitFor(() => expect(screen.getByRole('button', { name: 'Refresh', exact: true })).toBeEnabled());
        expect(api.post.mock.calls).toEqual([['/v1/backtests/1/continue'], ['/v1/backtests/1/continue']]);
        expect(showToast).not.toHaveBeenCalled();
    });

    it.each([
        ['success', (request) => request.resolve(ok(run(1, 'Late run one')))],
        ['error', (request) => request.reject({ message: 'Late route one failure' })],
    ])('ignores a genuinely pending old-route %s and survives pending A→B→A navigation', async (_outcome, settleOld) => {
        const reads = deferredGetById();
        renderRoutes();
        await waitFor(() => expect(reads(1).length).toBeGreaterThan(1));
        const firstStrictModeRead = reads(1)[0];
        const heldRouteOneRead = reads(1).at(-1);

        await act(async () => firstStrictModeRead.resolve(ok(run(1, 'Strict replay stale run'))));
        expect(screen.queryByRole('heading', { name: 'Strict replay stale run' })).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Route two' }));
        await waitFor(() => expect(reads(2).length).toBeGreaterThan(1));
        const heldRouteTwoRead = reads(2).at(-1);
        await act(async () => heldRouteTwoRead.resolve(ok(run(2, 'Current run two'))));
        expect(await screen.findByRole('heading', { name: 'Current run two' })).toBeVisible();

        expect(screen.getByRole('heading', { name: 'Current run two' })).toBeVisible();
        expect(screen.getByLabelText('Name')).toHaveValue('Current run two');
        expect(api.post).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Route one' }));
        await waitFor(() => expect(reads(1).length).toBeGreaterThan(2));
        const freshRouteOneRead = reads(1).at(-1);
        await act(async () => freshRouteOneRead.resolve(ok(run(1, 'Fresh run one'))));
        expect(await screen.findByRole('heading', { name: 'Fresh run one' })).toBeVisible();

        await act(async () => settleOld(heldRouteOneRead));
        expect(screen.getByRole('heading', { name: 'Fresh run one' })).toBeVisible();
        expect(screen.queryByRole('alert')).toBeNull();
        expect(showToast).not.toHaveBeenCalledWith('Late route one failure', 'danger');
        expect(screen.queryByRole('heading', { name: 'Late run one' })).toBeNull();
        expect(screen.queryByRole('heading', { name: 'Strict replay stale run' })).toBeNull();
    });

    it('allows a same-route failed detail read to retry and keeps the newer read result', async () => {
        const reads = deferredGetById();
        renderRoutes();
        await waitFor(() => expect(reads(1).length).toBeGreaterThan(1));
        const strictReplayRead = reads(1)[0];
        const failedCurrentRead = reads(1).at(-1);
        await act(async () => failedCurrentRead.reject({ response: { status: 503 }, message: 'Read unavailable' }));
        expect(await screen.findByRole('button', { name: 'Retry loading backtest' })).toBeVisible();
        const previousCount = reads(1).length;
        fireEvent.click(screen.getByRole('button', { name: 'Retry loading backtest' }));
        await waitFor(() => expect(reads(1).length).toBeGreaterThan(previousCount));
        await act(async () => reads(1).at(-1).resolve(ok(run(1, 'Recovered run one'))));
        expect(await screen.findByRole('heading', { name: 'Recovered run one' })).toBeVisible();
        await act(async () => strictReplayRead.reject({ message: 'Obsolete StrictMode read' }));
        expect(screen.getByRole('heading', { name: 'Recovered run one' })).toBeVisible();
        expect(showToast).not.toHaveBeenCalledWith('Obsolete StrictMode read', 'danger');
    });

    it('keeps old metadata and strategy-draft completions from changing the new route busy state or feedback', async () => {
        const reads = deferredGetById();
        const oldSave = deferred();
        const newSave = deferred();
        api.put.mockReturnValueOnce(oldSave.promise).mockReturnValueOnce(newSave.promise);
        const oldDraft = deferred();
        const newDraft = deferred();
        api.post.mockReturnValueOnce(oldDraft.promise).mockReturnValueOnce(newDraft.promise);
        renderRoutes();

        await waitFor(() => expect(reads(1).length).toBeGreaterThan(1));
        await act(async () => reads(1).at(-1).resolve(ok(run(1, 'Run one'))));
        expect(await screen.findByRole('heading', { name: 'Run one' })).toBeVisible();
        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Edited one' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save name / notes / tags' }));
        await waitFor(() => expect(api.put).toHaveBeenCalledTimes(1));
        fireEvent.click(screen.getByRole('button', { name: 'Create Strategy Draft' }));
        await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1));

        fireEvent.click(screen.getByRole('button', { name: 'Route two' }));
        await waitFor(() => expect(reads(2).length).toBeGreaterThan(1));
        await act(async () => reads(2).at(-1).resolve(ok(run(2, 'Run two'))));
        expect(await screen.findByRole('heading', { name: 'Run two' })).toBeVisible();
        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Edited two' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save name / notes / tags' }));
        await waitFor(() => expect(api.put).toHaveBeenCalledTimes(2));
        fireEvent.click(screen.getByRole('button', { name: 'Create Strategy Draft' }));
        await waitFor(() => expect(api.post).toHaveBeenCalledTimes(2));

        expect(api.put.mock.calls[0]).toEqual(['/v1/backtests/1', { name: 'Edited one', notes: 'notes-1', tags: ['tag-1'] }]);
        expect(api.put.mock.calls[1]).toEqual(['/v1/backtests/2', { name: 'Edited two', notes: 'notes-2', tags: ['tag-2'] }]);
        await act(async () => oldSave.resolve(ok({ ...run(1, 'Edited one') })));
        await act(async () => oldDraft.resolve(ok({ semver: '1.9.0' })));
        expect(screen.getByRole('heading', { name: 'Run two' })).toBeVisible();
        expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Creating Draft…' })).toBeDisabled();
        expect(showToast).not.toHaveBeenCalledWith('Backtest updated', 'success');
        expect(showToast).not.toHaveBeenCalledWith('Strategy Draft 1.9.0 created in Artifact Library', 'success');

        await act(async () => newSave.resolve(ok({ ...run(2, 'Edited two') })));
        await act(async () => newDraft.resolve(ok({ semver: '2.1.0' })));
        expect(await screen.findByRole('heading', { name: 'Edited two' })).toBeVisible();
        expect(showToast).toHaveBeenCalledWith('Backtest updated', 'success');
        expect(showToast).toHaveBeenCalledWith('Strategy Draft 2.1.0 created in Artifact Library', 'success');
        expect(api.post.mock.calls).toEqual([['/v1/backtests/1/strategy-draft'], ['/v1/backtests/2/strategy-draft']]);
    });

    it('ignores a delayed old strategy-draft failure while the new route draft request is busy', async () => {
        const reads = deferredGetById();
        const oldDraft = deferred();
        const newDraft = deferred();
        api.post.mockReturnValueOnce(oldDraft.promise).mockReturnValueOnce(newDraft.promise);
        renderRoutes();
        await waitFor(() => expect(reads(1).length).toBeGreaterThan(1));
        await act(async () => reads(1).at(-1).resolve(ok(run(1, 'Run one'))));
        fireEvent.click(screen.getByRole('button', { name: 'Create Strategy Draft' }));
        await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1));

        fireEvent.click(screen.getByRole('button', { name: 'Route two' }));
        await waitFor(() => expect(reads(2).length).toBeGreaterThan(1));
        await act(async () => reads(2).at(-1).resolve(ok(run(2, 'Run two'))));
        fireEvent.click(screen.getByRole('button', { name: 'Create Strategy Draft' }));
        await waitFor(() => expect(api.post).toHaveBeenCalledTimes(2));

        await act(async () => oldDraft.reject({ message: 'Old draft failed' }));
        expect(screen.getByRole('heading', { name: 'Run two' })).toBeVisible();
        expect(screen.getByRole('button', { name: 'Creating Draft…' })).toBeDisabled();
        expect(showToast).not.toHaveBeenCalledWith('Old draft failed', 'danger');
        await act(async () => newDraft.resolve(ok({ semver: '2.2.0' })));
        expect(showToast).toHaveBeenCalledWith('Strategy Draft 2.2.0 created in Artifact Library', 'success');
    });

    it('retains a same-route metadata draft after failure and retries the same payload', async () => {
        const reads = deferredGetById();
        const failedSave = deferred();
        api.put.mockReturnValueOnce(failedSave.promise).mockResolvedValueOnce(ok({ ...run(1, 'Retried name') }));
        renderRoutes();
        await waitFor(() => expect(reads(1).length).toBeGreaterThan(1));
        await act(async () => reads(1).at(-1).resolve(ok(run(1, 'Run one'))));
        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Retried name' } });
        fireEvent.change(screen.getByLabelText('Notes'), { target: { value: 'Retained notes' } });
        fireEvent.change(screen.getByLabelText('Tags'), { target: { value: 'one, two' } });
        const expectedPayload = { name: 'Retried name', notes: 'Retained notes', tags: ['one', 'two'] };

        fireEvent.click(screen.getByRole('button', { name: 'Save name / notes / tags' }));
        await waitFor(() => expect(api.put).toHaveBeenCalledTimes(1));
        await act(async () => failedSave.reject({ message: 'Save unavailable' }));
        expect(screen.getByLabelText('Name')).toHaveValue('Retried name');
        expect(screen.getByLabelText('Notes')).toHaveValue('Retained notes');
        expect(screen.getByLabelText('Tags')).toHaveValue('one, two');
        expect(showToast).toHaveBeenCalledWith('Save unavailable', 'danger');

        fireEvent.click(screen.getByRole('button', { name: 'Save name / notes / tags' }));
        await waitFor(() => expect(api.put).toHaveBeenCalledTimes(2));
        expect(api.put.mock.calls.map((call) => call[1])).toEqual([expectedPayload, expectedPayload]);
        expect(await screen.findByRole('heading', { name: 'Retried name' })).toBeVisible();
        expect(showToast).toHaveBeenCalledWith('Backtest updated', 'success');
    });

    it('does not let a late old metadata failure clear or report over the new route save', async () => {
        const reads = deferredGetById();
        const oldSave = deferred();
        const newSave = deferred();
        api.put.mockReturnValueOnce(oldSave.promise).mockReturnValueOnce(newSave.promise);
        renderRoutes();
        await waitFor(() => expect(reads(1).length).toBeGreaterThan(1));
        await act(async () => reads(1).at(-1).resolve(ok(run(1, 'Run one'))));
        expect(await screen.findByRole('heading', { name: 'Run one' })).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Save name / notes / tags' }));
        fireEvent.click(screen.getByRole('button', { name: 'Route two' }));
        await waitFor(() => expect(reads(2).length).toBeGreaterThan(1));
        await act(async () => reads(2).at(-1).resolve(ok(run(2, 'Run two'))));
        expect(await screen.findByRole('heading', { name: 'Run two' })).toBeVisible();
        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Run two draft' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save name / notes / tags' }));
        await waitFor(() => expect(api.put).toHaveBeenCalledTimes(2));

        await act(async () => oldSave.reject({ message: 'Old save failed' }));
        expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();
        expect(showToast).not.toHaveBeenCalledWith('Old save failed', 'danger');
        await act(async () => newSave.resolve(ok({ ...run(2, 'Run two draft') })));
        expect(await screen.findByRole('heading', { name: 'Run two draft' })).toBeVisible();
        expect(showToast).toHaveBeenCalledWith('Backtest updated', 'success');
    });

    it('does not let a prior run progress or final response change the newer route session', async () => {
        const reads = deferredGetById();
        const oldContinue = deferred();
        api.post.mockReturnValueOnce(oldContinue.promise);
        renderRoutes();
        await waitFor(() => expect(reads(1).length).toBeGreaterThan(1));
        await act(async () => reads(1).at(-1).resolve(ok(run(1, 'Old running run', 'running'))));
        await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/backtests/1/continue'));
        expect(screen.getByText(/Resuming backtest/)).toBeVisible();

        fireEvent.click(screen.getByRole('button', { name: 'Route two' }));
        await waitFor(() => expect(reads(2).length).toBeGreaterThan(1));
        await act(async () => reads(2).at(-1).resolve(ok(run(2, 'New completed run', 'completed'))));
        expect(await screen.findByRole('heading', { name: 'New completed run' })).toBeVisible();
        await act(async () => oldContinue.resolve(ok({ run: run(1, 'Old completed run'), completed: true, continued: false })));
        expect(screen.getByRole('heading', { name: 'New completed run' })).toBeVisible();
        expect(screen.queryByRole('heading', { name: 'Old completed run' })).toBeNull();
        expect(api.post.mock.calls).toEqual([['/v1/backtests/1/continue']]);
    });

    it('does not continue run two from a pending run one response after route change', async () => {
        const reads = deferredGetById();
        renderRoutes();
        await waitFor(() => expect(reads(1).length).toBeGreaterThan(1));

        fireEvent.click(screen.getByRole('button', { name: 'Route two' }));
        await waitFor(() => expect(reads(2).length).toBeGreaterThan(1));
        await act(async () => reads(2).at(-1).resolve(ok(run(2, 'Run two completed'))));
        expect(await screen.findByRole('heading', { name: 'Run two completed' })).toBeVisible();
        await act(async () => reads(1).forEach((request) => request.resolve(ok(run(1, 'Run one running', 'running')))));

        expect(screen.getByRole('heading', { name: 'Run two completed' })).toBeVisible();
        expect(screen.queryByText(/Resuming backtest/)).toBeNull();
        expect(api.post).not.toHaveBeenCalled();
    });

    it('ignores a detail failure that settles after its route session unmounts', async () => {
        const reads = deferredGetById();
        const view = renderRoutes();
        await waitFor(() => expect(reads(1).length).toBeGreaterThan(1));
        const pendingReads = [...reads(1)];
        view.unmount();
        await act(async () => pendingReads.forEach((request) => request.reject({ message: 'Unmounted detail failure' })));
        expect(showToast).not.toHaveBeenCalledWith('Unmounted detail failure', 'danger');
    });
});
