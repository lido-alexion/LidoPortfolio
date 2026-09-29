/**
 * Stable V9 journey/help metadata. The generated index is intentionally local
 * and deterministic: it contains no permissions or account data.
 */
export const JOURNEY_METADATA_VERSION = 'v9.ux001.1';

export const JOURNEY_TOPICS = Object.freeze([
    {
        id: 'SCR-01', title: 'How do I create a screener?', aliases: ['new screener', 'create screener'],
        keywords: ['screener', 'condition', 'indicator', 'save'], synonyms: ['screen stocks', 'discovery rule'],
        category: 'Screeners', route: '/screeners', guide: '/docs/screeners.html#scr-01--create-a-screener-from-scratch',
        steps: ['Open Screeners.', 'Start a new screener.', 'Add and configure the supported conditions.', 'Review and save the definition.', 'Validate it before using it in a strategy.'],
        prerequisites: [], warnings: ['Saving a screener does not create a recommendation or broker order.'],
    },
    {
        id: 'SCR-06', title: 'How do I run a screener and inspect matches?', aliases: ['screener results', 'run screener'],
        keywords: ['screener', 'run', 'matches', 'candidate'], synonyms: ['find matching stocks'], category: 'Screeners', route: '/screeners', guide: '/docs/screeners.html#scr-06--run-a-screener-and-inspect-matches',
        steps: ['Open Screeners and select the definition.', 'Run it and wait for completion.', 'Inspect matches and run metadata.', 'Open evidence for an interesting stock.'], prerequisites: [], warnings: ['An in-progress or failed run is not an empty result.'],
    },
    {
        id: 'STR-01', title: 'How do I create a strategy?', aliases: ['new strategy', 'configure strategy'], keywords: ['strategy', 'policy', 'screener', 'enable'], synonyms: ['investment policy'], category: 'Strategies', route: '/strategy', guide: '/docs/strategies.html#str-01--create-a-strategy-using-an-existing-screener',
        steps: ['Confirm the required screener exists and is valid.', 'Open Strategy and start a new strategy.', 'Select the screener and configure policy.', 'Review the complete policy and save it.', 'Enable it when ready for pipeline runs.'], prerequisites: [], warnings: ['Saving strategy configuration does not generate or approve recommendations.'],
    },
    {
        id: 'REC-02', title: 'How do I review recommendations?', aliases: ['review recommendation', 'new recommendations'], keywords: ['recommendation', 'review', 'evidence', 'approve'], synonyms: ['investment ideas'], category: 'Recommendations', route: '/recommendations', guide: '/docs/recommendations.html#rec-02--review-new-recommendations',
        steps: ['Open Recommendations.', 'Filter or sort the current records.', 'Inspect action, strategy, evidence, capital and status.', 'Open the detail view before deciding what to do.'], prerequisites: [], warnings: ['WATCH and HOLD are informational and are not trades to approve.'],
    },
    {
        id: 'REC-07', title: 'How do I approve a recommendation?', aliases: ['approve idea', 'approve trade'], keywords: ['recommendation', 'approve', 'pending execution'], synonyms: ['authorize execution'], category: 'Recommendations', route: '/recommendations', guide: '/docs/recommendations.html#rec-07--approve-a-recommendation',
        steps: ['Open the recommendation detail.', 'Review the action, evidence, capital state and executable amount.', 'Approve it using the supported approval control.', 'Open Pending Execution for the final execution check.'], prerequisites: ['A valid actionable recommendation is required.'], warnings: ['Approval authorizes the execution workflow; it is not broker submission.'],
    },
    {
        id: 'EXE-05', title: 'How do I review pending execution?', aliases: ['pending orders', 'pending execution'], keywords: ['pending', 'execution', 'order', 'quantity'], synonyms: ['check approved trade'], category: 'Execution', route: '/transactions/pending', guide: '/docs/execution.html#exe-05--review-pending-execution',
        steps: ['Open Pending Execution.', 'Review the recommendation, quantity, capital and readiness.', 'Confirm the intended execution mode.', 'Continue only when the final check is complete.'], prerequisites: ['An approved actionable recommendation is required.'], warnings: ['Do not treat broker submission as complete until the broker state is confirmed.'],
    },
    {
        id: 'EXE-08', title: 'How do I cancel before broker submission?', aliases: ['cancel execution', 'stop order'], keywords: ['cancel', 'execution', 'broker'], synonyms: ['stop a trade'], category: 'Execution', route: '/transactions/pending', guide: '/docs/execution.html#exe-08--cancel-before-broker-submission',
        steps: ['Open Pending Execution.', 'Select the approved execution that has not been submitted.', 'Use the cancellation control.', 'Confirm the final lifecycle state and any released reservation.'], prerequisites: ['The broker order must not have been submitted.'], warnings: ['A submitted broker order follows the separate broker-cancellation journey.'],
    },
    {
        id: 'EXE-15', title: 'How do I verify a transaction after execution?', aliases: ['verify trade', 'holding after execution'], keywords: ['transaction', 'holding', 'verify', 'fill'], synonyms: ['check completed trade'], category: 'Transactions', route: '/transactions', guide: '/docs/execution.html#exe-15--verify-transaction-and-holding-after-execution',
        steps: ['Open Transactions and locate the execution.', 'Confirm the fill, quantity, price and broker/manual evidence.', 'Open Holdings and verify the strategy-owned position.', 'Reconcile any partial or uncertain outcome before retrying.'], prerequisites: [], warnings: ['Never infer a fill from an order request alone.'],
    },
    {
        id: 'E2E-01', title: 'How do I go from a new idea to a first BUY?', aliases: ['complete investment workflow', 'idea to buy'], keywords: ['screener', 'strategy', 'recommendation', 'buy', 'workflow'], synonyms: ['end to end'], category: 'End-to-end journeys', route: '/', guide: '/docs/end-to-end.html#e2e-01--new-idea-to-first-buy',
        steps: ['Create and validate the discovery rule.', 'Create, configure and enable the strategy.', 'Run the decision pipeline and inspect the recommendation.', 'Approve it and review Pending Execution.', 'Execute safely and verify the transaction and holding.'], prerequisites: [], warnings: ['Automation and help search never place real money-moving orders.'],
    },
]);

const STOP_WORDS = new Set(['a', 'an', 'and', 'do', 'how', 'i', 'the', 'to']);

export function normalizeHelpQuery(value) {
    return String(value || '').toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g, ' ').trim();
}

function tokens(value) {
    return normalizeHelpQuery(value).split(/\s+/).filter((token) => token && !STOP_WORDS.has(token));
}

function scoreTopic(topic, query, currentPath = '', history = []) {
    const normalized = normalizeHelpQuery(query);
    if (!normalized) return 0;
    const qTokens = new Set(tokens(normalized));
    let score = 0;
    const exact = [topic.title, ...(topic.aliases || [])].map(normalizeHelpQuery);
    if (exact.includes(normalized)) score += 100;
    for (const token of qTokens) {
        if (tokens(topic.title).includes(token)) score += 24;
        if ((topic.aliases || []).some((value) => tokens(value).includes(token))) score += 16;
        if ((topic.keywords || []).some((value) => tokens(value).includes(token))) score += 10;
        if ((topic.synonyms || []).some((value) => tokens(value).includes(token))) score += 7;
    }
    if (currentPath && topic.route === currentPath) score += 6;
    if (history.includes(topic.id)) score += 2;
    return score;
}

export function searchJourneyTopics(query, { currentPath = '', history = [], limit = 6 } = {}) {
    return JOURNEY_TOPICS.map((topic) => ({
        ...topic,
        score: scoreTopic(topic, query, currentPath, history),
    }))
        .filter((topic) => topic.score > 0)
        .sort((a, b) => b.score - a.score || a.id.localeCompare(b.id))
        .slice(0, limit);
}

export function explainJourneyMatch(topic, query) {
    const q = new Set(tokens(query));
    const matched = [topic.title, ...(topic.aliases || []), ...(topic.keywords || []), ...(topic.synonyms || [])]
        .flatMap(tokens)
        .filter((token) => q.has(token));
    return matched.length ? `Matched ${[...new Set(matched)].slice(0, 3).join(', ')}` : 'Related StoX journey';
}
