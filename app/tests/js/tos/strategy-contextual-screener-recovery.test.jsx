import React from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { BrowserRouter, Route, Routes, useLocation, useNavigate } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import AuthContext from '../../../resources/js/src/context/AuthContext.jsx';
import { PortfolioProvider, usePortfolio } from '../../../resources/js/src/context/PortfolioContext.jsx';
import StrategyPage from '../../../resources/js/src/pages/StrategyPage.jsx';
import { getActivePortfolioId, setActivePortfolioId } from '../../../resources/js/src/portfolio/activePortfolioStorage.js';
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
    apiMock.get.mockImplementation((path, config) => {
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
}

function RouteProbeAndControls() {
    const navigate = useNavigate();
    const location = useLocation();
    const { setActivePortfolio } = usePortfolio();
    return (
        <>
            <output data-testid="router-location">{location.pathname}{location.search}</output>
            <button type="button" onClick={() => navigate('/strategy?strategy_id=8')}>Navigate to Strategy 8</button>
            <button type="button" onClick={() => navigate('/strategy?strategy_id=7')}>Navigate to Strategy 7</button>
            <button type="button" onClick={() => setActivePortfolio(2)}>Switch to Profile 2</button>
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
    it('consumes the real return route once and ignores late StrictMode and old-Strategy reads', async () => {
        const reads = [];
        installDeferredStrategyReads(reads);
        seedReturnDraft();
        renderActualStrategyPage('/strategy?strategy_id=7&screener_created=99');

        await waitFor(() => expect(reads.length).toBeGreaterThanOrEqual(2));
        const initialStrategyReads = reads.filter((read) => read.strategyId === '7' && read.portfolioId === '1');
        expect(initialStrategyReads.length).toBeGreaterThanOrEqual(2);
        const newestReturnRead = initialStrategyReads.at(-1);
        await resolveRead(newestReturnRead, 7, 'Server seven');
        await waitFor(() => expect(screen.getByTestId('router-location')).toHaveTextContent('/strategy?strategy_id=7'));
        expect(screen.getByRole('button', { name: 'Eligibility Sources' })).toHaveClass('btn-primary');
        expect(screen.getByRole('row', { name: /Contextual screen/ })).toBeInTheDocument();
        expect(readStrategyScreenerReturnDraft()).toBeNull();

        const readsAfterReturnCleanup = reads.length;
        expect(readsAfterReturnCleanup).toBe(initialStrategyReads.length);
        for (const staleRead of initialStrategyReads.slice(0, -1).reverse()) {
            await resolveRead(staleRead, 7, 'Late old response');
        }
        expect(reads.length).toBe(readsAfterReturnCleanup);
        expect(screen.getByRole('button', { name: 'Eligibility Sources' })).toHaveClass('btn-primary');
        expect(screen.getByRole('row', { name: /Contextual screen/ })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'General' }));
        expect(screen.getByLabelText('Name')).toHaveValue('Unsaved contextual strategy');
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

    it('binds draft restoration to the request profile and refetches on the real profile-change event', async () => {
        const reads = [];
        installDeferredStrategyReads(reads);
        seedReturnDraft('1');
        renderActualStrategyPage('/strategy?strategy_id=7&screener_return=cancel');

        await waitFor(() => expect(reads.length).toBeGreaterThanOrEqual(2));
        const profileOneReads = reads.filter((read) => read.strategyId === '7' && read.portfolioId === '1');
        expect(profileOneReads.length).toBeGreaterThanOrEqual(2);
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
