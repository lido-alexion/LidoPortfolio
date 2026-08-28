import { describe, expect, it } from 'vitest';
import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiMock, installDefaultTosHandlers } from './helpers/mockApi.js';
import { renderTosApp } from './helpers/renderTosApp.jsx';
import { SAMPLE_REVIEW_REPORT } from './fixtures/tosApi.js';
import { resolveDocKeywordFromPath } from '../../../resources/js/src/utils/documentationLinks.js';

function generatePostCall() {
    return apiMock.post.mock.calls.find((call) => call[0] === '/v1/reviews/generate');
}

describe('Review reports list / detail (V4-FEAT-009)', () => {
    it('renders stored report list columns without recomputing values', async () => {
        installDefaultTosHandlers({ reviews: [SAMPLE_REVIEW_REPORT] });
        renderTosApp({ route: '/review/reports' });

        expect(await screen.findByRole('heading', { name: 'Review reports' })).toBeInTheDocument();
        const table = await screen.findByTestId('review-reports-table');
        expect(within(table).getByText('ID')).toBeInTheDocument();
        expect(within(table).getByText('Period')).toBeInTheDocument();
        expect(within(table).getByText('Generated at')).toBeInTheDocument();
        expect(within(table).getByText('Status')).toBeInTheDocument();
        expect(within(table).getByText('Portfolio value')).toBeInTheDocument();
        expect(within(table).getByText('XIRR')).toBeInTheDocument();
        expect(within(table).getByText('12')).toBeInTheDocument();
        expect(within(table).getByText('2026-01-01 → 2026-03-31')).toBeInTheDocument();
        expect(within(table).getByText('completed')).toBeInTheDocument();
        expect(within(table).getByText('12.34%')).toBeInTheDocument();

        const listCall = apiMock.get.mock.calls.find((call) => call[0] === '/v1/reviews');
        expect(listCall[1].params).toEqual({ page: 1, pageSize: 20 });
        expect(screen.getAllByRole('link', { name: 'Review' }).length).toBeGreaterThan(0);
        expect(screen.queryByRole('link', { name: 'Review reports' })).not.toBeInTheDocument();
    });

    it('paginates with existing page / pageSize query params', async () => {
        const user = userEvent.setup();
        const page1 = { ...SAMPLE_REVIEW_REPORT, id: 1 };
        const page2 = { ...SAMPLE_REVIEW_REPORT, id: 21 };
        installDefaultTosHandlers({
            getReviews: async (config) => {
                const page = Number(config?.params?.page) || 1;
                if (page === 2) {
                    return {
                        items: [page2],
                        meta: { page: 2, pageSize: 20, total: 25, lastPage: 2 },
                    };
                }
                return {
                    items: [page1],
                    meta: { page: 1, pageSize: 20, total: 25, lastPage: 2 },
                };
            },
        });
        renderTosApp({ route: '/review/reports' });

        const table = await screen.findByTestId('review-reports-table');
        expect(within(table).getByText('1')).toBeInTheDocument();
        expect(screen.getByText('1–20 of 25')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Next' }));

        await waitFor(() => {
            expect(within(screen.getByTestId('review-reports-table')).getByText('21')).toBeInTheDocument();
        });
        const lastListCall = [...apiMock.get.mock.calls].reverse().find((call) => call[0] === '/v1/reviews');
        expect(lastListCall[1].params).toEqual({ page: 2, pageSize: 20 });
    });

    it('shows an empty state with Generate when total is 0', async () => {
        installDefaultTosHandlers({
            reviews: [],
            reviewsMeta: { page: 1, pageSize: 20, total: 0, lastPage: 1 },
        });
        renderTosApp({ route: '/review/reports' });

        const empty = await screen.findByTestId('review-reports-empty');
        expect(empty).toHaveTextContent(/No stored review reports yet/i);
        expect(within(empty).getByRole('button', { name: 'Generate' })).toBeInTheDocument();
        expect(screen.queryByTestId('review-reports-table')).not.toBeInTheDocument();
    });

    it('opens detail from row click and from Open', async () => {
        const user = userEvent.setup();
        installDefaultTosHandlers({ reviews: [SAMPLE_REVIEW_REPORT] });
        renderTosApp({ route: '/review/reports' });

        const table = await screen.findByTestId('review-reports-table');
        await user.click(within(table).getByText('12').closest('tr'));
        expect(await screen.findByRole('heading', { name: 'Review report' })).toBeInTheDocument();
        expect(screen.getByText('Back to reports')).toBeInTheDocument();
        expect(apiMock.get.mock.calls.some((call) => call[0] === '/v1/reviews/12')).toBe(true);

        await user.click(screen.getByRole('link', { name: 'Back to reports' }));
        const tableAgain = await screen.findByTestId('review-reports-table');
        await user.click(within(tableAgain).getByRole('button', { name: 'Open' }));
        expect(await screen.findByRole('heading', { name: 'Review report' })).toBeInTheDocument();
    });

    it('renders persisted metric cards, remaining table, Accepted (not executed), and methodology', async () => {
        installDefaultTosHandlers({
            reviews: [SAMPLE_REVIEW_REPORT],
            reviewById: { 12: SAMPLE_REVIEW_REPORT },
        });
        renderTosApp({ route: '/review/reports/12' });

        expect(await screen.findByText('Portfolio snapshot (report)')).toBeInTheDocument();
        expect(screen.getByText('Portfolio value (report)')).toBeInTheDocument();
        expect(screen.getByText('Invested value (report)')).toBeInTheDocument();
        expect(screen.getByText('Unrealized P/L (report)')).toBeInTheDocument();
        expect(screen.getByText('Realized P/L (report)')).toBeInTheDocument();
        expect(screen.getByText('XIRR (report)')).toBeInTheDocument();
        expect(screen.getByText('Win rate')).toBeInTheDocument();
        expect(screen.getByText('Average gain')).toBeInTheDocument();
        expect(screen.getByText('Average loss')).toBeInTheDocument();
        expect(screen.getByText('Profit factor')).toBeInTheDocument();
        expect(screen.getByText('Expectancy')).toBeInTheDocument();
        expect(screen.getByText('Accepted (not executed)')).toBeInTheDocument();
        expect(screen.getByText('Executed')).toBeInTheDocument();
        expect(screen.getByText('Closed sells')).toBeInTheDocument();
        expect(screen.getByText('Recommendations (period)')).toBeInTheDocument();
        expect(screen.getByText('Actionable recommendations')).toBeInTheDocument();
        expect(screen.getByText('Insight recommendations')).toBeInTheDocument();
        expect(screen.getByText('Rejected')).toBeInTheDocument();
        expect(screen.getByText('Deferred')).toBeInTheDocument();
        expect(screen.getByText('Pending review')).toBeInTheDocument();
        expect(screen.getByText('Insights published')).toBeInTheDocument();
        expect(screen.getByText('Acceptance rate')).toBeInTheDocument();
        expect(screen.getByText('12.34%')).toBeInTheDocument();

        const methodology = screen.getByTestId('review-report-methodology');
        expect(methodology).toHaveTextContent('Share of sell transactions with realized_pl > 0 in period');
        expect(methodology).toHaveTextContent('Sum gains / sum abs losses on sells');
        expect(methodology).toHaveTextContent('Net realized P/L / closed sells');
        expect(methodology).toHaveTextContent('(Accepted + Executed) / decided recommendations in period');
        expect(methodology).toHaveTextContent('win_rate');
    });

    it('uses existing API 404 behaviour for an unknown report id', async () => {
        installDefaultTosHandlers({ reviews: [SAMPLE_REVIEW_REPORT] });
        renderTosApp({ route: '/review/reports/404' });

        expect(await screen.findByTestId('review-report-not-found')).toBeInTheDocument();
        expect(within(screen.getByTestId('review-report-not-found')).getByText('Review report not found.')).toBeInTheDocument();
        expect(screen.getAllByRole('link', { name: 'Back to reports' }).length).toBeGreaterThan(0);
        expect(apiMock.get.mock.calls.some((call) => call[0] === '/v1/reviews/404')).toBe(true);
    });

    it('Generates without date query params and without a JSON body', async () => {
        const user = userEvent.setup();
        installDefaultTosHandlers({
            reviews: [SAMPLE_REVIEW_REPORT],
            generatedReport: SAMPLE_REVIEW_REPORT,
        });
        renderTosApp({ route: '/review/reports' });
        await screen.findByTestId('review-reports-table');

        await user.click(screen.getByRole('button', { name: 'Generate' }));

        await waitFor(() => {
            expect(generatePostCall()).toBeTruthy();
        });
        const [, body, config] = generatePostCall();
        expect(body).toBeNull();
        expect(config.params).toBeUndefined();
        expect(config.skipErrorToast).toBe(true);
        expect(JSON.stringify(body || {})).not.toMatch(/period_start|period_end/);
        expect(await screen.findByRole('heading', { name: 'Review report' })).toBeInTheDocument();
    });

    it('Generates with period_start and period_end as query parameters, not a JSON body', async () => {
        const user = userEvent.setup();
        installDefaultTosHandlers({
            reviews: [SAMPLE_REVIEW_REPORT],
            generatedReport: SAMPLE_REVIEW_REPORT,
        });
        renderTosApp({ route: '/review/reports' });
        await screen.findByTestId('review-reports-table');

        fireEvent.change(screen.getByLabelText('From (optional)'), { target: { value: '2026-01-01' } });
        fireEvent.change(screen.getByLabelText('To (optional)'), { target: { value: '2026-03-31' } });
        await user.click(screen.getByRole('button', { name: 'Generate' }));

        await waitFor(() => {
            expect(generatePostCall()).toBeTruthy();
        });
        const [, body, config] = generatePostCall();
        expect(body).toBeNull();
        expect(config.params).toEqual({
            period_start: '2026-01-01',
            period_end: '2026-03-31',
        });
        expect(JSON.stringify(body || {})).not.toMatch(/period_start|period_end/);
        expect(await screen.findByRole('heading', { name: 'Review report' })).toBeInTheDocument();
    });

    it('navigates from the live dashboard Reports control and keeps a single sidebar Review entry', async () => {
        const user = userEvent.setup();
        installDefaultTosHandlers({ reviews: [SAMPLE_REVIEW_REPORT] });
        renderTosApp({ route: '/review' });

        expect((await screen.findAllByRole('heading', { name: 'Review' })).length).toBeGreaterThan(0);
        expect(screen.getByRole('link', { name: 'Reports' })).toBeInTheDocument();
        await user.click(screen.getByRole('link', { name: 'Reports' }));
        expect(await screen.findByRole('heading', { name: 'Review reports' })).toBeInTheDocument();
        expect(screen.getAllByRole('link', { name: 'Review' }).length).toBeGreaterThan(0);
        expect(screen.queryByRole('link', { name: 'Review reports' })).not.toBeInTheDocument();
    });

    it('resolves help for reports routes to the reports topic, not the live dashboard', () => {
        expect(resolveDocKeywordFromPath('/review')).toBe('review');
        expect(resolveDocKeywordFromPath('/review/reports')).toBe('review-reports');
        expect(resolveDocKeywordFromPath('/review/reports/12')).toBe('review-reports');
    });
});
