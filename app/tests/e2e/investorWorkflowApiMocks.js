import { TEST_PORTFOLIO, TEST_USER, apiEnvelope } from '../js/tos/fixtures/tosApi.js';

function json(route, body, status = 200) {
    return route.fulfill({
        status,
        contentType: 'application/json',
        body: JSON.stringify(body),
    });
}

const SCREENER_META = {
    max_conditions: 40,
    indicators: [
        {
            id: 'ema',
            label: 'EMA',
            params: [{ id: 'period', label: 'Period', default: 50, min: 2, max: 400 }],
        },
    ],
    operators: [{ id: 'gt', label: '>' }],
    scopes: [{ id: 'holdings', label: 'Holdings' }],
    indexes: [],
};

/**
 * Auth + Screeners list/editor mocks for FEAT-064 investor workflow smoke.
 */
export async function installInvestorWorkflowApiMocks(page, options = {}) {
    let nextScreenerId = 99;
    let authenticated = !options.initiallyUnauthenticated;
    let remainingLoginFailures = options.failedLoginAttempts ?? 0;
    const fundamentalInsights = options.fundamentalInsights ?? {
        as_of: '2026-09-25',
        freshness: { status: 'fresh' },
        insights: {
            deterministic: {
                summary: 'Revenue and operating cash flow evidence are available for review.',
                data_sufficiency: {
                    rating: 'sufficient',
                    missing_information: [],
                },
                positive_signals: [{
                    signal_key: 'cash_quality_aligned',
                    title: 'Cash generation is aligned with reported earnings',
                    evidence: { basis: 'same-period comparison' },
                }],
                risk_signals: [],
                watch_items: [],
                follow_up_checks: ['Review the latest annual report cash-flow notes.'],
            },
            ai: { status: 'unavailable' },
            sector_context: null,
        },
    };
    const fundamentalsSnapshot = options.fundamentalsSnapshot ?? {
        as_of: '2026-09-25',
        market_price: 3500,
        freshness: { status: 'fresh' },
        summary: [
            {
                id: 'pe_ttm',
                label: 'P/E (TTM)',
                value: 27.5,
                basis: 'TTM',
                provenance: { source_label: 'Official NSE', derived: true, basis: 'TTM', latest_period_end: '2026-06-30' },
            },
            {
                id: 'revenue_ttm',
                label: 'Revenue (TTM)',
                value: 100000,
                basis: 'TTM',
                provenance: { source_label: 'Official NSE', derived: true, basis: 'TTM', latest_period_end: '2026-06-30' },
            },
        ],
        insights: null,
        coverage: { quarterly: { available: 4, expected: 4 }, annual: { available: 2, expected: 3 } },
    };
    const fundamentalsHistory = options.fundamentalsHistory ?? {
        periods: ['2026-06-30', '2026-03-31'],
        sections: {
            basic: [{ fact_key: 'revenue', label: 'Revenue', cells: [{ period_end: '2026-06-30', value: 100 }, { period_end: '2026-03-31', value: 95 }] }],
            advanced: [{ fact_key: 'capex', label: 'Capital expenditure', cells: [{ period_end: '2026-06-30', value: 12 }, { period_end: '2026-03-31', value: 10 }] }],
        },
    };
    const guidedTourState = {
        eligible: false,
        show_welcome_prompt: false,
        can_manual_relaunch: false,
        welcome_shown_at: '2026-01-01T00:00:00Z',
        completed_at: '2026-01-01T00:00:00Z',
        dismissed_at: null,
        current_step_id: null,
        tour_in_progress: false,
        ...(options.guidedTourState ?? {}),
    };

    await page.route(/\/(sanctum\/csrf-cookie|api\/)/, async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const path = url.pathname;
        const method = request.method();

        if (path.endsWith('/sanctum/csrf-cookie')) {
            return route.fulfill({ status: 204, body: '' });
        }
        if (path.endsWith('/api/auth/csrf-token')) {
            return json(route, { token: 'e2e-csrf' });
        }
        if (path.endsWith('/api/auth/me') && method === 'GET') {
            if (!authenticated) {
                return json(route, { message: 'Unauthenticated.' }, 401);
            }
            return json(route, { user: options.user ?? TEST_USER });
        }
        if (path.endsWith('/api/auth/login') && method === 'POST') {
            if (remainingLoginFailures > 0) {
                remainingLoginFailures -= 1;
                return json(route, { message: options.loginErrorMessage ?? 'Email or password is incorrect.' }, 422);
            }
            authenticated = true;
            return json(route, { user: options.user ?? TEST_USER });
        }
        if (path.endsWith('/api/portfolios') && method === 'GET') {
            return json(route, { data: [TEST_PORTFOLIO] });
        }
        if (path.endsWith('/api/holdings') && method === 'GET') {
            return json(route, { data: [] });
        }
        if (path.endsWith('/api/guided-tour') && method === 'GET') {
            return json(route, apiEnvelope(guidedTourState));
        }
        if (path.endsWith('/api/dashboard') && method === 'GET') {
            return json(route, apiEnvelope({}));
        }
        if (path.endsWith('/api/patterns/scan') && method === 'GET') {
            return json(route, apiEnvelope([]));
        }
        if (path.endsWith('/api/v1/protections') && method === 'GET') {
            return json(route, apiEnvelope([]));
        }
        if (path.endsWith('/api/calendar/upcoming') && method === 'GET') {
            return json(route, apiEnvelope([]));
        }
        if (path.endsWith('/api/stocks/search') && method === 'GET') {
            return json(route, { data: [{ id: 42, symbol: 'TCS', exchange: 'NSE', name: 'Tata Consultancy Services' }] });
        }
        const fundamentalsMatch = path.match(/\/api\/v1\/stocks\/(\d+)\/fundamentals$/);
        if (fundamentalsMatch && method === 'GET') {
            if (options.fundamentalInsightsError) {
                return json(route, { error: { message: options.fundamentalInsightsError } }, 503);
            }
            if (page.url().includes('/watchlist/')) {
                return json(route, { data: fundamentalsSnapshot });
            }
            return json(route, { data: fundamentalInsights });
        }
        if (path.endsWith('/api/v1/analytics/stocks/42') && method === 'GET') {
            return json(route, { data: { symbol: 'TCS', beta: 0.8 } });
        }
        if (path.endsWith('/api/v1/analytics/stocks/42/evaluation-profile') && method === 'GET') {
            return json(route, { data: { factors: [] } });
        }
        if (path.endsWith('/api/v1/analytics/stocks/42/recommendation-preview') && method === 'GET') {
            return json(route, { data: { available: false, reason: 'fixture' } });
        }
        if (path.endsWith('/api/v1/stocks/42/fundamentals') && method === 'GET') {
            return json(route, { data: fundamentalsSnapshot });
        }
        if (path.endsWith('/api/v1/stocks/42/fundamentals/history') && method === 'GET') {
            return json(route, { data: fundamentalsHistory });
        }
        if (path.endsWith('/api/v1/stocks/42/fundamentals/metrics/revenue/history') && method === 'GET') {
            return json(route, { data: { label: 'Revenue (TTM)', points: [{ period: '2026-06-30', value: 100000 }] } });
        }
        if (path.endsWith('/api/v1/stocks/42/fundamentals/metrics/pe/history') && method === 'GET') {
            return json(route, { data: { label: 'P/E (TTM)', points: [{ period: '2026-06-30', value: 27.5 }] } });
        }
        if (path.endsWith('/api/v1/stocks/42/fundamentals/metrics/pb/history') && method === 'GET') {
            return json(route, { data: { label: 'P/B (TTM)', points: [{ period: '2026-06-30', value: 5.1 }] } });
        }
        if (path.endsWith('/api/stocks/42/market-prices') && method === 'GET') {
            return json(route, { data: [], meta: { has_price_history: false } });
        }
        if (path.endsWith('/api/guided-tour') && (method === 'GET' || method === 'PUT')) {
            if (method === 'PUT') {
                const action = request.postDataJSON()?.action;
                if (action === 'begin') {
                    guidedTourState.tour_in_progress = true;
                    guidedTourState.completed_at = null;
                    guidedTourState.current_step_id = request.postDataJSON()?.step_id ?? 'navigation';
                } else if (action === 'update_step') {
                    guidedTourState.tour_in_progress = true;
                    guidedTourState.current_step_id = request.postDataJSON()?.step_id ?? guidedTourState.current_step_id;
                } else if (action === 'record_welcome_shown') {
                    guidedTourState.welcome_shown_at = '2026-01-02T00:00:00Z';
                } else if (action === 'complete') {
                    guidedTourState.tour_in_progress = false;
                    guidedTourState.completed_at = '2026-01-02T00:00:00Z';
                } else if (action === 'skip_prompt') {
                    guidedTourState.show_welcome_prompt = false;
                }
            }
            return json(route, apiEnvelope(guidedTourState));
        }
        if (path.endsWith('/api/logs/frontend')) {
            return json(route, { ok: true });
        }
        if (path.endsWith('/api/v1/execution/mode') && method === 'GET') {
            return json(route, apiEnvelope({
                execution_mode: 'manual',
                entitled: false,
                totp_enabled: false,
                blockers: [],
                can_submit_semi_automatic: false,
                can_submit_automatic: false,
            }));
        }
        if (path.endsWith('/api/v1/execution/state') && method === 'GET') {
            return json(route, apiEnvelope({
                execution_state: 'normal',
                broker_connected: false,
                active_orders: 0,
            }));
        }
        if (path.endsWith('/api/v1/strategy') && method === 'GET') {
            return json(route, {
                data: {
                    strategy_id: 7,
                    id: 7,
                    name: 'Incomplete E2E Strategy',
                    description: 'Saved but not executable',
                    status: 'draft',
                    is_enabled: false,
                    setup_required: true,
                    readiness: {
                        requirements: [
                            { code: 'eligibility_source_required', message: 'Add at least one eligibility Screener.' },
                        ],
                    },
                    indicators: [{ key: 'momentum_score', label: 'Momentum', enabled: true, weight: 100 }],
                    eligibility_sources: [],
                    portfolio_rules: { horizon_calendar_days: null, first_entry_pct: 50, max_holdings: 10 },
                    exit_strategy: { enabled: true, mode: 'any', rules: [] },
                    market_gates: [],
                },
            });
        }
        if (path.endsWith('/api/v1/strategy') && method === 'PUT') {
            return json(route, { data: { strategy_id: 7, id: 7, name: 'Incomplete E2E Strategy', setup_required: true } });
        }
        if (path.endsWith('/api/v1/strategy-registry') && method === 'GET') {
            return json(route, { data: [{ strategy_id: 7, id: 7, name: 'Incomplete E2E Strategy', status: 'draft', is_enabled: false, setup_required: true }] });
        }
        if (path.endsWith('/api/indexes') && method === 'GET') {
            return json(route, { data: { indexes: [] } });
        }
        if (path.endsWith('/api/screeners/meta') && method === 'GET') {
            return json(route, { data: SCREENER_META });
        }
        if (path.endsWith('/api/watchlists') && method === 'GET') {
            return json(route, { data: [] });
        }
        if (path.endsWith('/api/watchlist/membership') && method === 'GET') {
            return json(route, { data: { watchlist_ids: [] } });
        }
        if (path.endsWith('/api/screeners') && method === 'GET') {
            return json(route, { data: [], count: 0 });
        }
        if (path.endsWith('/api/screeners') && method === 'POST') {
            const payload = request.postDataJSON();
            const id = nextScreenerId++;
            const created = {
                id,
                name: payload?.name ?? 'E2E Screener',
                scope: payload?.scope ?? 'holdings',
                definition_json: payload?.definition_json,
                is_enabled: true,
                description: payload?.description ?? null,
                watchlist_id: null,
                index_symbol: null,
            };
            return json(route, { data: created }, 201);
        }
        const screenerMatch = path.match(/\/api\/screeners\/(\d+)$/);
        if (screenerMatch && method === 'GET') {
            return json(route, {
                data: {
                    id: Number(screenerMatch[1]),
                    name: 'E2E ROC Gate',
                    scope: 'holdings',
                    is_enabled: true,
                    definition_json: { root: { type: 'group', op: 'AND', children: [] } },
                    description: null,
                    watchlist_id: null,
                    index_symbol: null,
                },
            });
        }
        const runsMatch = path.match(/\/api\/screeners\/(\d+)\/runs$/);
        if (runsMatch && method === 'GET') {
            return json(route, { data: [] });
        }
        const backtestMatrixMatch = path.match(/\/api\/screeners\/(\d+)\/backtest\/matrix$/);
        if (backtestMatrixMatch && method === 'GET') {
            return json(route, { data: { windows: [], matrix: [] } });
        }

        return json(route, { success: false, error: { code: 'UNMOCKED', message: `${method} ${path}` } }, 501);
    });
}
