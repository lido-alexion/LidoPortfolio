/**
 * Representative /api/v1 payloads for TOS UI smoke tests.
 * Shapes follow TradingOsController serializeRecommendation / serializeCandidate + ApiEnvelope.
 */

export const TEST_USER = {
    id: 1,
    name: 'Smoke Tester',
    email: 'smoke@example.com',
    is_admin: false,
    default_portfolio_id: 1,
};

export const TEST_PORTFOLIO = {
    id: 1,
    name: 'Main',
    is_default: true,
};

export const OPEN_BUY_RECOMMENDATION = {
    id: 41,
    security_id: 7,
    symbol: 'INFY',
    name: 'Infosys Limited',
    recommendation_type: 'OPEN_POSITION',
    portfolio_action: 'OPEN_POSITION',
    ui_label: 'Open',
    market_opinion: {
        direction: 'bullish',
        strength: 'strong',
        confidence: 0.82,
        evidence: {
            passed_rules: ['trend_up', 'rs_ok'],
            indicators: { rsi: 62 },
        },
    },
    execution_plan: {
        position_target_amount: 100000,
        this_cycle_amount: 50000,
        filled_amount: 0,
        remaining_amount: 100000,
        is_first_entry: true,
    },
    current_allocation_pct: 0,
    target_allocation_pct: 8,
    suggested_allocation_pct: 8,
    reasoning: 'Trend template passed; first entry at half target.',
    confidence: 0.82,
    score: 78,
    strategy_score: 78,
    strategy_name: 'Minervini Strategy',
    factor_breakdown: [
        {
            key: 'trend',
            display_name: 'Trend',
            contribution: 20,
            max_contribution: 25,
            gated: false,
        },
    ],
    evidence: {
        passed_rules: ['trend_up', 'rs_ok'],
        indicators: { rsi: 62 },
        factor_breakdown: [
            {
                key: 'trend',
                display_name: 'Trend',
                contribution: 20,
                max_contribution: 25,
                gated: false,
            },
        ],
        strategy_name: 'Minervini Strategy',
    },
    failed_checks: [],
    reference_price: 1450.5,
    status: 'pending_review',
    lifecycle_status: 'pending_review',
    review_status: 'pending_review',
    execution_status: null,
    category: 'actionable',
    generated_at: '2026-08-27T10:00:00+05:30',
    can_review: true,
    can_reopen: false,
    can_execute_manually: false,
    capital_allocation_status: null,
    capital_request_id: null,
    position_target_amount: 100000,
    this_cycle_amount: 50000,
    reviews: [],
};

export const HOLD_INSIGHT = {
    id: 42,
    security_id: 8,
    symbol: 'TCS',
    name: 'Tata Consultancy Services',
    recommendation_type: 'HOLD_POSITION',
    portfolio_action: 'HOLD_POSITION',
    ui_label: 'Hold',
    market_opinion: { direction: 'neutral', strength: 'moderate', confidence: 0.55 },
    confidence: 0.55,
    score: 51,
    status: 'published',
    category: 'insight',
    can_review: false,
    generated_at: '2026-08-27T10:00:00+05:30',
    evidence: { passed_rules: [], indicators: {} },
    failed_checks: [],
};

export const DISCOVERY_CANDIDATE = {
    id: 11,
    discovery_run_id: 3,
    security_id: 7,
    symbol: 'INFY',
    name: 'Infosys Limited',
    source: 'screener',
    discovery_reason: 'Minervini Trend Template',
    evidence: { signals: [{ id: 'screener', label: 'Minervini Trend Template' }] },
    evaluation_result_id: 22,
    score: 78,
    confidence: 0.82,
    rank: 1,
    explanation: 'Passed: trend_up, rs_ok',
    indicators: { rsi: 62 },
    component_scores: { trend: 20 },
    passed_rules: ['trend_up', 'rs_ok'],
    failed_rules: [],
    created_at: '2026-08-27T09:00:00+05:30',
};

export const CAPITAL_RESOLUTION = {
    capital_resolution_state: 'resolved_at_actual',
    requested_investment_amount: 50000,
    own_capital_used: 50000,
    recalled_capital_requested: 0,
    recalled_capital_received: 0,
    bridge_capital_used: 0,
    total_immediately_available: 50000,
    actual_execution_amount: 50000,
    unresolved_amount: 0,
};

export const PIPELINE_STAGES = {
    discovery: { candidates: 4 },
    evaluation: { results: 4 },
    recommendation: { count: 2 },
};

export function apiEnvelope(data, meta = {}) {
    return { success: true, data, meta };
}

export function axiosOk(body) {
    return { data: body };
}
