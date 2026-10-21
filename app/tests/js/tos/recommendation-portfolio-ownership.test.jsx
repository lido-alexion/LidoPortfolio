import React from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import AuthContext from '../../../resources/js/src/context/AuthContext';
import { PortfolioProvider, usePortfolio } from '../../../resources/js/src/context/PortfolioContext';
import PortfolioSwitcher from '../../../resources/js/src/components/PortfolioSwitcher';
import RecommendationsPage from '../../../resources/js/src/pages/RecommendationsPage';
import { showToast } from '../../../resources/js/src/toast';

vi.mock('../../../resources/js/src/api', () => ({
    default: { get: vi.fn(), post: vi.fn() },
    getApiErrorMessage: (error, fallback) => error?.response?.data?.error?.message || error?.message || fallback,
}));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
const childBoundary = vi.hoisted(() => ({ onChanged: null, calls: 0 }));
vi.mock('../../../resources/js/src/components/RecommendationLenderActions', () => ({
    default: ({ onChanged }) => {
        childBoundary.onChanged = onChanged;
        return <button type="button" onClick={() => { childBoundary.calls += 1; onChanged?.(); }}>Child lender completion</button>;
    },
}));
vi.mock('../../../resources/js/src/components/RecommendationCapitalResolution', () => ({ default: () => null }));

const deferred = () => {
    let resolve; let reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
};
const portfolios = [{ id: 1, name: 'Main', is_default: true }, { id: 2, name: 'Research' }];
const list = (symbol) => ({ data: { data: [{ id: symbol, symbol, category: 'actionable', portfolio_action: 'OPEN_POSITION', status: 'pending_review' }], meta: { total: 1 } } });
function BatchSwitch() {
    const { setActivePortfolio } = usePortfolio();
    return <button type="button" onClick={() => { setActivePortfolio(2); setActivePortfolio(1); }}>Batch A-B-A</button>;
}
function renderPage(extra = null) {
    return render(<MemoryRouter><AuthContext.Provider value={{ isAuthenticated: true, user: { id: 1, default_portfolio_id: 1 }, loading: false }}>
        <PortfolioProvider><PortfolioSwitcher />{extra}<RecommendationsPage /></PortfolioProvider>
    </AuthContext.Provider></MemoryRouter>);
}

beforeEach(() => {
    cleanup(); vi.resetAllMocks(); localStorage.clear(); sessionStorage.clear();
    childBoundary.onChanged = null;
    childBoundary.calls = 0;
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: portfolios } });
        if (path.startsWith('/v1/recommendations/')) return Promise.resolve({ data: { data: { id: 'ALPHA', symbol: 'ALPHA', portfolio_action: 'OPEN_POSITION', category: 'actionable', can_review: true } } });
        if (path === '/v1/recommendations') return Promise.resolve(list(sessionStorage.getItem('portfolio_active_id') === '2' ? 'BETA' : 'ALPHA'));
        throw new Error(`Unexpected GET ${path}`);
    });
});
afterEach(() => cleanup());

it('replaces confirmed A recommendations with B on a real provider switch', async () => {
    renderPage();
    expect(await screen.findByText('ALPHA')).toBeInTheDocument();
    fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
    fireEvent.click(await screen.findByRole('button', { name: 'Research', exact: true }));
    expect(await screen.findByText('BETA')).toBeInTheDocument();
    expect(screen.queryByText('ALPHA')).not.toBeInTheDocument();
    expect(api.get.mock.calls.filter(([path]) => path === '/v1/recommendations').map(([, options]) => options.params))
        .toEqual([{ open: 1 }, { open: 1 }]);
});

it('ignores a deferred A list completion after B becomes active', async () => {
    const oldA = deferred();
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: portfolios } });
        if (path === '/v1/recommendations' && sessionStorage.getItem('portfolio_active_id') === '1') return oldA.promise;
        if (path === '/v1/recommendations') return Promise.resolve(list('BETA'));
        throw new Error(`Unexpected GET ${path}`);
    });
    renderPage();
    await waitFor(() => expect(api.get.mock.calls.some(([path, options]) =>
        path === '/v1/recommendations' && options?.params?.open === 1)).toBe(true));
    fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
    fireEvent.click(await screen.findByRole('button', { name: 'Research', exact: true }));
    expect(await screen.findByText('BETA')).toBeInTheDocument();
    await act(async () => oldA.resolve(list('ALPHA')));
    expect(screen.queryByText('ALPHA')).not.toBeInTheDocument();
    expect(screen.getByText('BETA')).toBeInTheDocument();
});

it('does not send a stale review after storage has switched portfolios', async () => {
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Review' }));
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/v1/recommendations/ALPHA', expect.any(Object)));
    sessionStorage.setItem('portfolio_active_id', '2');
    fireEvent.click(await screen.findByRole('button', { name: 'Approve' }));
    expect(api.post).not.toHaveBeenCalled();
});

it('lets the latest same-portfolio detail request own the review panel', async () => {
    const first = deferred();
    const second = deferred();
    const details = [];
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: portfolios } });
        if (path === '/v1/recommendations') return Promise.resolve({ data: { data: [
            { id: 11, symbol: 'FIRST', category: 'actionable', portfolio_action: 'OPEN_POSITION' },
            { id: 12, symbol: 'SECOND', category: 'actionable', portfolio_action: 'OPEN_POSITION' },
        ] } });
        if (path === '/v1/recommendations/11') { details.push(path); return first.promise; }
        if (path === '/v1/recommendations/12') { details.push(path); return second.promise; }
        throw new Error(`Unexpected GET ${path}`);
    });
    renderPage();
    fireEvent.click(await screen.findAllByRole('button', { name: 'Review' }).then((buttons) => buttons[0]));
    fireEvent.click(screen.getAllByRole('button', { name: 'Review' })[1]);
    expect(details).toEqual(['/v1/recommendations/11', '/v1/recommendations/12']);
    await act(async () => second.resolve({ data: { data: { id: 12, symbol: 'SECOND', portfolio_action: 'OPEN_POSITION' } } }));
    expect(await screen.findByRole('heading', { name: /SECOND/ })).toBeInTheDocument();
    await act(async () => first.resolve({ data: { data: { id: 11, symbol: 'FIRST', portfolio_action: 'OPEN_POSITION' } } }));
    expect(screen.getByRole('heading', { name: /SECOND/ })).toBeInTheDocument();
});

it('uses the portfolio event epoch to reject an A request across a batched A to B to A switch', async () => {
    const oldA = deferred();
    let aReads = 0;
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: portfolios } });
        if (path !== '/v1/recommendations') throw new Error(`Unexpected GET ${path}`);
        const active = sessionStorage.getItem('portfolio_active_id');
        if (active === '1' && ++aReads === 1) return oldA.promise;
        return Promise.resolve(list(active === '2' ? 'BETA' : 'ALPHA FRESH'));
    });
    renderPage(<BatchSwitch />);
    await waitFor(() => expect(api.get.mock.calls.some(([path, options]) =>
        path === '/v1/recommendations' && options?.params?.open === 1)).toBe(true));
    fireEvent.click(screen.getByRole('button', { name: 'Batch A-B-A' }));
    expect(await screen.findByText('ALPHA FRESH')).toBeInTheDocument();
    await act(async () => oldA.resolve(list('ALPHA OLD')));
    expect(screen.queryByText('ALPHA OLD')).not.toBeInTheDocument();
    expect(screen.getByText('ALPHA FRESH')).toBeInTheDocument();
});

it.each(['resolve', 'reject'])('ignores deferred A detail %s after switching to B', async (outcome) => {
    const detail = deferred();
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: portfolios } });
        if (path === '/v1/recommendations') return Promise.resolve(list(sessionStorage.getItem('portfolio_active_id') === '2' ? 'BETA' : 'ALPHA'));
        if (path === '/v1/recommendations/ALPHA') return detail.promise;
        if (path === '/v1/recommendations/BETA') return Promise.resolve({ data: { data: { id: 'BETA', symbol: 'BETA', portfolio_action: 'OPEN_POSITION', can_review: true } } });
        throw new Error(`Unexpected GET ${path}`);
    });
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Review' }));
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/v1/recommendations/ALPHA', expect.any(Object)));
    fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
    fireEvent.click(await screen.findByRole('button', { name: 'Research', exact: true }));
    expect(await screen.findByText('BETA')).toBeInTheDocument();
    if (outcome === 'resolve') {
        await act(async () => detail.resolve({ data: { data: { id: 'ALPHA', symbol: 'ALPHA', portfolio_action: 'OPEN_POSITION' } } }));
    } else {
        await act(async () => detail.reject(Object.assign(new Error('old A detail failed'), { response: { data: { error: { message: 'old A detail failed' } } } })));
    }
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(showToast).not.toHaveBeenCalledWith('old A detail failed', 'danger');
    expect(screen.getByText('BETA')).toBeInTheDocument();
});

it('preserves API response-envelope detail errors for the active portfolio', async () => {
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: portfolios } });
        if (path === '/v1/recommendations') return Promise.resolve(list('ALPHA'));
        if (path === '/v1/recommendations/ALPHA') return Promise.reject(Object.assign(new Error('generic transport message'), {
            response: { data: { error: { message: 'Recommendation detail unavailable' } } },
        }));
        throw new Error(`Unexpected GET ${path}`);
    });
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Review' }));
    await waitFor(() => expect(showToast).toHaveBeenCalledWith('Recommendation detail unavailable', 'danger'));
});

it('keeps B detail and busy state when an obsolete A review finishes', async () => {
    const oldReview = deferred();
    const newReview = deferred();
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: portfolios } });
        if (path === '/v1/recommendations') return Promise.resolve({ data: { data: sessionStorage.getItem('portfolio_active_id') === '2'
            ? [{ id: 202, symbol: 'BETA', category: 'actionable', portfolio_action: 'OPEN_POSITION' }]
            : [{ id: 101, symbol: 'ALPHA', category: 'actionable', portfolio_action: 'OPEN_POSITION' }] } });
        if (path === '/v1/recommendations/101') return Promise.resolve({ data: { data: { id: 101, symbol: 'ALPHA', portfolio_action: 'OPEN_POSITION', can_review: true } } });
        if (path === '/v1/recommendations/202') return Promise.resolve({ data: { data: { id: 202, symbol: 'BETA', portfolio_action: 'OPEN_POSITION', can_review: true } } });
        throw new Error(`Unexpected GET ${path}`);
    });
    api.post.mockImplementation((path) => (path.includes('/101/') ? oldReview.promise : newReview.promise));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Review' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Approve' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/recommendations/101/review', { decision: 'approved', notes: null }, { skipErrorToast: true }));
    fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
    fireEvent.click(await screen.findByRole('button', { name: 'Research', exact: true }));
    fireEvent.click(await screen.findByRole('button', { name: 'Review' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Approve' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/recommendations/202/review', { decision: 'approved', notes: null }, { skipErrorToast: true }));
    await act(async () => oldReview.resolve({ data: { data: {} } }));
    expect(screen.getByRole('heading', { name: /BETA/ })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Approve' })).toBeDisabled();
    expect(showToast).not.toHaveBeenCalledWith('Marked approved for execution', 'success');
    await act(async () => newReview.resolve({ data: { data: {} } }));
});

it('preserves a newer same-scope detail, notes, and busy operation when the older review finishes', async () => {
    const oldReview = deferred();
    const newReview = deferred();
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: portfolios } });
        if (path === '/v1/recommendations') return Promise.resolve({ data: { data: [
            { id: 11, symbol: 'FIRST', category: 'actionable', portfolio_action: 'OPEN_POSITION' },
            { id: 12, symbol: 'SECOND', category: 'actionable', portfolio_action: 'OPEN_POSITION' },
        ] } });
        if (path === '/v1/recommendations/11') return Promise.resolve({ data: { data: { id: 11, symbol: 'FIRST', portfolio_action: 'OPEN_POSITION', can_review: true } } });
        if (path === '/v1/recommendations/12') return Promise.resolve({ data: { data: { id: 12, symbol: 'SECOND', portfolio_action: 'OPEN_POSITION', can_review: true } } });
        throw new Error(`Unexpected GET ${path}`);
    });
    api.post.mockImplementation((path) => (path.includes('/11/') ? oldReview.promise : newReview.promise));
    renderPage();
    fireEvent.click((await screen.findAllByRole('button', { name: 'Review' }))[0]);
    fireEvent.click(await screen.findByRole('button', { name: 'Approve' }));
    fireEvent.click(screen.getAllByRole('button', { name: 'Review' })[1]);
    expect(await screen.findByRole('heading', { name: /SECOND/ })).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Review notes (optional)'), { target: { value: 'Keep this note' } });
    fireEvent.click(await screen.findByRole('button', { name: 'Approve' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/recommendations/12/review', { decision: 'approved', notes: 'Keep this note' }, { skipErrorToast: true }));
    await act(async () => oldReview.resolve({ data: { data: {} } }));
    expect(screen.getByRole('heading', { name: /SECOND/ })).toBeInTheDocument();
    expect(screen.getByLabelText('Review notes (optional)')).toHaveValue('Keep this note');
    expect(screen.getByRole('button', { name: 'Approve' })).toBeDisabled();
    await act(async () => newReview.resolve({ data: { data: {} } }));
});

it('ignores an A lender child callback after switching to B', async () => {
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: portfolios } });
        if (path === '/v1/recommendations' && sessionStorage.getItem('portfolio_active_id') === '2') return Promise.resolve(list('BETA'));
        if (path === '/v1/recommendations') return Promise.resolve({ data: { data: [{
            id: 'ALPHA', symbol: 'ALPHA', category: 'actionable', portfolio_action: 'OPEN_POSITION',
            capital_request_id: 55, capital_allocation_status: 'unfunded',
        }] } });
        throw new Error(`Unexpected GET ${path}`);
    });
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Select lender' }));
    await waitFor(() => expect(childBoundary.onChanged).toEqual(expect.any(Function)));
    const oldCallback = childBoundary.onChanged;
    sessionStorage.setItem('portfolio_active_id', '2');
    fireEvent.click(screen.getByRole('button', { name: 'Child lender completion' }));
    expect(childBoundary.calls).toBe(0);
    fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
    fireEvent.click(await screen.findByRole('button', { name: 'Research', exact: true }));
    expect(await screen.findByText('BETA')).toBeInTheDocument();
    const listReadsBeforeOldCallback = api.get.mock.calls.filter(([path]) => path === '/v1/recommendations').length;
    await act(async () => oldCallback());
    expect(api.get.mock.calls.filter(([path]) => path === '/v1/recommendations')).toHaveLength(listReadsBeforeOldCallback);
    expect(api.get.mock.calls.some(([path]) => path === '/v1/recommendations/ALPHA')).toBe(false);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});

it('does not treat a null provider bootstrap as a confirmed empty recommendation list', async () => {
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: [] } });
        throw new Error(`Unexpected GET ${path}`);
    });
    renderPage();
    expect(await screen.findByText('Loading…')).toBeInTheDocument();
    expect(api.get).toHaveBeenCalledTimes(1);
});

it('ignores a deferred list failure after unmount', async () => {
    const pending = deferred();
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: portfolios } });
        if (path === '/v1/recommendations') return pending.promise;
        throw new Error(`Unexpected GET ${path}`);
    });
    const view = renderPage();
    await waitFor(() => expect(api.get.mock.calls.some(([path, options]) =>
        path === '/v1/recommendations' && options?.params?.open === 1)).toBe(true));
    view.unmount();
    await act(async () => pending.reject(new Error('late unmounted failure')));
    expect(showToast).not.toHaveBeenCalled();
});

it('keeps same-scope review and pipeline payloads and follow-up behavior', async () => {
    api.post.mockImplementation((path) => Promise.resolve(path === '/v1/pipeline/run'
        ? { data: { data: { stages: { discovery: { candidates: 2 }, evaluation: { results: 2 }, recommendation: { count: 1 } } } } }
        : { data: { data: {} } }));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Review' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Defer' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/recommendations/ALPHA/review', { decision: 'deferred', notes: null }, { skipErrorToast: true }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: 'Run decision pipeline' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/pipeline/run', null, { params: { notify: 1, review: 1 }, skipErrorToast: true }));
    expect(await screen.findByText(/Pipeline: discovery candidates 2/)).toBeInTheDocument();
});

it('preserves the same-scope reopen request and detail refresh', async () => {
    let detailReads = 0;
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: portfolios } });
        if (path === '/v1/recommendations') return Promise.resolve(list('ALPHA'));
        if (path === '/v1/recommendations/ALPHA') {
            detailReads += 1;
            return Promise.resolve({ data: { data: { id: 'ALPHA', symbol: 'ALPHA', portfolio_action: 'OPEN_POSITION', can_reopen: true } } });
        }
        throw new Error(`Unexpected GET ${path}`);
    });
    api.post.mockResolvedValue({ data: { data: {} } });
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Review' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Undo decision — reopen for review' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/recommendations/ALPHA/reopen', { notes: null }, { skipErrorToast: true }));
    await waitFor(() => expect(detailReads).toBe(2));
    expect(screen.getByRole('heading', { name: /ALPHA/ })).toBeInTheDocument();
});


it('keeps an in-flight pipeline owned and busy while opening and closing details', async () => {
    const pipeline = deferred();
    api.post.mockImplementation((path) => {
        if (path === '/v1/pipeline/run') return pipeline.promise;
        throw new Error(`Unexpected POST ${path}`);
    });
    renderPage();
    await screen.findByText('ALPHA');
    fireEvent.click(screen.getByRole('button', { name: 'Run decision pipeline' }));
    expect(screen.getByRole('button', { name: 'Running pipeline…' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Review' }));
    await screen.findByRole('dialog');
    expect(screen.getByRole('button', { name: 'Running pipeline…' })).toBeDisabled();
    fireEvent.click(screen.getByText('Close', { selector: 'button' }));
    expect(screen.getByRole('button', { name: 'Running pipeline…' })).toBeDisabled();
    expect(api.post.mock.calls.filter(([path]) => path === '/v1/pipeline/run')).toHaveLength(1);
    await act(async () => pipeline.resolve({ data: { data: { stages: { recommendation: { count: 1 } } } } }));
    expect(await screen.findByRole('button', { name: 'Run decision pipeline' })).toBeEnabled();
    expect(screen.getByText(/strategy-specific recommendations 1/)).toBeInTheDocument();
});


it('lets a changed history filter supersede an initial pending list read', async () => {
    const openList = deferred();
    api.get.mockImplementation((path, options) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: portfolios } });
        if (path === '/v1/recommendations' && options.params.open === 1) {
            return openList.promise;
        }
        if (path === '/v1/recommendations' && options.params.all === 1) return Promise.resolve(list('CLOSED HISTORY'));
        throw new Error(`Unexpected GET ${path}`);
    });
    renderPage();
    await waitFor(() => expect(api.get.mock.calls.some(([path, options]) =>
        path === '/v1/recommendations' && options?.params?.open === 1)).toBe(true));
    fireEvent.click(screen.getByLabelText('Include closed history'));
    expect(await screen.findByText('CLOSED HISTORY')).toBeInTheDocument();
    await act(async () => openList.resolve(list('OLD OPEN')));
    expect(screen.queryByText('OLD OPEN')).not.toBeInTheDocument();
    expect(screen.getByText('CLOSED HISTORY')).toBeInTheDocument();
    expect(api.get.mock.calls.filter(([path]) => path === '/v1/recommendations').map(([, options]) => options.params))
        .toEqual([{ open: 1 }, { all: 1 }]);
});
