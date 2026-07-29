/**
 * Presentation metadata for in-app documentation.
 * Adds workflow, purpose, icons, callouts, mistakes, and diagrams without
 * removing technical prose from appDocumentation.js.
 */

/** Shared Trading OS spine used by many topics. */
export const TRADING_OS_SPINE = [
    { label: 'Dashboard', keyword: 'dashboard' },
    { label: 'Screeners', keyword: 'screener' },
    { label: 'Strategy', keyword: 'strategy' },
    { label: 'Recommendations', keyword: 'recommendations' },
    { label: 'Pending Execution', keyword: 'pending-execution' },
    { label: 'Transactions', keyword: 'transactions' },
    { label: 'Review', keyword: 'review' },
];

function spineWithCurrent(currentKeyword) {
    return TRADING_OS_SPINE.map((step) => ({
        ...step,
        current: step.keyword === currentKeyword,
    }));
}

/**
 * @typedef {{
 *   icon?: string,
 *   purpose?: string,
 *   workflow?: Array<{ label: string, keyword?: string, current?: boolean }>,
 *   callouts?: Array<{ variant?: string, title?: string, body: string }>,
 *   comparisons?: Array<{ caption?: string, headers: string[], rows: string[][] }>,
 *   decisions?: Array<{ tone: 'allow'|'caution'|'block', title: string, body: string }>,
 *   behindTheScenes?: { summary?: string, mermaid?: string },
 *   commonMistakes?: Array<{ q: string, a: string }>,
 *   conceptBoxes?: Array<{ title: string, icon?: string, rows: Array<{ label: string, value: string }> }>,
 * }} DocPresentation
 */

/** @type {Record<string, DocPresentation>} */
export const DOC_PRESENTATION = {
    overview: {
        icon: 'bi-journal-richtext',
        purpose:
            'This index is the map of every screen in Lido Alexion. Use it to learn what a page is for before you change settings or act on recommendations.',
        workflow: [
            { label: 'Open any app screen', keyword: 'dashboard' },
            { label: 'Tap header (?)', current: true },
            { label: 'Read topic + related links', keyword: 'trading-os-flow' },
            { label: 'Return and take action', keyword: 'recommendations' },
        ],
        callouts: [
            {
                variant: 'tip',
                title: 'Public docs',
                body: 'Documentation does not require login. Product screens linked from topics still need sign-in.',
            },
        ],
        commonMistakes: [
            {
                q: 'Why did (?) open a different topic than I expected?',
                a: 'The button maps the current route to a keyword. Nested routes (e.g. screener editor) have their own topics.',
            },
        ],
    },
    'trading-os-flow': {
        icon: 'bi-diagram-3',
        purpose:
            'Explains which Trading OS page owns which job, and how a stock moves from eligibility to a filled trade — without treating Strategy as a stock list.',
        workflow: TRADING_OS_SPINE.map((step) => ({ ...step })),
        comparisons: [
            {
                caption: 'What each page is about',
                headers: ['Page', 'About', 'Data you see'],
                rows: [
                    ['Screener', 'Eligibility rules', 'Definitions, runs, hit lists'],
                    ['Strategy', 'Policy / config only', 'Weights, thresholds, exits, gates (no stock list)'],
                    ['Discovery', 'Candidates (pipeline)', 'Symbols — optional to open; still run by pipeline'],
                    ['Evaluations', 'Factor facts (pipeline)', 'RS/trend/… — optional to open; required as data'],
                    ['Recommendations', 'Final ideas', 'Open/Increase/Reduce/Exit + HOLD/WATCH; Approve'],
                    ['Pending Exec.', 'Approved, not filled yet', 'Queue + optional cash reservation'],
                    ['Transactions', 'Ledger fills', 'Buys/sells (holdings source of truth)'],
                    ['Review', 'Outcomes', 'How ideas performed after decisions/fills'],
                    ['Cash', 'Money available', 'Balance, reserved, available investable'],
                    ['Dashboard', 'Portfolio + market snapshot', 'Value, analytics — not the idea queue'],
                ],
            },
            {
                caption: 'Eligibility vs scoring vs ideas',
                headers: ['Layer', 'Job'],
                rows: [
                    ['Screener', 'Finds / admits stocks (eligibility)'],
                    ['Evaluation', 'Measures factor facts (RS, trend, …)'],
                    ['Strategy', 'Scores, thresholds, exits, gates, allocation'],
                    ['Recommendations', 'Where you review and approve ideas'],
                ],
            },
        ],
        behindTheScenes: {
            summary:
                'Day-to-day you configure Screener + Strategy and act on Recommendations. Discovery and Evaluations still run inside the pipeline.',
            mermaid: `flowchart TD
  A[Screener hits] --> B[Discovery candidates]
  B --> C[Evaluations facts]
  C --> D[Strategy score thresholds exits gates cash]
  D --> E[Recommendations]
  E --> F[Approve]
  F --> G[Pending Execution]
  G --> H[Transactions fill]
  E --> I[Review later outcomes]`,
        },
        callouts: [
            {
                variant: 'important',
                title: 'Strategy never lists stocks',
                body: 'Changing Strategy only changes policy. Run the decision pipeline to refresh Recommendations.',
            },
        ],
        commonMistakes: [
            {
                q: 'Why are there no stocks on Strategy?',
                a: 'Strategy is configuration only. Ideas appear on Recommendations after a pipeline run.',
            },
            {
                q: 'Do I need to open Discovery / Evaluations?',
                a: 'Usually no. Those stages still run in the pipeline; open them when debugging.',
            },
        ],
    },
    dashboard: {
        icon: 'bi-speedometer2',
        purpose:
            'Orients you on portfolio health and market context so you know where to investigate next — it is not the place to approve recommendations.',
        workflow: spineWithCurrent('dashboard'),
        callouts: [
            {
                variant: 'tip',
                body: 'Read summary cards → allocation → market gauges → alerts/patterns → calendar. Refresh if numbers look stale.',
            },
        ],
        behindTheScenes: {
            summary: 'Dashboard aggregates portfolio metrics, market analytics, and cached API responses for the active portfolio.',
        },
        commonMistakes: [
            {
                q: 'Why don’t I see my latest recommendation here?',
                a: 'Ideas live on Recommendations. Dashboard is a snapshot, not the idea queue.',
            },
        ],
    },
    transactions: {
        icon: 'bi-receipt',
        purpose:
            'This is the accounting backbone. Holdings, realized P/L, and many reports derive from this ledger — enter rows carefully.',
        workflow: spineWithCurrent('transactions'),
        callouts: [
            {
                variant: 'warning',
                title: 'Edits ripple',
                body: 'Changing historical rows can recalculate cost basis and performance. Re-check Holdings and Review after major edits.',
            },
        ],
        commonMistakes: [
            {
                q: 'Why didn’t holdings update?',
                a: 'Confirm the transaction saved for the active portfolio, correct buy/sell type, and that quantity/price/date are valid.',
            },
        ],
    },
    'pending-execution': {
        icon: 'bi-hourglass-split',
        purpose:
            'Holds approved actionable ideas that are not yet filled at the broker — the bridge between decision and ledger.',
        workflow: spineWithCurrent('pending-execution'),
        decisions: [
            {
                tone: 'allow',
                title: 'Approve → here',
                body: 'Actionable Open / Increase / Reduce / Exit ideas land in this queue after Approve.',
            },
            {
                tone: 'caution',
                title: 'Cash reserved',
                body: 'Some approvals may reserve investable cash until you fill or cancel.',
            },
            {
                tone: 'block',
                title: 'HOLD / WATCH',
                body: 'Insights stay on Recommendations; they do not enter Pending Execution.',
            },
        ],
        commonMistakes: [
            {
                q: 'Why is my WATCH idea missing from the queue?',
                a: 'HOLD/WATCH are insights only — Approve→Pending Execution applies to actionable trade ideas.',
            },
        ],
    },
    cash: {
        icon: 'bi-wallet2',
        purpose:
            'Tracks balance, reserved, and available investable cash so Strategy allocation and Pending Execution stay within funding limits.',
        workflow: [
            { label: 'Cash balance', keyword: 'cash', current: true },
            { label: 'Strategy allocation', keyword: 'strategy' },
            { label: 'Recommendations', keyword: 'recommendations' },
            { label: 'Pending / fills', keyword: 'pending-execution' },
        ],
        conceptBoxes: [
            {
                title: 'Cash layers',
                icon: 'bi-cash-stack',
                rows: [
                    { label: 'Balance', value: 'Ledger cash for the active portfolio' },
                    { label: 'Reserved', value: 'Held for approved-but-unfilled ideas when enabled' },
                    { label: 'Available', value: 'Investable amount Strategy / pipeline can deploy' },
                ],
            },
        ],
        commonMistakes: [
            {
                q: 'Why did cash allocation fail?',
                a: 'Available investable cash may be below the amount Strategy wants to allocate, or reserves are tying up balance.',
            },
        ],
    },
    'corporate-action': {
        icon: 'bi-building-gear',
        purpose: 'Records splits, bonuses, and related events so holdings quantities and cost basis stay correct.',
        workflow: [
            { label: 'Holdings', keyword: 'holdings' },
            { label: 'Corporate action', current: true },
            { label: 'Transactions / metrics', keyword: 'transactions' },
        ],
        callouts: [
            {
                variant: 'warning',
                body: 'Apply corporate actions carefully — quantity and price history adjustments affect performance metrics.',
            },
        ],
    },
    holdings: {
        icon: 'bi-pie-chart',
        purpose: 'Shows open positions derived from transactions — the live portfolio view for risk, stoploss, and allocation.',
        workflow: [
            { label: 'Transactions', keyword: 'transactions' },
            { label: 'Holdings', current: true },
            { label: 'Recommendations / exits', keyword: 'recommendations' },
            { label: 'Review', keyword: 'review' },
        ],
        callouts: [
            {
                variant: 'info',
                body: 'Transactions are the source of truth; holdings are calculated from the ledger for the active portfolio.',
            },
        ],
    },
    'stock-prices': {
        icon: 'bi-graph-up',
        purpose: 'Inspect OHLCV history for a holding so you can verify data freshness, gaps, and chart context.',
        workflow: [
            { label: 'Holdings', keyword: 'holdings' },
            { label: 'Price history', current: true },
            { label: 'Explorer / sync', keyword: 'explorer' },
        ],
    },
    watchlist: {
        icon: 'bi-star',
        purpose: 'Personal symbol lists for research — separate from holdings and from Screener eligibility.',
        workflow: [
            { label: 'Watchlist', current: true },
            { label: 'Explorer', keyword: 'explorer' },
            { label: 'Screener / Strategy', keyword: 'screener' },
        ],
    },
    explorer: {
        icon: 'bi-binoculars',
        purpose: 'Deep-dive a single symbol against a chosen benchmark for charts, relatives, and diagnostics.',
        workflow: [
            { label: 'Pick symbol', current: true },
            { label: 'Choose benchmark', keyword: 'indices' },
            { label: 'Act via Recommendations / Transactions', keyword: 'recommendations' },
        ],
    },
    indices: {
        icon: 'bi-bar-chart-steps',
        purpose: 'Browse configured market indexes (including India VIX) for context, history, and VIX alerts.',
        workflow: [
            { label: 'Indices', current: true },
            { label: 'Dashboard / market gates', keyword: 'dashboard' },
            { label: 'Strategy market gates', keyword: 'strategy' },
        ],
        callouts: [
            {
                variant: 'info',
                title: 'India VIX scale',
                body: 'India VIX is typically ~10–30. Values in the thousands are data errors the app normalises automatically.',
            },
        ],
    },
    'market-depth': {
        icon: 'bi-grid-3x3-gap',
        purpose: 'Breadth heatmap — how many stocks sit above key averages — a participation lens alongside Dashboard gauges.',
        workflow: [
            { label: 'Dashboard gauges', keyword: 'dashboard' },
            { label: 'Market depth', current: true },
            { label: 'Strategy market gates', keyword: 'strategy' },
        ],
    },
    screener: {
        icon: 'bi-funnel',
        purpose:
            'Defines eligibility: which stocks are allowed into Discovery. Strategy does not rewrite Screener rules — it scores what Screeners admit.',
        workflow: spineWithCurrent('screener'),
        comparisons: [
            {
                caption: 'Screener vs Strategy',
                headers: ['Screener', 'Strategy'],
                rows: [
                    ['Finds / filters stocks', 'Scores admitted stocks'],
                    ['Defines eligibility conditions', 'Applies weights, thresholds, exits, gates'],
                    ['Produces hit lists / runs', 'Produces recommendations via pipeline'],
                    ['Edited on /screeners', 'Edited on /strategy'],
                ],
            },
        ],
        behindTheScenes: {
            summary: 'Scheduled or manual runs evaluate conditions against OHLCV and write hit lists the pipeline consumes.',
            mermaid: `flowchart LR
  A[Screener definition] --> B[Run]
  B --> C[Hit list]
  C --> D[Discovery / pipeline]`,
        },
        commonMistakes: [
            {
                q: 'Why zero hits?',
                a: 'Conditions may be too strict, price history may be incomplete, or the universe/index scope excludes candidates.',
            },
        ],
    },
    'screener-editor': {
        icon: 'bi-sliders',
        purpose: 'Build nested AND/OR technical conditions that decide eligibility for a single Screener definition.',
        workflow: [
            { label: 'Screeners list', keyword: 'screener' },
            { label: 'Editor', current: true },
            { label: 'Run / schedule', keyword: 'screener' },
            { label: 'Pipeline → Recommendations', keyword: 'recommendations' },
        ],
        callouts: [
            {
                variant: 'tip',
                body: 'Save before running. Nested groups are evaluated as written — start simple, then tighten.',
            },
        ],
    },
    discovery: {
        icon: 'bi-search',
        purpose: 'Shows pipeline candidates after eligibility — “who deserves deeper scoring,” not “who to buy now.”',
        workflow: [
            { label: 'Screeners', keyword: 'screener' },
            { label: 'Discovery', current: true },
            { label: 'Evaluations', keyword: 'evaluations' },
            { label: 'Strategy', keyword: 'strategy' },
            { label: 'Recommendations', keyword: 'recommendations' },
        ],
        callouts: [
            {
                variant: 'info',
                body: 'Opening this tab is optional. Discovery still runs inside the decision pipeline.',
            },
        ],
    },
    evaluations: {
        icon: 'bi-clipboard-data',
        purpose: 'Factor facts (inputs) for candidates — Strategy turns these into scores and labels.',
        workflow: [
            { label: 'Discovery', keyword: 'discovery' },
            { label: 'Evaluations', current: true },
            { label: 'Strategy scoring', keyword: 'strategy' },
            { label: 'Recommendations', keyword: 'recommendations' },
        ],
        conceptBoxes: [
            {
                title: 'Compared against (typical factor)',
                icon: 'bi-bullseye',
                rows: [
                    { label: 'Source', value: 'Evaluation Engine' },
                    { label: 'Value', value: 'Per-factor metrics (e.g. RS, trend)' },
                    { label: 'Used by', value: 'Strategy scoring model' },
                    { label: 'Updated', value: 'Latest pipeline / evaluation run' },
                ],
            },
        ],
    },
    recommendations: {
        icon: 'bi-lightning-charge',
        purpose:
            'Final decision proposals after eligibility, scoring, market gates, and cash constraints. Evidence first, action label second.',
        workflow: spineWithCurrent('recommendations'),
        decisions: [
            {
                tone: 'allow',
                title: 'Open / Increase / Reduce / Exit',
                body: 'Actionable ideas — Approve → Pending Execution (when cash/rules allow).',
            },
            {
                tone: 'caution',
                title: 'Defer',
                body: 'Use when you need more evidence instead of forcing approve/reject.',
            },
            {
                tone: 'block',
                title: 'HOLD / WATCH',
                body: 'Insights only — not Telegram actionable alerts; not Approve→Pending Execution.',
            },
        ],
        behindTheScenes: {
            summary: 'Pipeline applies Screener hits, Evaluation facts, and Strategy config, then writes recommendation rows for this portfolio.',
            mermaid: `sequenceDiagram
  participant U as You
  participant P as Decision pipeline
  participant S as Strategy
  participant R as Recommendations
  U->>P: Run / schedule
  P->>S: Score + gates + cash
  S->>R: Ideas
  U->>R: Approve / Reject / Defer`,
        },
        commonMistakes: [
            {
                q: 'Why no recommendations?',
                a: 'No Screener hits, scores below thresholds, market gates blocking entries, or insufficient cash for allocation.',
            },
            {
                q: 'Why is this only WATCH?',
                a: 'Score/label rules placed it in insight territory — not an actionable Open/Increase/Reduce/Exit.',
            },
        ],
    },
    strategy: {
        icon: 'bi-gear',
        purpose:
            'Policy for one portfolio: how admitted stocks are scored, labelled, exited, gated, and funded. It does not list stocks — Recommendations does.',
        workflow: spineWithCurrent('strategy'),
        comparisons: [
            {
                caption: 'Strategy vs Screener',
                headers: ['Strategy', 'Screener'],
                rows: [
                    ['Scores stocks', 'Finds stocks'],
                    ['Allocates capital', 'Filters candidates'],
                    ['Generates recommendations (via pipeline)', 'Defines eligibility'],
                    ['Exits, gates, thresholds', 'Condition builder / runs'],
                ],
            },
        ],
        conceptBoxes: [
            {
                title: 'Compared against — overall score',
                icon: 'bi-speedometer',
                rows: [
                    { label: 'Source', value: 'Evaluation facts × Strategy weights' },
                    { label: 'Value', value: 'Overall score' },
                    { label: 'Range', value: '0–100 (typical)' },
                    { label: 'Updated', value: 'Latest pipeline execution' },
                ],
            },
        ],
        behindTheScenes: {
            summary:
                'Eligibility admits → Scoring ranks → Thresholds label → Exit can force sells → Portfolio rules → Market gates → Capital + cash → Recommendations.',
            mermaid: `flowchart TD
  A[Screener admits] --> B[Score]
  B --> C[Thresholds label]
  C --> D[Exit rules]
  D --> E[Portfolio rules]
  E --> F[Market gates]
  F --> G[Cash allocation]
  G --> H[Recommendations]`,
        },
        decisions: [
            {
                tone: 'allow',
                title: 'New entries allowed',
                body: 'Market phase / sentiment / gates permit new Open / Increase ideas.',
            },
            {
                tone: 'caution',
                title: 'Reduced allocation',
                body: 'Gates or cash limits may shrink size even when ideas still appear.',
            },
            {
                tone: 'block',
                title: 'Entries blocked',
                body: 'Market gates or cash floor can prevent new buys while exits may still fire.',
            },
        ],
        callouts: [
            {
                variant: 'important',
                title: 'Save ≠ new ideas',
                body: 'Saving Strategy updates policy only. Run the decision pipeline from Recommendations to refresh ideas.',
            },
            {
                variant: 'tip',
                title: 'Weights',
                body: 'Enabled scoring weights auto-normalise to 100 on Save. Use Normalise now to preview.',
            },
        ],
        commonMistakes: [
            {
                q: 'Why no stocks on this page?',
                a: 'By design — Strategy is configuration. See Recommendations after a pipeline run.',
            },
            {
                q: 'Why is Save disabled / weights odd?',
                a: 'At least one enabled positive weight is required; totals are auto-normalised to 100 on Save.',
            },
        ],
    },
    review: {
        icon: 'bi-clipboard-check',
        purpose: 'Closes the learning loop — how recommendation decisions and fills performed over time.',
        workflow: spineWithCurrent('review'),
        callouts: [
            {
                variant: 'tip',
                body: 'Feed Review insights back into Screener conditions and Strategy thresholds — do not treat it as passive reporting only.',
            },
        ],
    },
    notifications: {
        icon: 'bi-bell',
        purpose: 'Audit trail of messages sent for the portfolio — confirm delivery and troubleshoot gaps.',
        workflow: [
            { label: 'Pipeline / alerts', keyword: 'recommendations' },
            { label: 'Notification history', current: true },
            { label: 'Settings credentials', keyword: 'settings' },
        ],
        callouts: [
            {
                variant: 'info',
                body: 'Missing HOLD/WATCH Telegram messages are expected — actionable Open/Increase/Reduce/Exit ideas are the ones alerted.',
            },
        ],
    },
    patterns: {
        icon: 'bi-activity',
        purpose: 'Educational pattern catalogue linked to scanner output on Dashboard, Watchlist, and OHLCV views.',
        workflow: [
            { label: 'Pattern signal', keyword: 'dashboard' },
            { label: 'Patterns guide', current: true },
            { label: 'Explorer / trade decision', keyword: 'explorer' },
        ],
    },
    calendar: {
        icon: 'bi-calendar-event',
        purpose: 'Portfolio events and reminders so you do not miss planned reviews or corporate dates.',
        workflow: [
            { label: 'Dashboard card', keyword: 'dashboard' },
            { label: 'Calendar', current: true },
        ],
    },
    knowledge: {
        icon: 'bi-journal-text',
        purpose: 'Durable research memory for theses, checklists, and post-mortems — per active portfolio.',
        workflow: [
            { label: 'Research', current: true },
            { label: 'Tags', keyword: 'knowledge-tags' },
            { label: 'Decisions / Review', keyword: 'review' },
        ],
    },
    'knowledge-tags': {
        icon: 'bi-tags',
        purpose: 'Maintain the tag vocabulary used to filter and group Knowledge Board notes.',
        workflow: [
            { label: 'Knowledge Board', keyword: 'knowledge' },
            { label: 'Tags', current: true },
        ],
    },
    profile: {
        icon: 'bi-person-circle',
        purpose: 'Account identity and personal preferences for the signed-in user.',
        workflow: [
            { label: 'Profile', current: true },
            { label: 'Portfolios / Settings', keyword: 'portfolios' },
        ],
    },
    portfolios: {
        icon: 'bi-briefcase',
        purpose: 'Create and switch portfolio profiles — most data is scoped to the active portfolio in the header.',
        workflow: [
            { label: 'Portfolios', current: true },
            { label: 'Header switcher', keyword: 'dashboard' },
            { label: 'Per-portfolio Strategy / Cash', keyword: 'strategy' },
        ],
        callouts: [
            {
                variant: 'important',
                body: 'Switching portfolio changes almost every list and metric. Confirm the header switcher before trading actions.',
            },
        ],
    },
    settings: {
        icon: 'bi-gear-wide-connected',
        purpose: 'Global app settings and per-portfolio preferences (notifications, VIX alerts, fees, sync).',
        workflow: [
            { label: 'Settings', current: true },
            { label: 'Sync / admin tools', keyword: 'universe-price-sync' },
        ],
    },
    'alert-policies': {
        icon: 'bi-shield-exclamation',
        purpose: 'Custom alert rules on holdings fields — separate from India VIX threshold alerts and admin ops alerts.',
        workflow: [
            { label: 'Holdings fields', keyword: 'holdings' },
            { label: 'Alert policies', current: true },
            { label: 'Notifications', keyword: 'notifications' },
        ],
    },
    'sync-logs': {
        icon: 'bi-journal-code',
        purpose: 'Admin troubleshooting for provider fetches, batch jobs, and sync failures.',
        workflow: [
            { label: 'Universe / index sync', keyword: 'universe-price-sync' },
            { label: 'Sync logs', current: true },
        ],
    },
    'admin-alerts': {
        icon: 'bi-exclamation-octagon',
        purpose: 'Operational alerts (rate limits, stale jobs) for admins — not portfolio trading alert policies.',
        workflow: [
            { label: 'Admin alerts', current: true },
            { label: 'Sync logs', keyword: 'sync-logs' },
        ],
    },
    'universe-price-sync': {
        icon: 'bi-cloud-download',
        purpose: 'Keeps equity and index OHLCV caches fresh so Screeners, Evaluations, and charts have data to work with.',
        workflow: [
            { label: 'Price sync', current: true },
            { label: 'Screeners / pipeline', keyword: 'screener' },
            { label: 'Dashboard / Explorer', keyword: 'dashboard' },
        ],
        callouts: [
            {
                variant: 'warning',
                body: 'Stale or gapped prices cause empty Screener hits and odd analytics. Check sync status before blaming Strategy.',
            },
        ],
        commonMistakes: [
            {
                q: 'Why do analytics look wrong?',
                a: 'Confirm universe/index sync completed and gap fill is not still running for the symbols you care about.',
            },
        ],
    },
    users: {
        icon: 'bi-people',
        purpose: 'Admin invite-only user management — security-sensitive account lifecycle.',
        workflow: [
            { label: 'User management', current: true },
            { label: 'Invite / reset flows', keyword: 'profile' },
        ],
        callouts: [
            {
                variant: 'warning',
                body: 'Keep invite and reset links scoped and time-bound. Only admins should operate this page.',
            },
        ],
    },
};

/**
 * @param {string} keyword
 * @returns {DocPresentation}
 */
export function getDocPresentation(keyword) {
    return DOC_PRESENTATION[keyword] || {};
}
