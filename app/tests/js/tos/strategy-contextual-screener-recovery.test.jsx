import React from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { BrowserRouter, Route, Routes, useLocation, useNavigate } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import AuthContext, { AuthProvider } from '../../../resources/js/src/context/AuthContext.jsx';
import { PortfolioProvider, usePortfolio } from '../../../resources/js/src/context/PortfolioContext.jsx';
import StrategyPage from '../../../resources/js/src/pages/StrategyPage.jsx';
import { clearActivePortfolioId, getActivePortfolioId, setActivePortfolioId } from '../../../resources/js/src/portfolio/activePortfolioStorage.js';
import { readStrategyScreenerReturnDraft, writeStrategyScreenerReturnDraft } from '../../../resources/js/src/utils/strategyScreenerReturnFlow.js';
import { TEST_USER } from './fixtures/tosApi.js';
import { apiMock } from './helpers/mockApi.js';

const EXISTING_SCREENER = {
    id: 41,
    name: 'Existing entry screen',
    description: 'Keep this eligibility source.',
    scope: 'holdings',
    is_enabled: true,
    version: 3,
};
const CREATED_SCREENER = {
    id: 99,
    name: 'Contextual screen',
    description: 'Created for this Strategy.',
    scope: 'holdings',
    is_enabled: true,
    version: 1,
};

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((resolvePromise, rejectPromise) => {
        resolve = resolvePromise;
        reject = rejectPromise;
    });
    return { promise, resolve, reject };
}

function strategyResponse(id, name, overrides = {}) {
    return {
        data: {
            data: {
                strategy_id: id,
                id,
                name,
                description: 'Server-owned description.',
                status: 'draft',
                is_enabled: false,
                setup_required: false,
                readiness: { requirements: [] },
                reusable_artifact_uuid: null,
                compatibility_read_only: false,
                indicators: [{ key: 'momentum_score', category: 'Momentum', label: 'Momentum', enabled: true, weight: 100 }],
                eligibility_sources: [{
                    screener_id: EXISTING_SCREENER.id,
                    screener_name: EXISTING_SCREENER.name,
                    description: EXISTING_SCREENER.description,
                    enabled: true,
                    priority: 1,
                    display_order: 0,
                }],
                thresholds: { open_position: 61 },
                portfolio_rules: { horizon_calendar_days: 30, first_entry_pct: 50, max_holdings: 5 },
                capital_allocation: { strategy: 'proportional', tie_break: 'highest_score', score_bands: [] },
                exit_strategy: { enabled: true, mode: 'any', rules: [] },
                market_gates: { enabled: false, min_sentiment: 45, allowed_phases: [], max_risk_raw: 70 },
                ...overrides,
            },
        },
    };
}

function installDeferredStrategyReads(reads) {
    const portfolioReads = [];
    let nextPortfolioRead = null;
    apiMock.get.mockImplementation((path, config) => {
        if (path === '/auth/me') return Promise.resolve({ data: { user: TEST_USER } });
        if (path === '/v1/strategy') {
            const pending = deferred();
            reads.push({
                ...pending,
                readIndex: reads.length,
                strategyId: String(config?.params?.strategy_id || ''),
                portfolioId: getActivePortfolioId(),
            });
            return pending.promise;
        }
        if (path === '/portfolios') {
            if (nextPortfolioRead) {
                const pending = nextPortfolioRead;
                nextPortfolioRead = null;
                portfolioReads.push(pending);
                return pending.promise;
            }
            return Promise.resolve({ data: { data: [
                { id: 1, name: 'Profile one', is_default: true },
                { id: 2, name: 'Profile two', is_default: false },
            ] } });
        }
        if (path === '/screeners') {
            return Promise.resolve({ data: { data: [EXISTING_SCREENER, CREATED_SCREENER] } });
        }
        if (path === '/indexes') return Promise.resolve({ data: { data: { indexes: [] } } });
        if (path === '/v1/strategy-registry') {
            return Promise.resolve({ data: { data: [
                { id: 7, strategy_id: 7, name: 'Strategy seven', status: 'draft', is_enabled: false },
                { id: 8, strategy_id: 8, name: 'Strategy eight', status: 'draft', is_enabled: false },
            ] } });
        }
        throw new Error(`Unexpected GET ${path}`);
    });

    return {
        portfolioReads,
        deferNextPortfolioRead() {
            if (nextPortfolioRead) throw new Error('A deferred Portfolio read is already queued.');
            nextPortfolioRead = deferred();
            return nextPortfolioRead;
        },
    };
}

function RouteProbeAndControls() {
    const navigate = useNavigate();
    const location = useLocation();
    const { setActivePortfolio, bootstrap, loading: portfolioLoading } = usePortfolio();
    return (
        <>
            <output data-testid="router-location">{location.pathname}{location.search}</output>
            <output data-testid="portfolio-loading">{portfolioLoading ? 'loading' : 'idle'}</output>
            <button type="button" onClick={() => navigate('/strategy?strategy_id=8')}>Navigate to Strategy 8</button>
            <button type="button" onClick={() => navigate('/strategy?strategy_id=7')}>Navigate to Strategy 7</button>
            <button type="button" onClick={() => setActivePortfolio(2)}>Switch to Profile 2</button>
            <button type="button" onClick={() => setActivePortfolio(1)}>Re-select Profile 1</button>
            <button type="button" onClick={() => bootstrap()}>Refresh Profiles</button>
            <Routes>
                <Route path="/strategy" element={<StrategyPage />} />
            </Routes>
        </>
    );
}

function renderActualStrategyPage(initialUrl) {
    window.history.replaceState({}, '', initialUrl);
    const auth = { user: TEST_USER, loading: false, isAuthenticated: true };
    return render(
        <React.StrictMode>
            <BrowserRouter>
                <AuthContext.Provider value={auth}>
                    <PortfolioProvider>
                        <RouteProbeAndControls />
                    </PortfolioProvider>
                </AuthContext.Provider>
            </BrowserRouter>
        </React.StrictMode>,
    );
}

function renderStrategyPageWithActualAuthProvider(initialUrl) {
    window.history.replaceState({}, '', initialUrl);
    return render(
        <React.StrictMode>
            <BrowserRouter>
                <AuthProvider>
                    <PortfolioProvider>
                        <RouteProbeAndControls />
                    </PortfolioProvider>
                </AuthProvider>
            </BrowserRouter>
        </React.StrictMode>,
    );
}

function seedReturnDraft(portfolioId = '1') {
    setActivePortfolioId(portfolioId);
    writeStrategyScreenerReturnDraft({
        strategyId: '7',
        portfolioId,
        meta: {
            id: 7,
            name: 'Unsaved contextual strategy',
            description: 'Unsaved description.',
            status: 'draft',
            is_enabled: false,
            setup_required: false,
            readiness: { requirements: [] },
            compatibility_read_only: false,
        },
        config: {
            indicators: [{ key: 'momentum_score', category: 'Momentum', label: 'Momentum', enabled: true, weight: 100 }],
            eligibility_sources: [
                { screener_id: EXISTING_SCREENER.id, screener_name: EXISTING_SCREENER.name, enabled: true, priority: 1 },
                { screener_id: CREATED_SCREENER.id, screener_name: CREATED_SCREENER.name, enabled: true, priority: 2 },
            ],
            thresholds: { open_position: 68 },
            portfolio_rules: { horizon_calendar_days: 30, first_entry_pct: 50, max_holdings: 7 },
            capital_allocation: { strategy: 'proportional', tie_break: 'highest_score', score_bands: [] },
            exit_strategy: { enabled: true, mode: 'any', rules: [] },
            market_gates: { enabled: false, min_sentiment: 45, allowed_phases: [], max_risk_raw: 70 },
        },
        section: 'eligibility',
    });
}

async function resolveRead(read, id, name, overrides = {}) {
    await act(async () => read.resolve(strategyResponse(id, name, overrides)));
}

describe('STR-02 contextual Strategy recovery concurrency', () => {
    it('waits for initial PortfolioProvider ownership before hydrating the Strategy', async () => {
        const reads = [];
        installDeferredStrategyReads(reads);
        clearActivePortfolioId();
        renderStrategyPageWithActualAuthProvider('/strategy?strategy_id=7');

        expect(screen.getByText('Loading strategy…')).toBeInTheDocument();
        await waitFor(() => expect(getActivePortfolioId()).toBe('1'));
        await waitFor(() => expect(reads.length).toBeGreaterThan(0));
        const profileOneReads = reads.filter((read) => read.strategyId === '7' && read.portfolioId === '1');
        expect(profileOneReads.length).toBeGreaterThan(0);
        expect(reads.every((read) => read.portfolioId === '1')).toBe(true);

        const currentRead = profileOneReads.at(-1);
        await resolveRead(currentRead, 7, 'Profile one current Strategy');
        await waitFor(() => expect(screen.getByLabelText('Name')).toHaveValue('Profile one current Strategy'));
        expect(screen.queryByText('Loading strategy…')).not.toBeInTheDocument();

        for (const staleDuplicate of profileOneReads.slice(0, -1).reverse()) {
            await resolveRead(staleDuplicate, 7, 'Late pre-current duplicate');
        }
        expect(screen.getByLabelText('Name')).toHaveValue('Profile one current Strategy');
        expect(screen.getByTestId('router-location')).toHaveTextContent('/strategy?strategy_id=7');
        expect(apiMock.put).not.toHaveBeenCalled();
    });

    it('consumes the real return route once and ignores late StrictMode and old-Strategy reads', async () => {
        const reads = [];
        installDeferredStrategyReads(reads);
        seedReturnDraft();
        renderActualStrategyPage('/strategy?strategy_id=7&screener_created=99');

        await waitFor(() => expect(reads.length).toBeGreaterThan(0));
        const afterInitialPhase = reads.length;
        fireEvent.click(screen.getByRole('button', { name: 'Re-select Profile 1' }));
        await waitFor(() => expect(reads.length).toBeGreaterThan(afterInitialPhase));
        const firstProfileEventReads = reads.slice(afterInitialPhase);

        const beforeSecondProfileEvent = reads.length;
        fireEvent.click(screen.getByRole('button', { name: 'Re-select Profile 1' }));
        await waitFor(() => expect(reads.length).toBeGreaterThan(beforeSecondProfileEvent));
        const secondProfileEventReads = reads.slice(beforeSecondProfileEvent);
        const overlappingStrategyReads = reads.filter((read) => read.strategyId === '7' && read.portfolioId === '1');
        expect(firstProfileEventReads.length).toBeGreaterThan(0);
        expect(secondProfileEventReads.length).toBeGreaterThan(0);
        expect(overlappingStrategyReads.length).toBeGreaterThanOrEqual(3);
        expect(reads.every((read) => read.portfolioId === '1')).toBe(true);

        const newestReturnRead = overlappingStrategyReads.at(-1);
        const staleReads = overlappingStrategyReads.filter((read) => read !== newestReturnRead);
        const staleReadAfterCurrent = firstProfileEventReads.at(-1);
        const staleReadsBeforeCurrent = staleReads
            .filter((read) => read !== staleReadAfterCurrent)
            .slice()
            .reverse();
        expect(staleReadsBeforeCurrent.length).toBeGreaterThan(0);
        for (const staleRead of staleReadsBeforeCurrent) {
            await resolveRead(staleRead, 7, 'Late old response');
        }
        expect(screen.getByText('Loading strategy…')).toBeInTheDocument();
        await resolveRead(newestReturnRead, 7, 'Server seven');
        await waitFor(() => expect(screen.getByTestId('router-location')).toHaveTextContent('/strategy?strategy_id=7'));
        expect(screen.getByRole('button', { name: 'Eligibility Sources' })).toHaveClass('btn-primary');
        expect(screen.getByRole('row', { name: /Contextual screen/ })).toBeInTheDocument();
        expect(readStrategyScreenerReturnDraft()).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'General' }));
        expect(screen.getByLabelText('Name')).toHaveValue('Unsaved contextual strategy');
        fireEvent.click(screen.getByRole('button', { name: 'Eligibility Sources' }));
        expect(screen.getByRole('row', { name: /Contextual screen/ })).toBeInTheDocument();

        await resolveRead(staleReadAfterCurrent, 7, 'Late post-restoration duplicate');
        expect(screen.getByTestId('router-location')).toHaveTextContent('/strategy?strategy_id=7');
        expect(screen.getByRole('button', { name: 'Eligibility Sources' })).toHaveClass('btn-primary');
        expect(screen.getByRole('row', { name: /Contextual screen/ })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'General' }));
        expect(screen.getByLabelText('Name')).toHaveValue('Unsaved contextual strategy');
        fireEvent.click(screen.getByRole('button', { name: 'Eligibility Sources' }));
        expect(screen.getByRole('button', { name: 'Eligibility Sources' })).toHaveClass('btn-primary');
        expect(screen.getByRole('row', { name: /Contextual screen/ })).toBeInTheDocument();

        const readsAfterReturnCleanup = reads.length;
        expect(readsAfterReturnCleanup).toBe(overlappingStrategyReads.length);
        expect(reads.length).toBe(readsAfterReturnCleanup);
        expect(screen.getByRole('button', { name: 'Eligibility Sources' })).toHaveClass('btn-primary');
        expect(screen.getByRole('row', { name: /Contextual screen/ })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Recommendation Thresholds' }));
        fireEvent.change(screen.getByDisplayValue('68'), { target: { value: '73' } });
        expect(screen.getByDisplayValue('73')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Portfolio Rules' }));
        expect(screen.getByLabelText('Hard maximum holdings')).toHaveValue('7');
        fireEvent.click(screen.getByRole('button', { name: 'Eligibility Sources' }));
        expect(screen.getByRole('row', { name: /Contextual screen/ })).toBeInTheDocument();

        const strategyEightStart = reads.length;
        fireEvent.click(screen.getByRole('button', { name: 'Navigate to Strategy 8' }));
        await waitFor(() => expect(reads.slice(strategyEightStart).some((read) => read.strategyId === '8')).toBe(true));
        const strategyEightReads = reads.slice(strategyEightStart).filter((read) => read.strategyId === '8');
        expect(strategyEightReads.length).toBeGreaterThan(0);
        const strategySevenReturnStart = reads.length;
        fireEvent.click(screen.getByRole('button', { name: 'Navigate to Strategy 7' }));
        await waitFor(() => expect(reads.slice(strategySevenReturnStart).some((read) => read.strategyId === '7')).toBe(true));
        const strategySevenReturnReads = reads.slice(strategySevenReturnStart).filter((read) => read.strategyId === '7');
        expect(strategySevenReturnReads.length).toBeGreaterThan(0);
        expect(screen.getByText('Loading strategy…')).toBeInTheDocument();

        for (const staleRead of strategyEightReads.slice().reverse()) {
            await resolveRead(staleRead, 8, 'Late Strategy eight response');
        }
        expect(screen.getByText('Loading strategy…')).toBeInTheDocument();
        await resolveRead(strategySevenReturnReads.at(-1), 7, 'Current Strategy seven response');
        fireEvent.click(screen.getByRole('button', { name: 'General' }));
        await waitFor(() => expect(screen.getByLabelText('Name')).toHaveValue('Current Strategy seven response'));
        for (const staleRead of strategySevenReturnReads.slice(0, -1).reverse()) {
            await resolveRead(staleRead, 7, 'Late duplicate Strategy seven response');
        }
        expect(screen.getByLabelText('Name')).toHaveValue('Current Strategy seven response');
        expect(screen.getByTestId('router-location')).toHaveTextContent('/strategy?strategy_id=7');
        expect(apiMock.put).not.toHaveBeenCalled();
    });

    it('keeps the unsaved Strategy form while the same profile refreshes in the background', async () => {
        const reads = [];
        const portfolioRequests = installDeferredStrategyReads(reads);
        seedReturnDraft();
        renderActualStrategyPage('/strategy?strategy_id=7');

        await waitFor(() => expect(reads.length).toBeGreaterThan(0));
        const initialRead = reads.at(-1);
        await resolveRead(initialRead, 7, 'Server seven');
        await waitFor(() => expect(screen.getByLabelText('Name')).toHaveValue('Server seven'));

        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Unsaved same-profile edit' } });
        expect(screen.getByLabelText('Name')).toHaveValue('Unsaved same-profile edit');
        const strategyReadCount = reads.length;
        const pendingPortfolioRefresh = portfolioRequests.deferNextPortfolioRead();
        fireEvent.click(screen.getByRole('button', { name: 'Refresh Profiles' }));
        await waitFor(() => expect(screen.getByTestId('portfolio-loading')).toHaveTextContent('loading'));
        expect(portfolioRequests.portfolioReads).toContain(pendingPortfolioRefresh);
        expect(screen.getByLabelText('Name')).toHaveValue('Unsaved same-profile edit');
        expect(reads).toHaveLength(strategyReadCount);

        await act(async () => {
            pendingPortfolioRefresh.resolve({ data: { data: [
                { id: 1, name: 'Profile one refreshed', is_default: true },
                { id: 2, name: 'Profile two', is_default: false },
            ] } });
        });
        await waitFor(() => expect(screen.getByTestId('portfolio-loading')).toHaveTextContent('idle'));
        expect(screen.getByLabelText('Name')).toHaveValue('Unsaved same-profile edit');
        expect(reads).toHaveLength(strategyReadCount);
        expect(getActivePortfolioId()).toBe('1');
        expect(apiMock.put).not.toHaveBeenCalled();
    });

    it('binds draft restoration to the request profile and refetches on the real profile-change event', async () => {
        const reads = [];
        installDeferredStrategyReads(reads);
        seedReturnDraft('1');
        renderActualStrategyPage('/strategy?strategy_id=7&screener_return=cancel');

        await waitFor(() => expect(reads.length).toBeGreaterThan(0));
        const profileOneReads = reads.filter((read) => read.strategyId === '7' && read.portfolioId === '1');
        expect(profileOneReads.length).toBeGreaterThan(0);
        const profileTwoStart = reads.length;
        fireEvent.click(screen.getByRole('button', { name: 'Switch to Profile 2' }));
        await waitFor(() => expect(reads.slice(profileTwoStart).some((read) => read.portfolioId === '2')).toBe(true));
        const profileTwoReads = reads.slice(profileTwoStart).filter((read) => read.strategyId === '7' && read.portfolioId === '2');
        expect(profileTwoReads.length).toBeGreaterThan(0);

        await act(async () => {
            for (const staleRead of profileOneReads.slice().reverse()) {
                staleRead.reject(new Error('Stale profile one read failed.'));
            }
        });
        expect(screen.getByText('Loading strategy…')).toBeInTheDocument();

        const currentProfileRead = profileTwoReads.at(-1);
        await resolveRead(currentProfileRead, 7, 'Profile two server Strategy', {
            status: 'active',
            is_enabled: true,
            setup_required: true,
            readiness: { requirements: [{ code: 'needs_review', message: 'Profile two needs review.' }] },
            reusable_artifact_uuid: 'profile-two-artifact',
            compatibility_read_only: true,
        });
        await waitFor(() => expect(screen.getByLabelText('Name')).toHaveValue('Profile two server Strategy'));
        expect(screen.getByTestId('router-location')).toHaveTextContent('/strategy?strategy_id=7');
        expect(screen.getByText(/This Strategy is bound to a published Artifact Library version/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save', exact: true })).toBeDisabled();
        expect(screen.getByText('Profile two needs review.')).toBeInTheDocument();
        expect(readStrategyScreenerReturnDraft()).toBeNull();
        expect(getActivePortfolioId()).toBe('2');
        for (const duplicateRead of profileTwoReads.slice(0, -1).reverse()) {
            await resolveRead(duplicateRead, 7, 'Late profile two duplicate');
        }
        expect(screen.getByLabelText('Name')).toHaveValue('Profile two server Strategy');
        expect(apiMock.put).not.toHaveBeenCalled();
    });
});
