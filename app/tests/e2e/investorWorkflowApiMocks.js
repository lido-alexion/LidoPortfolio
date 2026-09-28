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
            return json(route, { user: TEST_USER });
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
            return json(route, { data: fundamentalInsights });
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
