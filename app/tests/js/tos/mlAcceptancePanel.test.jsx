import React from 'react';
import { beforeEach, afterEach, describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, waitFor, cleanup, act } from '@testing-library/react';
import MlAcceptancePanel from '../../../resources/js/src/pages/MlAcceptancePanel';
import api from '../../../resources/js/src/api';

vi.mock('../../../resources/js/src/api', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }));
const steps = (current = 2, discovery = 'action_required') => [
    { id: 'readiness', status: 'complete', reason_codes: [] }, { id: 'discovery', status: discovery, reason_codes: discovery === 'action_required' ? ['preflight_not_recorded'] : [] },
    { id: 'sources', status: 'blocked', reason_codes: [] }, { id: 'backfill', status: 'blocked', reason_codes: [] },
    { id: 'final_preflight', status: 'blocked', reason_codes: [] }, { id: 'training', status: 'blocked', reason_codes: [] }, { id: 'review', status: 'action_required', reason_codes: [] },
];
const wizard = (overrides = {}) => ({
    reported_at: '2026-10-16T00:00:00Z', current_step: 2, steps: steps(), campaign: null, backfill: null,
    required_sources: [], horizons: {}, migrations: {}, readiness: { ready: false },
    identity: { build_id: 'build-a', environment: 'production', registry_version: 'registry-13', configuration_sha256: 'config-a', commit_sha: 'a'.repeat(40) },
    runtime: { queue_configuration_ready: true }, lifecycle: { enabled: false, drift_trigger_enabled: false, horizons: {} },
    ...overrides,
});
const sourcePage = { data: [], current_page: 1, last_page: 1 };
let state;
beforeEach(() => {
    vi.clearAllMocks();
    state = wizard();
    api.get.mockImplementation(async (url) => ({ data: { data: url.endsWith('/wizard') ? state : sourcePage } }));
    api.post.mockResolvedValue({ data: { data: {} } });
});
afterEach(() => { cleanup(); vi.restoreAllMocks(); });

describe('ML acceptance wizard', () => {
    it('loads server-owned step state and renders all seven steps without mutating', async () => {
        render(<MlAcceptancePanel />);
        await screen.findByText('Run discovery preflight');
        expect(screen.getByRole('navigation', { name: 'Production acceptance steps' })).toBeInTheDocument();
        for (const name of ['Understand & check readiness', 'Discover required data', 'Add historical sources', 'Preview & apply backfill', 'Run final preflight', 'Train candidates', 'Review completion']) {
            expect(screen.getByRole('navigation').textContent).toContain(name);
        }
        expect(api.get).toHaveBeenCalledWith('/v1/admin/ml/acceptance/wizard');
        expect(api.post).not.toHaveBeenCalled();
        expect(api.put).not.toHaveBeenCalled();
    });

    it('allows discovery before source upload and clearly explains that it does not train', async () => {
        render(<MlAcceptancePanel />);
        await screen.findByRole('button', { name: 'Run discovery preflight' });
        expect(screen.getByText(/Preflight checks the canonical datasets/)).toBeInTheDocument();
        expect(screen.getByLabelText('Training cutoff', { selector: 'input' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Run discovery preflight' })).toBeEnabled();
    });

    it('shows only the server-authorized recovery action for an existing backfill', async () => {
        const base = { current_step: 4, campaign: { id: 'campaign-remediation', status: 'blocked' },
            required_sources: [{ date: '2026-09-01', horizons: ['1m'], accepted_families: ['nse_cash_bhavcopy'], sources: [] }],
            steps: ['complete', 'complete', 'complete', 'in_progress', 'blocked', 'blocked', 'action_required'].map((status, i) => ({ id: steps()[i].id, status, reason_codes: [] })),
            backfill: { id: 44, status: 'running', mode: 'preview', cursor: 0, requested_dates: ['2026-09-01'], can_apply: false, can_retry_preview: false, resumable: false } };
        state = wizard(base);
        render(<MlAcceptancePanel />);
        expect(await screen.findByText(/Backfill status:/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Run safe preview' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Resume backfill' })).not.toBeInTheDocument();

        state = wizard({ ...base, backfill: { ...base.backfill, status: 'failed', resumable: true } });
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        expect(await screen.findByRole('button', { name: 'Resume backfill' })).toBeEnabled();
        expect(screen.queryByRole('button', { name: 'Run safe preview' })).not.toBeInTheDocument();
        expect(api.post).not.toHaveBeenCalled();
    });

    it('shows plain-language copy and preserves unknown codes in advanced diagnostics', async () => {
        state = wizard({ current_step: 2, steps: [{ ...steps()[0] }, { id: 'discovery', status: 'blocked', reason_codes: ['unknown_new_blocker'] }, ...steps().slice(2)] });
        render(<MlAcceptancePanel />);
        await screen.findByText(/unrecognized blocker/i);
        expect(screen.getByText(/Open Advanced diagnostics and copy the blocker code/)).toBeInTheDocument();
        const details = screen.getByText('Advanced diagnostics').closest('details');
        expect(details.open).toBe(false);
        fireEvent.click(screen.getByText('Advanced diagnostics'));
        expect(details.open).toBe(true);
        expect(details.textContent).toContain('unknown_new_blocker');
    });

    it('routes a source-missing discovery result to source step and keeps read-only steps safe', async () => {
        state = wizard({ current_step: 3, steps: [
            { id: 'readiness', status: 'complete', reason_codes: [] }, { id: 'discovery', status: 'complete', reason_codes: [] },
            { id: 'sources', status: 'action_required', reason_codes: ['missing_required_source_date'] }, ...steps().slice(3),
        ], required_sources: [{ date: '2026-09-01', horizons: ['1m'], accepted_families: ['nse_cash_bhavcopy'],
            sources: [{ id: 'sealed-1', status: 'sealed', family: 'nse_cash_bhavcopy', date: '2026-09-01' }] }] });
        api.get.mockImplementation(async (url) => ({ data: { data: url.includes('/sources') ? { ...sourcePage, data: [{ id: 'sealed-1', status: 'sealed', received: 10, manifest: { filename: 'cm01SEP2026bhav.csv', date: '2026-09-01', source: 'nse_cash_bhavcopy' } }] } : state } }));
        render(<MlAcceptancePanel />);
        expect(await screen.findByText('2026-09-01 — 1m horizon(s)')).toBeInTheDocument();
        expect(screen.getByRole('checkbox', { name: 'Select cm01SEP2026bhav.csv' })).toBeEnabled();
        await waitFor(() => expect(screen.getByRole('checkbox', { name: 'Select cm01SEP2026bhav.csv' })).toBeChecked());
        expect(api.post).not.toHaveBeenCalled();
    });

    it('continues from selected sealed sources into Backfill and runs an explicit safe preview', async () => {
        state = wizard({ current_step: 3, campaign: { id: 'campaign-sources', status: 'blocked', cutoff_date: '2026-10-01' }, steps: [
            { id: 'readiness', status: 'complete', reason_codes: [] }, { id: 'discovery', status: 'complete', reason_codes: [] },
            { id: 'sources', status: 'action_required', reason_codes: ['missing_required_source_date'] }, { id: 'backfill', status: 'action_required', reason_codes: [] }, ...steps().slice(4),
        ], required_sources: [{ date: '2026-09-01', horizons: ['1m'], accepted_families: ['nse_cash_bhavcopy'],
            sources: [{ id: 'sealed-1', status: 'sealed', family: 'nse_cash_bhavcopy', date: '2026-09-01' }] }] });
        api.get.mockImplementation(async (url) => ({ data: { data: url.includes('/sources')
            ? { ...sourcePage, data: [{ id: 'sealed-1', status: 'sealed', received: 10, manifest: { filename: 'cm01SEP2026bhav.csv', date: '2026-09-01', source: 'nse_cash_bhavcopy' } }] }
            : state } }));
        render(<MlAcceptancePanel />);

        await screen.findByText('2026-09-01 — 1m horizon(s)');
        const source = await screen.findByRole('checkbox', { name: 'Select cm01SEP2026bhav.csv' });
        await waitFor(() => expect(source).toBeChecked());
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(await screen.findByRole('heading', { name: 'Preview & apply backfill' })).toBeInTheDocument();
        const preview = await screen.findByRole('button', { name: 'Run safe preview' });
        fireEvent.click(preview);
        await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/admin/ml/acceptance/backfills/preview', expect.objectContaining({
            campaign_id: 'campaign-sources', sources: ['sealed-1'],
        })));
    });

    it('requires a successful preview and explicit confirmation before backfill apply', async () => {
        state = wizard({ current_step: 4, steps: [
            { id: 'readiness', status: 'complete', reason_codes: [] }, { id: 'discovery', status: 'complete', reason_codes: [] },
            { id: 'sources', status: 'complete', reason_codes: [] }, { id: 'backfill', status: 'action_required', reason_codes: [] }, ...steps().slice(4),
        ], backfill: { id: 42, status: 'completed', mode: 'preview', can_apply: true, cursor: 1, requested_dates: ['2026-09-01'], results: { '2026-09-01': { snapshot_sha256: 'abc', diagnostics: { mapping_percentage: 95 } } } } });
        render(<MlAcceptancePanel />);
        fireEvent.click(await screen.findByRole('button', { name: 'Apply reviewed backfill' }));
        expect(await screen.findByRole('alertdialog')).toHaveTextContent('Matching proven data is idempotent');
        expect(api.post).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Confirm apply' }));
        await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/admin/ml/acceptance/backfills/42/apply'));
    });

    it('requires explicit training confirmation and says all horizons remain candidates', async () => {
        state = wizard({ current_step: 6, campaign: { id: 'campaign-1', status: 'ready', cutoff_date: '2026-10-01', identity: { build_id: 'build-a', configuration_sha256: 'config-a' } },
            steps: ['complete', 'complete', 'not_required', 'not_required', 'complete', 'action_required', 'blocked'].map((status, i) => ({ id: steps()[i].id, status, reason_codes: [] })) });
        render(<MlAcceptancePanel />);
        fireEvent.click(await screen.findByRole('button', { name: 'Start 1m/3m/6m candidate training' }));
        expect(await screen.findByRole('alertdialog')).toHaveTextContent('active models, schedules and drift settings will not change');
        expect(api.post).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Confirm and start' }));
        await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/admin/ml/acceptance/campaigns/campaign-1/start'));
    });

    it('distinguishes quality rejection from operational failure', async () => {
        state = wizard({ current_step: 7, campaign: { id: 'campaign-2', status: 'qualified', qualified_at: '2026-10-16T00:00:00Z' },
            steps: steps(7, 'complete').map((item) => ({ ...item, status: 'complete' })), evidence_current: true,
            horizons: { '1m': { training: { run_id: 1, status: 'completed_rejected', evidence_complete: true } } }, readiness: { ready: true } });
        render(<MlAcceptancePanel />);
        expect(await screen.findByText('Production acceptance is current for this build and configuration.')).toBeInTheDocument();
        expect(screen.getByText('Training run 1: Completed — quality gates not met')).toBeInTheDocument();
        expect(screen.getByText(/Promotion, schedules, lifecycle automation and drift are separate actions/)).toBeInTheDocument();
        expect(api.post).not.toHaveBeenCalled();
    });

    it('refreshes authoritative state after an idle-to-active successful mutation', async () => {
        api.post.mockImplementationOnce(async () => {
            state = wizard({ current_step: 2, campaign: { id: 'campaign-new', status: 'preflight', cutoff_date: '2026-10-16' }, steps: steps(2, 'in_progress') });
            return { data: { data: { id: 'campaign-new' } } };
        });
        render(<MlAcceptancePanel />);
        fireEvent.click(await screen.findByRole('button', { name: 'Run discovery preflight' }));
        expect(await screen.findByText('campaign-new')).toBeInTheDocument();
        expect(screen.getByText(/preflight; cutoff/)).toBeInTheDocument();
        await waitFor(() => expect(api.get.mock.calls.filter(([url]) => url.endsWith('/wizard')).length).toBeGreaterThanOrEqual(2));
        expect(screen.queryByRole('button', { name: 'Run discovery preflight' })).not.toBeInTheDocument();
        expect(api.post).toHaveBeenCalledTimes(1);
    });

    it('keeps an uncertain action message and suppresses a duplicate when refresh shows it started', async () => {
        const timeout = Object.assign(new Error('timeout'), { code: 'ECONNABORTED' });
        api.post.mockImplementationOnce(async () => {
            state = wizard({ current_step: 2, campaign: { id: 'campaign-accepted', status: 'preflight', cutoff_date: '2026-10-16' }, steps: steps(2, 'in_progress') });
            throw timeout;
        });
        render(<MlAcceptancePanel />);
        fireEvent.click(await screen.findByRole('button', { name: 'Run discovery preflight' }));
        expect(await screen.findByText(/may have accepted the action/)).toBeInTheDocument();
        await waitFor(() => expect(api.get.mock.calls.filter(([url]) => url.endsWith('/wizard')).length).toBeGreaterThanOrEqual(2));
        expect(await screen.findByText('campaign-accepted')).toBeInTheDocument();
        expect(screen.getByText(/preflight; cutoff/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Run discovery preflight' })).not.toBeInTheDocument();
        expect(api.post).toHaveBeenCalledTimes(1);
    });

    it('recovers from a failed status poll without discarding the page', async () => {
        let failFirstWizardGet = true;
        api.get.mockImplementation(async (url) => {
            if (url.endsWith('/wizard') && failFirstWizardGet) { failFirstWizardGet = false; throw new Error('network'); }
            return { data: { data: url.endsWith('/wizard') ? state : sourcePage } };
        });
        render(<MlAcceptancePanel />);
        expect(await screen.findByText(/Status refresh failed/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Retry status' }));
        expect(await screen.findByRole('button', { name: 'Run discovery preflight' })).toBeInTheDocument();
        expect(screen.queryByText(/Status refresh failed/)).not.toBeInTheDocument();
    });

    it('keeps polling through repeated transient failures and recovers without a manual retry', async () => {
        vi.useFakeTimers();
        state = wizard({ current_step: 2, campaign: { id: 'campaign-polling', status: 'preflight', cutoff_date: '2026-10-16' }, steps: steps(2, 'in_progress') });
        let wizardReads = 0;
        api.get.mockImplementation(async (url) => {
            if (url.endsWith('/wizard')) {
                wizardReads += 1;
                if (wizardReads === 2 || wizardReads === 3) throw new Error('transient poll failure');
                return { data: { data: state } };
            }
            return { data: { data: sourcePage } };
        });
        render(<MlAcceptancePanel />);
        await act(async () => { await Promise.resolve(); await Promise.resolve(); });
        expect(screen.getByText('campaign-polling')).toBeInTheDocument();
        await act(async () => { await vi.advanceTimersByTimeAsync(5000); });
        await act(async () => { await vi.advanceTimersByTimeAsync(5000); });
        await act(async () => { await vi.advanceTimersByTimeAsync(5000); });
        expect(wizardReads).toBeGreaterThanOrEqual(4);
        expect(screen.queryByText(/Status refresh failed/)).not.toBeInTheDocument();
        expect(screen.getByText('campaign-polling')).toBeInTheDocument();
        vi.useRealTimers();
    });

    it('ignores an older refresh response after a newer authoritative response', async () => {
        let releaseOlder;
        let reads = 0;
        api.get.mockImplementation((url) => {
            if (!url.endsWith('/wizard')) return Promise.resolve({ data: { data: sourcePage } });
            reads += 1;
            if (reads === 1) return Promise.resolve({ data: { data: state } });
            if (reads === 2) return new Promise((resolve) => { releaseOlder = resolve; });
            state = wizard({ current_step: 2, campaign: { id: 'newer-campaign', status: 'blocked' } });
            return Promise.resolve({ data: { data: state } });
        });
        render(<MlAcceptancePanel />);
        await screen.findByText('Run discovery preflight');
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        expect(await screen.findByText('newer-campaign')).toBeInTheDocument();
        releaseOlder({ data: { data: wizard({ current_step: 2, campaign: { id: 'older-campaign', status: 'preflight' } }) } });
        await act(async () => { await Promise.resolve(); await Promise.resolve(); });
        expect(screen.getByText('newer-campaign')).toBeInTheDocument();
        expect(screen.queryByText('older-campaign')).not.toBeInTheDocument();
    });

    it('uses backend evidence freshness verbatim for qualified completion', async () => {
        state = wizard({ current_step: 5, campaign: { id: 'campaign-stale', status: 'qualified', qualified_at: '2026-10-16T00:00:00Z', identity: { build_id: 'old-build', registry: 'registry-12', configuration_sha256: 'old-config', commit_sha: 'b'.repeat(40) } },
            evidence_current: false, readiness: { ready: true },
            steps: ['complete', 'complete', 'not_required', 'not_required', 'action_required', 'complete', 'action_required'].map((status, index) => ({ id: steps()[index].id, status, reason_codes: [] })) });
        render(<MlAcceptancePanel />);
        fireEvent.click(await screen.findByRole('button', { name: /Review completion/ }));
        expect(await screen.findByText(/Saved evidence is stale/)).toBeInTheDocument();
        expect(screen.getByText(/old-build \/ build-a/)).toBeInTheDocument();
        expect(screen.queryByText('Production acceptance is current for this build and configuration.')).not.toBeInTheDocument();
    });
});
