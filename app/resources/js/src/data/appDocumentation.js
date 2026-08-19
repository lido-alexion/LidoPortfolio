/**
 * In-app contextual documentation index.
 * Each entry is reachable via static HTML at /docs/{keyword}.html (and legacy /documentation?q= redirects there).
 */

import {
    AUTHORING_TRADING_ARTIFACTS_TOPIC,
    AI_AUTHORING_CONTRACT_TOPIC,
    INDICATOR_REGISTRY_GUIDE_EXTRAS,
    RUNTIME_SEMANTICS_TOPIC,
    SCREENER_REGISTRY_GUIDE_EXTRAS,
    STRATEGY_REGISTRY_GUIDE_EXTRAS,
    TRADING_COOKBOOK_TOPIC,
} from './tradingArtifactGuides.js';

function pathIs(pathname, exact) {
    const p = pathname.replace(/\/$/, '') || '/';
    return p === exact;
}

function pathStarts(pathname, prefix) {
    const p = pathname.replace(/\/$/, '') || '/';
    return p === prefix || p.startsWith(`${prefix}/`);
}

/**
 * @typedef {{ name: string, description: string }} DocItem
 * @typedef {{
 *   id: string,
 *   keyword: string,
 *   aliases?: string[],
 *   title: string,
 *   routeLabel: string,
 *   match: (pathname: string) => boolean,
 *   summary: string,
 *   overview: string,
 *   controls: DocItem[],
 *   concepts: DocItem[],
 *   related?: string[],
 * }} AppDocEntry
 */

/** @type {AppDocEntry[]} */
const APP_DOCUMENTATION_BASE = [
    {
        id: 'overview',
        keyword: 'overview',
        aliases: ['help', 'docs', 'documentation', 'home-help'],
        title: 'App documentation',
        routeLabel: '/docs/',
        match: (p) => pathStarts(p, '/documentation') || pathStarts(p, '/docs'),
        summary: 'How contextual help works and how to browse topics for every screen.',
        overview:
            'Documentation is public static HTML — anyone with the URL can read it without signing in or running JavaScript. Preferred links look like /docs/strategy.html (index: /docs/index.html). When you are logged in, use the (?) button in the header (left of your profile) on any page: it opens the matching static topic in a new tab. Guests can open /docs/ from the login screen. Legacy /documentation?q=… URLs redirect to these pages.',
        controls: [
            {
                name: 'Header (?)',
                description: 'Opens static Documentation for the current route in a new browser tab (available before and after login).',
            },
            {
                name: 'Public static HTML',
                description: 'No login or JavaScript required to view /docs/*.html. Product screens linked from topics still require sign-in.',
            },
            {
                name: 'Topic index',
                description: 'Open /docs/index.html for the full topic list.',
            },
            {
                name: 'Direct topic URLs',
                description: 'Share /docs/{keyword}.html (for example /docs/strategy.html) with humans or AI crawlers.',
            },
            {
                name: 'Sidebar toggle (Ctrl/Cmd+B)',
                description: 'Collapses or expands the primary sidebar on wide screens, or opens/closes the navigation overlay on narrower screens. Ignored while typing in form fields.',
            },
        ],
        concepts: [
            {
                name: 'Contextual keyword',
                description: 'Each app route maps to a stable keyword (for example dashboard, screener, strategy). Static files use that keyword as the HTML filename.',
            },
            {
                name: 'Active portfolio',
                description: 'Most screens read and write data for the portfolio selected in the header switcher.',
            },
            {
                name: 'Primary sidebar',
                description:
                    'Primary navigation is sidebar-only and fully configuration-driven (`config/navigation.js` + `navigation/` registry). Sections: Favourites, Quick Actions, then groups — Portfolio, Market, Trading, Knowledge, Administration. Reusable primitives: NavMenuItem, NavGroup, NavBadge, NavTooltip. Icons come from a single registry (no duplicated Lucide imports in rows). Routes use ROUTES constants. Editors stay internal. Future plugins register via navigationRegistry.registerModule. Ctrl/Cmd+B toggles the sidebar. See Documentation topic and specs/architecture/ui/15-Sidebar-Navigation-Architecture.md.',

            },
            {
                name: 'Page chrome',
                description:
                    'Above the page content, breadcrumbs (Home → group → page) and the current page title come from the same navigation catalog. Tags such as NEW or BETA and numeric badges can appear on sidebar items and the page title row. Disabled items stay visible but are not clickable; external items open in a new tab. Permission keys on catalog entries are reserved for future access filtering. Browser Back/Forward work with normal history entries from sidebar links.',
            },
            {
                name: 'Trading OS flow',
                description: 'See topic Trading OS pages & flow for which page shows what and how recommendations move from Screener → Strategy → Recommendations → Review.',
            },
        ],
        related: ['trading-os-flow', 'dashboard', 'settings', 'authoring-trading-artifacts', 'trading-artifact-runtime', 'trading-cookbook'],
    },
    {
        id: 'trading-os-flow',
        keyword: 'trading-os-flow',
        aliases: [
            'pipeline-flow',
            'pages-flow',
            'recommendation-flow',
            'how-recommendations-work',
            'trading-os',
        ],
        title: 'Trading OS pages & flow',
        routeLabel: '/documentation?q=trading-os-flow',
        match: () => false,
        summary: 'What Screener, Strategy, Discovery, Recommendations, and Review each show — and how stocks flow between them.',
        overview:
            'Strategy is only configuration. Recommended stocks appear on Recommendations after the decision pipeline applies Screeners, Discovery/Evaluation facts, and Strategy rules.\n\n'
            + 'What each page is about:\n\n'
            + '```text\n'
            + 'Page              About                         Data you see\n'
            + '---------------   ---------------------------   ------------------------------------------\n'
            + 'Screener          Eligibility rules             Definitions, runs, hit lists\n'
            + 'Strategy          Policy / config only          Weights, thresholds, exits, gates (no stock list)\n'
            + 'Discovery         Candidates + factor facts     Symbols, long-focused score/confidence/explanation\n'
            + 'Recommendations   Final ideas                   Open/Increase/Reduce/Exit + HOLD/WATCH; Approve\n'
            + 'Pending Exec.     Approved, not filled yet      Queue + optional cash reservation\n'
            + 'Transactions      Ledger fills                  Buys/sells (holdings source of truth)\n'
            + 'Review            Outcomes                      How ideas performed after decisions/fills\n'
            + 'Cash              Money available               Balance, reserved, available investable\n'
            + 'Dashboard         Portfolio + market snapshot   Value, analytics — not the idea queue\n'
            + '```\n\n'
            + 'Day-to-day: configure Screener + Strategy, act on Recommendations. Discovery (candidates + evaluation facts) still runs inside the pipeline — open it when inspecting or debugging.\n\n'
            + 'How a stock becomes a recommendation:\n\n'
            + '```mermaid\n'
            + 'flowchart TD\n'
            + '  A[Screener hits] --> B[Discovery candidates]\n'
            + '  B --> C[Evaluation facts on Discovery]\n'
            + '  C --> D[Strategy: score thresholds exits gates cash]\n'
            + '  D --> E[Recommendations]\n'
            + '  E --> F[Approve]\n'
            + '  F --> G[Pending Execution]\n'
            + '  G --> H[Transactions fill]\n'
            + '  E --> I[Review later outcomes]\n'
            + '```\n\n'
            + 'Configure Screeners + Strategy first, then run the decision pipeline from Recommendations (or the scheduled daily pipeline). Full write-up: specs/architecture/ui/07-Trading-OS-Pages-and-Flow.md.',
        controls: [
            {
                name: 'Run decision pipeline',
                description: 'On Recommendations — regenerates ideas using current Screener hits, Discovery/Evaluation facts, and Strategy config.',
            },
            {
                name: 'Approve / Reject / Defer',
                description: 'On Recommendations for actionable ideas. Approve moves buys/sells to Pending Execution.',
            },
        ],
        concepts: [
            {
                name: 'Discovery optional to visit',
                description:
                    'You configure Screener + Strategy and act on Recommendations. Discovery still runs inside the decision pipeline (candidates → long-focused factor facts). Opening Discovery is optional unless you are inspecting or debugging. Evaluation scores live on the Discovery table (no separate Evaluations page).',
            },
            {
                name: 'Eligibility vs scoring vs ideas',
                description: 'Screener admits; Discovery/Evaluation measures (long-focused); Strategy scores and filters; Recommendations is where you act.',
            },
            {
                name: 'Strategy never lists stocks',
                description: 'Changing Strategy only changes policy. You must generate (pipeline) to refresh Recommendations.',
            },
            {
                name: 'HOLD / WATCH',
                description: 'Insights on Recommendations; not sent to Telegram; not Approve→Pending Execution.',
            },
        ],
        related: [
            'screener',
            'strategy',
            'discovery',
            'recommendations',
            'pending-execution',
            'review',
            'cash',
        ],
    },
    {
        id: 'dashboard',
        keyword: 'dashboard',
        aliases: ['home', 'summary'],
        title: 'Dashboard',
        routeLabel: '/',
        match: (p) => pathIs(p, '/'),
        summary: 'Portfolio snapshot: value, allocation, market analytics, alerts, calendar, pattern signals, and a cash-reserve shortfall warning when physical cash is below the required reserve.',
        overview:
            'The Dashboard is the home screen for the active portfolio. It summarises performance, allocation, market context, alerts, upcoming calendar events, and pattern signals on holdings. A client-side cache can show the last load instantly until you refresh. If physical cash is below the portfolio required cash reserve, a warning appears on this page only — it does not block withdrawals or cancel recommendations by itself.',
        controls: [
            { name: 'Refresh dashboard', description: 'Clears the local cache and reloads dashboard + pattern scan data.' },
            { name: 'Sync prices for today', description: 'Admin: pulls latest holdings prices for the current session day.' },
            { name: 'Allocation charts', description: 'Donut / table views of market % and invested % by holding.' },
            { name: 'Portfolio growth chart', description: '365-day portfolio_value / invested_value trend from materialized snapshots; View snapshots opens the full history page.' },
            { name: 'Market Analytics gauges', description: 'Market Health summary card stays visible; gauge diagnostics are collapsible and explain the score. Diagnostics still include trend, momentum, volatility, risk, sentiment, phase, breadth, and regime. The Market breadth gauge title links to Market Depth.' },
            { name: 'Stocks Above heatmap', description: 'Market-depth view of how many stocks sit above key moving averages.' },
            { name: 'Pattern signals', description: 'Matched pattern names link into the Patterns guide.' },
            { name: 'Calendar card', description: 'Upcoming portfolio events for the next ~31 days.' },
            { name: 'Cash reserve shortfall warning', description: 'Shown on Dashboard when physical cash is below required_cash_reserve. Withdrawals are still recorded; this is not a hard block and not an app-wide banner.' },
        ],
        concepts: [
            { name: 'Portfolio XIRR', description: 'Money-weighted return for the active portfolio based on cash flows and current value.' },
            { name: 'Market phase / sentiment', description: 'Deterministic market regime from the primary benchmark (e.g. Nifty 50); used later by Strategy market gates.' },
            { name: 'Dashboard cache', description: 'Responses are cached per user + portfolio (~24h) for snappy revisits; mutations invalidate it.' },
            { name: 'Required cash reserve', description: 'Portfolio-level floor from Settings (portfolio cash reserve %). Rupee amount = that % of the larger of currently held invested amount and current holdings market value. Non-investable and non-lendable. Physical cash may fall below it after a broker withdrawal; Dashboard then warns.' },
        ],
        related: ['trading-os-flow', 'holdings', 'market-depth', 'patterns', 'calendar', 'review', 'portfolio-snapshots', 'historical-holdings', 'cash', 'settings'],
    },
    {
        id: 'historical-holdings',
        keyword: 'historical-holdings',
        aliases: ['as-of-holdings', 'historical holdings', 'holdings-as-of'],
        title: 'Historical Holdings',
        routeLabel: '/portfolio/historical-holdings',
        match: (p) => pathIs(p, '/portfolio/historical-holdings'),
        summary: 'As-of open holdings reconstructed from the transaction ledger for a chosen past date, with valuation and unrealized P/L.',
        overview:
            'Historical Holdings (F014) reconstructs open equity positions as of a selected calendar date from the transaction ledger — the same source of truth as live Holdings, but replayed only through that date.\n\n'
            + 'This page is not live Holdings and not Portfolio Snapshots. Snapshots (F015) are a daily equity-curve cache of portfolio_value / invested_value. Historical Holdings shows the per-stock cross-section on one date.\n\n'
            + 'Transactions on the selected date are included. Weekends and holidays are allowed; market price uses the latest available close on or before that date (adjusted close when present). Missing prices make valuation incomplete rather than silently zero. Corporate-action history reflects today’s corrected ledger (rebuildable truth), not a pre-correction “belief” state. Cash as-of and realized P/L are not shown here.',
        controls: [
            { name: 'As-of date', description: 'Pick any calendar date on or before today (YYYY-MM-DD). Future dates are rejected.' },
            { name: 'Refresh', description: 'Reload GET /api/portfolio/historical-holdings for the active portfolio.' },
            { name: 'Holdings table', description: 'Symbol, name, qty, Avg Buy, Invested (fee-exclusive), as-of price, market value, unrealized P/L and unrealized % vs invested — same percentage definition as live Holdings.' },
            { name: 'Inconsistency warnings', description: 'If historical sells exceed reconstructed quantity, a warning lists affected rows; reconstruction continues and the ledger is not modified.' },
            { name: 'Incomplete valuation banner', description: 'Shown when one or more holdings lack a price on or before the as-of date; aggregate market/unrealized totals are marked incomplete.' },
        ],
        concepts: [
            { name: 'Ledger reconstruction', description: 'Open qty and fee-exclusive cost basis come from replaying portfolio_transactions ordered by date then id — not from today’s holdings table or snapshot rows.' },
            { name: 'As-of price', description: 'Latest OHLCV row with price_date ≤ as-of; prefers adjusted_close, else close (F043-repaired data when present).' },
            { name: 'Unrealized P/L', description: 'market_value − invested_amount; unrealized % = unrealized / invested × 100 when invested > 0 (same as live Holdings).' },
            { name: 'Vs Portfolio Snapshots', description: 'Snapshots chart daily portfolio totals; Historical Holdings lists stocks open on one date.' },
        ],
        related: ['holdings', 'portfolio-snapshots', 'transactions', 'dashboard'],
    },
    {
        id: 'portfolio-snapshots',
        keyword: 'portfolio-snapshots',
        aliases: ['snapshots', 'portfolio-history', 'value-history'],
        title: 'Portfolio Snapshots',
        routeLabel: '/portfolio/snapshots',
        match: (p) => pathIs(p, '/portfolio/snapshots'),
        summary: 'Daily portfolio value history from backend snapshots (portfolio value, invested value, unrealized P/L).',
        overview:
            'Portfolio Snapshots shows the materialized daily history stored in portfolio_portfolio_snapshots. '
            + 'Each row is rebuilt from transactions and closing prices — not recalculated in the browser. '
            + 'Use range filters, the growth chart, and the daily table to review history. Rebuild history recalculates all snapshot rows from the ledger. '
            + 'For a per-stock list on one past date, use Historical Holdings instead.',
        controls: [
            { name: 'Range filters', description: '90 / 180 / 365 days or All (up to 2000 rows) — fetches backend snapshots for the active portfolio only.' },
            { name: 'Refresh', description: 'Reload snapshot data from GET /api/portfolio/snapshots.' },
            { name: 'Rebuild history', description: 'POST /api/portfolio/rebuild-history — recalculates daily snapshots from transactions and OHLCV.' },
            { name: 'Daily table', description: 'Newest first: snapshot date, portfolio value, invested value, unrealized P/L, day-over-day portfolio change.' },
        ],
        concepts: [
            { name: 'Portfolio value', description: 'Market value of holdings at each snapshot date (quantity × close on or before that date).' },
            { name: 'Invested value', description: 'Remaining cost basis for open holdings on that date — not the same as cash balance.' },
            { name: 'Unrealized P/L', description: 'portfolio_value − invested_value for that snapshot row (display derived from backend fields).' },
        ],
        related: ['dashboard', 'transactions', 'holdings', 'cash', 'historical-holdings'],
    },
    {
        id: 'transactions',
        keyword: 'transactions',
        aliases: ['ledger', 'buys', 'sells', 'history'],
        title: 'Transactions',
        routeLabel: '/transactions',
        match: (p) => pathIs(p, '/transactions') || pathIs(p, '/transactions/closed'),
        summary: 'Buy/sell ledger — source of truth for holdings, fees, and realized P/L.',
        overview:
            'Transactions are the ledger of record. Buys and sells drive holdings, average cost, fees, and FIFO realized P/L. Use Active vs Squared-off views, CSV import, and sell prefill from Portfolio.',
        controls: [
            { name: 'Add transaction', description: 'Record a buy or sell with symbol autocomplete, quantity, price, fees, and date.' },
            { name: 'Bulk CSV import', description: 'Paste CSV (Stock, Quantity, Average Price, Transaction Type), review editable rows (exchange, date defaulting to today, fees), then Save all. The batch commits all-or-nothing via a bulk API — on failure nothing is saved and you can fix and retry the same batch; a completed batch cannot be submitted again.' },
            { name: 'Page tabs', description: 'Switch between Transaction History and Pending Execution (or closed / squared-off views where available).' },
            { name: 'Edit / delete', description: 'Correct ledger rows; deleting a row linked to a recommendation can reopen pending execution.' },
        ],
        concepts: [
            { name: 'Ledger-true holdings', description: 'Holdings are derived from transactions — not edited independently as the source of truth.' },
            { name: 'FIFO realization', description: 'Sells match against earlier buys to compute realized P/L and allocated fees.' },
            { name: 'Auto fees', description: 'Fee components (Zerodha-style delivery defaults) can be configured under Settings.' },
        ],
        related: ['pending-execution', 'holdings', 'corporate-action', 'cash'],
    },
    {
        id: 'pending-execution',
        keyword: 'pending-execution',
        aliases: ['pending', 'execute'],
        title: 'Pending Execution',
        routeLabel: '/transactions/pending',
        match: (p) => pathIs(p, '/transactions/pending'),
        summary: 'Approved recommendations waiting for you to record the broker fill.',
        overview:
            'After you Approve a BUY or SELL recommendation, it moves here as pending execution. You place the trade at your broker, then complete it in the app (which writes a ledger transaction). Cancel returns the idea to an open state without a fill.',
        controls: [
            { name: 'Execute / Add Transaction', description: 'Opens the transaction form prefilled from the recommendation.' },
            { name: 'Cancel execution', description: 'Drops pending status and releases any reserved cash for buys.' },
            { name: 'History toggle', description: 'Navigate back to full Transaction History.' },
        ],
        concepts: [
            { name: 'Approval vs execution', description: 'Approve means you accept the idea; execution is the separate ledger write after a real fill.' },
            { name: 'Cash reservation', description: 'Approving a funded buy can reserve cash until execute, cancel, expire, or reopen.' },
        ],
        related: ['recommendations', 'transactions', 'cash'],
    },
    {
        id: 'cash',
        keyword: 'cash',
        aliases: ['balance', 'deposit', 'withdraw', 'unallocated cash', 'cash reserve'],
        title: 'Cash',
        routeLabel: '/cash',
        match: (p) => pathStarts(p, '/cash'),
        summary: 'Physical cash pool, reserved cash, required reserve, Unallocated Cash, strategy capital allocations, and the cash ledger.',
        overview:
            'Manage portfolio cash independently of stock trades. Physical cash is one portfolio-level pool — strategies do not have bank accounts. Balance minus reserved cash is available physical cash (pending-execution buys). The required cash reserve is a portfolio-level floor from Settings; it is not a separate ledger bucket. Unallocated Cash is a presentation of residual cash after that reserve that is not claimed by strategy unused-allocation accounting — not a new account. Strategy allocation % values split investable capital for accounting; they must sum to 100 to save and are not auto-normalized. Withdrawals cannot spend reserved cash, but they are not blocked merely because cash would fall below the reserve.',
        controls: [
            { name: 'Deposit / Withdraw / Adjust', description: 'Change cash with amount stepper, optional remarks, and transaction date. Withdrawals cannot exceed available physical cash (balance − reserved). They still succeed if cash then sits below the required reserve.' },
            { name: 'Reserved cash', description: 'Expand to see reservations tied to pending-execution buys.' },
            { name: 'Required cash reserve', description: 'Rupee floor from Portfolio Settings → Portfolio cash reserve %. Based on max(invested amount, current holdings market value), not a % of cash.' },
            { name: 'Unallocated Cash', description: 'Presentation-only residual after the reserve that unused strategy allocation has not claimed. Not a ledger line and not a withdrawal entitlement.' },
            { name: 'Strategy capital allocation', description: 'Edit enabled-strategy allocation % (must sum to 100 to save). Shows allocated capital, unused allocation, and retained capital (accounting floor, not physical cash).' },
            { name: 'Statement', description: 'Chronological cash ledger for the active portfolio. Deposit, withdrawal, adjustment, buy, and sell only — retained capital is never posted here.' },
        ],
        concepts: [
            { name: 'Available physical cash', description: 'max(0, total cash − pending-execution reservations). Withdrawals cannot exceed this amount.' },
            { name: 'Investable capital', description: '(cash − required reserve − pending reserved) + market value of strategy-owned holdings. Unmanaged holdings are excluded from the 100% strategy split.' },
            { name: 'Strategy allocation %', description: 'Policy share of investable capital (TradingStrategy.allocation_pct). Distinct from score-band allocation_pct on the Strategy Capital Allocation tab and from holdings market-value %.' },
            { name: 'Retained capital', description: 'Per-strategy nearest-integer rupees of allocated capital ÷ recommended minimum holdings. Lending cannot consume it later; it is not a cash bucket.' },
        ],
        related: ['recommendations', 'pending-execution', 'strategy', 'settings', 'dashboard'],
    },
    {
        id: 'corporate-action',
        keyword: 'corporate-action',
        aliases: ['split', 'bonus'],
        title: 'Corporate actions',
        routeLabel: '/corporate-action',
        match: (p) => pathStarts(p, '/corporate-action'),
        summary: 'Guided stock splits and bonus issues with OHLCV restatement.',
        overview:
            'Apply splits and bonus issues so holdings quantities/prices and historical OHLCV stay consistent. Preview before applying.',
        controls: [
            { name: 'Split / Bonus wizards', description: 'Choose stock, ratios, and effective date; preview then apply.' },
        ],
        concepts: [
            { name: 'OHLCV restatement', description: 'Historical prices are adjusted so charts and indicators remain comparable after the action.' },
            { name: 'Bonus ledger', description: 'Bonus shares are recorded as zero-price ledger rows linked to the corporate action.' },
        ],
        related: ['transactions', 'holdings'],
    },
    {
        id: 'holdings',
        keyword: 'holdings',
        aliases: ['portfolio', 'positions'],
        title: 'Portfolio (Holdings)',
        routeLabel: '/holdings',
        match: (p) => pathIs(p, '/holdings'),
        summary: 'Open positions derived from the ledger — qty, cost, P/L, XIRR, and trailing stop.',
        overview:
            'Holdings show current positions for the active portfolio. Use Simple or Complex views, resizable columns, sell prefill, Analyse prompts, and drill into per-stock OHLCV history.',
        controls: [
            { name: 'Simple / Complex views', description: 'Toggle density of columns and metrics.' },
            { name: 'Sell', description: 'Prefills a sell transaction from the selected holding.' },
            { name: 'Price history', description: 'Opens OHLCV chart and table for that stock.' },
            { name: 'Analyse', description: 'Copies an AI-ready prompt (with recent OHLCV) to the clipboard.' },
        ],
        concepts: [
            { name: 'Average buy / invested', description: 'Fee-exclusive cost basis (price × qty) for open lots; fees are shown separately.' },
            { name: 'Unrealized P/L', description: 'Mark-to-market vs latest cached close; unrealized % = unrealized ÷ invested × 100 when invested > 0.' },
            { name: 'Trailing stop metric', description: 'Shown on holdings; alert policies can act on it — not a separate automatic Telegram spam path.' },
        ],
        related: ['transactions', 'stock-prices', 'watchlist', 'dashboard', 'historical-holdings'],
    },
    {
        id: 'stock-prices',
        keyword: 'stock-prices',
        aliases: ['ohlcv', 'price-history'],
        title: 'Stock price history',
        routeLabel: '/holdings/:stockId/prices',
        match: (p) => /^\/holdings\/[^/]+\/prices/.test(p.replace(/\/$/, '') || '/'),
        summary: 'OHLCV chart and table for a single stock, with optional force sync.',
        overview:
            'Inspect daily bars used by charts, indicators, screeners, and pattern detection. Force sync can backfill or refresh from price providers when needed.',
        controls: [
            { name: 'Chart / table', description: 'Browse historical OHLCV for the selected stock.' },
            { name: 'Force sync / backfill', description: 'Request a fresh pull of history from configured providers.' },
            { name: 'Possible patterns', description: 'Patterns detected on the visible window, with links to the Patterns guide.' },
        ],
        concepts: [
            { name: 'Provider fallback', description: 'NSE → Yahoo → Alpha Vantage (BSE path uses bhavcopy → Yahoo → AV).' },
            { name: 'Cached bars', description: 'Screeners and pattern scans run on stored daily OHLCV, not live ticks.' },
        ],
        related: ['holdings', 'patterns', 'universe-price-sync'],
    },
    {
        id: 'watchlist',
        keyword: 'watchlist',
        aliases: ['watchlists', 'research-list'],
        title: 'Watchlist',
        routeLabel: '/watchlist',
        match: (p) => pathStarts(p, '/watchlist'),
        summary: 'Named research lists with prices, pattern scans, Recommendation Preview, and links into Explorer.',
        overview:
            'Maintain multiple watchlists per portfolio. Select a symbol for its price panel (`/watchlist/{SYMBOL}`), scan for patterns, and compare relative strength in Explorer. The research panel includes Stock Analytics, Evaluation Profile, and Recommendation Preview (F137) for the portfolio’s selected strategy.',
        controls: [
            { name: 'List switcher / add stock', description: 'Manage lists and quick-add symbols with notes.' },
            { name: 'Scan my watchlist', description: 'Runs pattern detection and can persist icons until prices refresh or expiry.' },
            { name: 'Compare strength', description: 'Deep-links into Explorer relative-strength views.' },
            {
                name: 'Recommendation Preview tab',
                description:
                    'Shows the execution-grade recommendation for the selected stock under the portfolio’s active strategy (same decision logic as Generate Recommendations). Requires a completed evaluation cycle. When unavailable, lists structured reasons instead of inventing WATCH. Values are BUY / SELL / HOLD_POSITION / WATCH. Score is the strategy overall (0–100); confidence is 0–1 research metadata.',
            },
        ],
        concepts: [
            { name: 'Persisted pattern scans', description: 'Results stay valid until expiry or newer OHLCV arrives.' },
            { name: 'Holding badge', description: 'Shows when a watched symbol is also an open position.' },
            {
                name: 'Recommendation Preview vs Generate',
                description:
                    'Preview is read-only: it does not create or cancel recommendations. It uses a current persisted recommendation only when it matches the latest completed evaluation cycle; otherwise it recalculates via the shared decision engine. Watchlist membership is not required for the API.',
            },
        ],
        related: ['explorer', 'patterns', 'holdings', 'recommendations', 'strategy'],
    },
    {
        id: 'explorer',
        keyword: 'explorer',
        aliases: ['relative-strength', 'universe-analytics'],
        title: 'Stock Explorer',
        routeLabel: '/explorer',
        match: (p) => pathStarts(p, '/explorer'),
        summary: 'Universe-cache analytics: price cards, relative strength, charts, normalized gain.',
        overview:
            'Stock Explorer (sidebar → Market) analyses stocks from the universe price cache over selectable windows (1M / 3M / 6M / 1Y). Use it to compare strength versus benchmarks and peers.',
        controls: [
            { name: 'Period toggles', description: 'Choose the lookback window for cards and charts.' },
            { name: 'Relative strength / charts', description: 'Compare normalized performance across selected symbols or vs indices.' },
        ],
        concepts: [
            { name: 'Universe cache', description: 'Explorer depends on batched universe OHLCV sync, not only holdings history.' },
            { name: 'Normalized gain', description: 'Rebasing series to a common start makes relative performance comparable.' },
        ],
        related: ['indices', 'watchlist', 'market-depth'],
    },
    {
        id: 'indices',
        keyword: 'indices',
        aliases: ['index', 'nifty', 'sensex', 'benchmarks', 'vix', 'india vix'],
        title: 'Indices',
        routeLabel: '/indices',
        match: (p) => pathStarts(p, '/indices'),
        summary: 'Browse index pages, constituents, benchmark comparisons, and India VIX alerts.',
        overview:
            'Indices cover configured benchmarks (Nifty, Sensex, mid/small caps, sector indexes, and India VIX). Use constituents and comparisons when researching market context or screener scopes. India VIX is a volatility index (typical closes ~10–30), not a stock-price index.',
        controls: [
            { name: 'Index browser', description: 'Expand an index section to review latest close, history chart, and (where available) constituents.' },
            {
                name: 'India VIX alert',
                description:
                    'On the India VIX section: enable/disable and set a threshold (default 20). Notifies once when VIX closes above the threshold, then stays quiet until VIX falls back to or below it.',
            },
        ],
        concepts: [
            { name: 'Primary benchmark', description: 'Market Analysis typically uses Nifty 50 (via IndexCatalog) for sentiment and phase.' },
            { name: 'Index constituents scope', description: 'Screeners can limit runs to members of a chosen index.' },
            {
                name: 'India VIX scale',
                description:
                    'India VIX is quoted as an annualised volatility percentage (e.g. 12.5). Values in the thousands are data errors; the app normalises known ×100 provider glitches automatically.',
            },
        ],
        related: ['explorer', 'dashboard', 'screener'],
    },
    {
        id: 'market-depth',
        keyword: 'market-depth',
        aliases: ['breadth', 'stocks-above'],
        title: 'Market depth',
        routeLabel: '/market-depth',
        match: (p) => pathStarts(p, '/market-depth'),
        summary: 'Breadth / stocks-above heatmap and related market-depth snapshots.',
        overview:
            'Market depth visualises how many stocks trade above key averages — a breadth lens that also appears on the Dashboard heatmap.',
        controls: [
            { name: 'Heatmap / depth view', description: 'Inspect breadth across the configured universe snapshot.' },
        ],
        concepts: [
            { name: 'Market breadth', description: 'Participation measure used alongside trend and sentiment gauges.' },
        ],
        related: ['dashboard', 'indices'],
    },
    {
        id: 'screener',
        keyword: 'screener',
        aliases: ['screeners', 'filters'],
        title: 'Screener',
        routeLabel: '/screeners',
        match: (p) => pathIs(p, '/screeners'),
        summary: 'Eligibility engine — condition builders that feed Discovery and Strategy.',
        overview:
            'Screeners are the sole eligibility engine. Build nested AND/OR conditions on technical indicators, run manually or on a schedule, review history, and optionally Telegram results. Strategy only references Screeners — it does not rewrite their rules.',
        controls: [
            { name: 'Create / open screener', description: 'Opens the editor for a new or existing definition.' },
            { name: 'Run / schedule', description: 'Execute now or attach a cron schedule + optional Telegram delivery.' },
            { name: 'Share across your portfolios', description: 'Mark a screener shared so your other portfolios (same account only) can list and import a private copy. Other users cannot see it.' },
            { name: 'Screener Registry', description: 'Open the registry to export/import Screener JSON. The import schema guide lists mandatory fields (slug, name, definition.root, …) and a minimal working example.' },
            { name: 'Guide tab (editor)', description: 'Plain-language indicator definitions and Investopedia links.' },
        ],
        concepts: [
            { name: 'Eligibility vs scoring', description: 'Screeners admit candidates; Strategy scoring ranks them afterward.' },
            { name: 'Scopes', description: 'Holdings, watchlist, all equities, or index constituents.' },
            { name: 'Same-account sharing', description: '`is_shared` lists the screener under Shared screens for your other portfolios only — not a global catalog for other users.' },
            { name: 'Factory Minervini Trend Template', description: 'Shipped default screener often referenced by the default Minervini Strategy.' },
            { name: 'Registry vs editor', description: 'The registry manages reusable artifact metadata and JSON I/O; the editor builds conditions and runs screens.' },
        ],
        related: ['trading-os-flow', 'screener-editor', 'screener-registry', 'strategy', 'discovery'],
    },
    {
        id: 'screener-editor',
        keyword: 'screener-editor',
        aliases: ['screener-edit', 'conditions', 'backtest'],
        title: 'Screener editor',
        routeLabel: '/screeners/:id',
        match: (p) => {
            const path = p.replace(/\/$/, '') || '/';
            if (pathStarts(path, '/screeners/registry')) return false;
            return /^\/screeners\/[^/]+/.test(path);
        },
        summary: 'Edit conditions, run history, stacked results, and backtests.',
        overview:
            'Define LHS/RHS comparisons (stock or index entity), weight factors, and nested groups. Review run history, stacked compare matrices, and backtests over 1y / 6m / 3m / 1m / 15d windows.',
        controls: [
            { name: 'Condition builder', description: 'Add indicators, operators, weights, and AND/OR groups.' },
            { name: 'Run history', description: 'Past runs with hit lists for comparison.' },
            { name: 'Stacked results', description: 'Compare multiple runs side by side.' },
            { name: 'Backtest', description: 'Evaluate the rule set across dates with per-date persistence.' },
            { name: 'Save', description: 'Persist definition changes for future Discovery / Strategy use. Definition changes bump Screener Registry artifact versions.' },
        ],
        concepts: [
            { name: 'LHS entity', description: 'Compute the left side on the stock or an index (e.g. stock range % vs Nifty 50).' },
            { name: 'Weight factor', description: 'Compare left vs weight × right for scaled thresholds.' },
            { name: 'Stock-major series', description: 'Backtests reuse series efficiently across runs.' },
        ],
        related: ['trading-os-flow', 'screener', 'screener-registry', 'strategy', 'discovery'],
    },
    {
        id: 'screener-registry',
        keyword: 'screener-registry',
        aliases: ['screener-artifacts', 'screener-json', 'import-screener'],
        title: 'Screener Registry',
        routeLabel: '/screeners/registry',
        match: (p) => pathStarts(p, '/screeners/registry') || pathStarts(p, '/settings/screener-registry'),
        summary: 'Import/export Screener JSON artifacts — mandatory fields, slug rules, condition tree shape, and version history.',
        overview:
            'The Screener Registry turns portfolio screeners into reusable Trading Artifacts. Each screener still uses the same condition tree the run engine executes. The registry adds slug, metadata, artifact_version, definition_hash, and version history.\n\n'
            + 'Export downloads the Trading Artifact JSON envelope. **Validate** checks the envelope. **Import** stays disabled until validation succeeds, then creates a new screener in the active portfolio. Shared screeners from **your other portfolios** (same account) appear read-only and can be copied with Import copy — not visible to other users.\n\n'
            + '## Importing JSON — start here\n\n'
            + 'If Validate or Import reports many field errors, you almost always missed a **mandatory** envelope field or built an empty/invalid `definition.root` tree. Use the minimum schema below, then expand.\n\n'
            + '### Minimum valid envelope (copy/paste starting point)\n\n'
            + '```json\n'
            + '{\n'
            + '  "schema_version": "1.0",\n'
            + '  "artifact_type": "screener",\n'
            + '  "slug": "my_first_screener",\n'
            + '  "name": "My First Screener",\n'
            + '  "metadata": {\n'
            + '    "universe": "holdings",\n'
            + '    "description": "Close above 10 (example)"\n'
            + '  },\n'
            + '  "definition": {\n'
            + '    "root": {\n'
            + '      "type": "group",\n'
            + '      "op": "AND",\n'
            + '      "children": [\n'
            + '        {\n'
            + '          "type": "condition",\n'
            + '          "left": { "indicator": "close", "params": {} },\n'
            + '          "operator": "gt",\n'
            + '          "weight_factor": 1,\n'
            + '          "right": { "type": "constant", "value": 10 }\n'
            + '        }\n'
            + '      ]\n'
            + '    }\n'
            + '  }\n'
            + '}\n'
            + '```\n\n'
            + 'That JSON has every runtime-required field and at least one condition (Import will reject an empty tree even if Validate is lenient on empty groups).\n\n'
            + '### Mandatory vs optional fields\n\n'
            + '| Field | Required? | What to put |\n'
            + '|-------|-----------|-------------|\n'
            + '| `schema_version` | **Yes** | Always `"1.0"` |\n'
            + '| `artifact_type` | **Yes** | Always `"screener"` |\n'
            + '| `slug` | **Yes** | Stable id: lowercase letters, numbers, underscores only (e.g. `high_liquidity`). See **Slug** below |\n'
            + '| `name` | **Yes** | Human label shown in the UI (max 120 characters) |\n'
            + '| `metadata` | **Yes** | An object — may be `{}`, but prefer at least `universe` / `description` |\n'
            + '| `definition` | **Yes** | Object with required `root` condition tree |\n'
            + '| `definition.root` | **Yes** | A `group` or `condition` node; Import needs ≥1 condition |\n'
            + '| `artifact_id` | Optional | Leave out on create; export may include a local id |\n'
            + '| `artifact_version` | Optional | Integer ≥ 1; Import starts versions at 1 anyway |\n'
            + '| `definition_hash` | Optional | Recalculated on Import — do not invent this |\n'
            + '| `minimum_engine_version` | Optional | e.g. `"1.1.0"` |\n'
            + '| `dependencies` | Optional | Array of refs; export fills this |\n'
            + '| `validation` | Optional | Embedded hints; not executed as rules |\n'
            + '\n'
            + 'Note: the database column is `definition_json`, but the **import JSON field name is `definition`** (not `definition_json`).\n\n'
            + '### What each field means\n\n'
            + '**`schema_version`** — Which envelope contract this file uses. Must be `"1.0"`. Wrong or missing → `SCHEMA_VERSION_UNSUPPORTED` / empty schema errors.\n\n'
            + '**`artifact_type`** — Discriminator so the registry knows this is a Screener (not Strategy/Indicator). Must be `"screener"`.\n\n'
            + '**`slug`** — Machine-stable key for this screener inside a portfolio. Strategies and other artifacts can refer to a screener by slug. '
            + 'Use snake_case like `breakout_volume` or `minervini_trend_template`. Allowed characters after normalisation: `a-z`, `0-9`, `_` '
            + '(spaces and punctuation become `_`). Keep it short, unique, and meaningful — do **not** put a sentence here; that belongs in `name` / `metadata.description`. '
            + 'If the slug already exists on Import, the system may suffix it (e.g. `_import_ab12`) so the import still succeeds.\n\n'
            + '**`name`** — Display title in lists and the editor. Example: `"Breakout with volume"`. If the name collides, Import may append `" (import)"`.\n\n'
            + '**`metadata`** — Descriptive object. Common keys:\n'
            + '- `universe` — maps to screener scope: `holdings` | `watchlist` | `all_equities` | `index` (aliases like `portfolio` → `holdings` are accepted)\n'
            + '- `description` / `intent` / `summary` — human prose (description/intent capped ~500 chars on save)\n'
            + '- `tags` — array of strings\n'
            + '- `status` — lifecycle hint: `draft` | `active` | `deprecated` | `archived`\n'
            + '- `origin` — provenance: `factory` | `user` | `imported` | `ai_assisted` | `fork` | `exported`\n'
            + '- `factory_key` — stable factory id when shipping a built-in (e.g. `high_liquidity`)\n\n'
            + '**`definition`** — The condition tree the Screener engine runs. Shape:\n\n'
            + '```json\n'
            + '{\n'
            + '  "root": {\n'
            + '    "type": "group",\n'
            + '    "op": "AND",\n'
            + '    "children": [ /* conditions or nested groups */ ]\n'
            + '  }\n'
            + '}\n'
            + '```\n\n'
            + '**Group node:** `type: "group"`, `op: "AND"` or `"OR"`, `children`: non-empty array on Import.\n\n'
            + '**Condition node:**\n'
            + '```json\n'
            + '{\n'
            + '  "type": "condition",\n'
            + '  "left": { "indicator": "close", "params": {} },\n'
            + '  "operator": "gt",\n'
            + '  "weight_factor": 1.0,\n'
            + '  "right": { "indicator": "sma", "params": { "period": 50 } }\n'
            + '}\n'
            + '```\n'
            + '- `operator`: `gt` | `gte` | `lt` | `lte` | `eq`\n'
            + '- `left` / `right`: either `{ "indicator": "<id>", "params": {…} }` or `{ "type": "constant", "value": <number> }`\n'
            + '- Indicator ids must be screenable catalogue ids (e.g. `close`, `sma`, `rsi`, `volume`) — unknown ids fail validation\n'
            + '- Optional `weight_factor` multiplies the right side (default `1`)\n'
            + '- Nesting depth max **4**; max **40** conditions\n\n'
            + '**`artifact_version` / `definition_hash`** — Versioning metadata. Export includes them; Import recomputes the hash and starts history for the new local copy.\n\n'
            + '### Recommended import workflow\n\n'
            + '1. Start from the minimum example above (or Export an existing working screener and edit a copy).\n'
            + '2. Paste into Registry → **Validate** and fix every listed path (`$.slug`, `$.definition.root`, …).\n'
            + '3. Use **Import** (enabled only after Validate succeeds).\n'
            + '4. Open the classic Screener editor to refine conditions visually if needed.\n\n'
            + '### Common validation / import errors\n\n'
            + '| Message / code | Likely cause |\n'
            + '|----------------|--------------|\n'
            + '| `slug is required` | Missing or blank `slug` |\n'
            + '| `name is required` | Missing or blank `name` |\n'
            + '| `definition object is required` | Used `definition_json` instead of `definition`, or omitted it |\n'
            + '| `definition_json.root is required` / root errors | Missing `definition.root` |\n'
            + '| `Screener needs at least one condition` | Empty `children` array |\n'
            + '| `Group op must be AND or OR` | Typo in `op` |\n'
            + '| `Invalid condition operator` | Use `gt`/`gte`/`lt`/`lte`/`eq` only |\n'
            + '| `Unknown indicator…` | Indicator id not in the Screener catalogue |\n'
            + '| Nesting / too many conditions | Depth > 4 or > 40 conditions |\n'
            + '| Slug already exists | Pick another slug or let Import rename |\n'
            + '\n'
            + SCREENER_REGISTRY_GUIDE_EXTRAS,
        controls: [
            { name: 'Search / filters', description: 'Filter by status, ownership (own vs shared), and origin (factory / user / shared).' },
            { name: 'Export JSON', description: 'Download the Screener artifact envelope (schema_version, slug, name, metadata, definition.root, dependencies). Best template for a new import.' },
            { name: 'Validate', description: 'Check pasted JSON against Trading Artifact Screener rules. On success, a green “Validated successfully” cue appears above Validate/Import and the JSON result panel still shows details. Import stays disabled until this reports ok. Editing the JSON clears validation.' },
            { name: 'Import', description: 'Enabled only after successful Validate. Creates a new screener in this portfolio and shows a success toast (not an inline alert). Mandatory: schema_version, artifact_type, slug, name, metadata, definition.root with ≥1 condition.' },
            { name: 'Download AI authoring guide (.md)', description: 'Download /docs/stox-trading-artifacts-ai-guide.md — consolidated Indicator + Screener + Strategy + Authoring + Cookbook Markdown for AI agents and offline authoring.' },
            { name: 'Import copy (shared)', description: 'Copy a screener shared from one of your other portfolios into the active portfolio (same as Shared screens import).' },
            { name: 'Open editor', description: 'Jump to the classic Screener editor to change conditions or run screens after import.' },
            { name: 'Version history', description: 'On detail for owned screeners, list definition snapshots and change notes.' },
        ],
        concepts: [
            {
                name: 'Slug',
                description:
                    'Stable machine id (snake_case: `my_breakout_screen`). Used for uniqueness and cross-artifact references. Not the display title — that is `name`. Only a–z, 0–9, and underscore after normalisation.',
            },
            {
                name: 'definition vs definition_json',
                description:
                    'Import/export JSON uses the field `definition`. The database stores the same tree in column `definition_json`. Do not put `definition_json` in the envelope.',
            },
            {
                name: 'Mandatory envelope fields',
                description:
                    'schema_version ("1.0"), artifact_type ("screener"), slug, name, metadata (object), and definition.root with at least one condition for a successful Import.',
            },
            {
                name: 'Operator enum',
                description:
                    'Condition operators: gt, gte, lt, lte, eq only. Group ops: AND, OR only. NOT, neq, crosses_*, between, etc. are not supported.',
            },
            {
                name: 'Operand shapes',
                description:
                    'Indicator `{ indicator, params }` or constant `{ type: "constant", value: <number> }`. No boolean/string/null/date operands.',
            },
            {
                name: 'No execution redesign',
                description: 'Runs, schedules, and backtests still use ScreenerRunService and the existing definition tree.',
            },
            {
                name: 'Shared → Registry',
                description: 'is_shared screeners from your other portfolios surface in the registry with ownership=shared; copying them creates a local owned artifact. Other users cannot see them.',
            },
            {
                name: 'Version bump',
                description: 'Changing the condition tree (via editor or registry update) increments artifact_version and appends portfolio_screener_versions.',
            },
        ],
        related: ['screener', 'screener-editor', 'indicator-registry', 'strategy-registry', 'authoring-trading-artifacts', 'trading-artifact-runtime', 'trading-cookbook', 'settings'],
    },
    {
        id: 'discovery',
        keyword: 'discovery',
        aliases: ['candidates', 'evaluations', 'evaluation', 'factors'],
        title: 'Discovery',
        routeLabel: '/candidates',
        match: (p) => pathStarts(p, '/candidates') || pathStarts(p, '/evaluations'),
        summary: 'Candidates from screeners and patterns, plus long-focused evaluation scores on one page.',
        overview:
            'Discovery lists candidates from the Discovery Engine (Screeners + PatternScan), then the Evaluation Engine measures factor facts for those same rows. Score, confidence, and explanation appear in the Discovery table — there is no separate Evaluations page.\n\n'
            + 'How Discovery and Evaluation link:\n\n'
            + '```mermaid\n'
            + 'flowchart TD\n'
            + '  A[Screener hits / patterns / membership] --> B[Discovery candidates]\n'
            + '  B --> C[Evaluation factor facts]\n'
            + '  C --> D[Same Discovery table: score confidence explanation]\n'
            + '```\n\n'
            + 'Discovery builds the candidate inventory for the latest run. Evaluation loads that run’s candidates and computes measurable facts (trend, momentum, relative strength, volume, risk, pattern bonus). Each evaluation result points back to a candidate (`candidate_id`); the evaluation run points back to the discovery run (`discovery_run_id`).\n\n'
            + 'Long-focused scoring (important):\n\n'
            + 'Evaluation and the informational score on this page are **long-focused**. Factor heuristics favour longs (e.g. SMA uptrend stack and healthy RSI score higher). They do **not** switch to a sell viewpoint based on which screener produced the hit.\n\n'
            + 'Screeners themselves have no bullish/bearish flag — they are condition trees. You can build a bearish screener (weak RSI, price below MA, distribution, etc.). Bearish hits can still appear as Discovery candidates when recent screener runs are merged in. Evaluation still scores them with the same long-leaning facts; a weak long profile simply shows as a low score / failed rules — it is not re-interpreted as “good to sell.”\n\n'
            + 'How buy vs sell intent is wired (outside Evaluation):\n\n'
            + '| Where you attach a screener | Meaning |\n'
            + '|-----------------------------|---------|\n'
            + '| Strategy → Eligibility Sources | Hits may be considered for **entry** |\n'
            + '| Strategy → Exit Strategy → Screener Exit | If you **already hold** the stock and it appears in that screener’s latest run → exit signal |\n'
            + '\n'
            + 'The system does not infer sell purpose from screener condition text. You assign purpose by wiring the screener to eligibility (buy gate) or Screener Exit (sell trigger). Discovery/Evaluation only inventory and measure; they do not invent that wiring.\n\n'
            + 'Use **Run discovery** to rebuild candidates (evaluation follows automatically). Use **Run evaluation** to re-measure the latest discovery run without rebuilding candidates.',
        controls: [
            {
                name: 'Run discovery',
                description:
                    'Creates a new discovery run (pattern scans + recent screener hits, with holdings/watchlist fallback), then runs evaluation on those candidates and refreshes the table.',
            },
            {
                name: 'Run evaluation',
                description:
                    'Re-scores the latest completed discovery run’s candidates with long-focused factor facts (score, confidence, rank, explanation). Requires a prior discovery run.',
            },
            {
                name: 'Candidate table',
                description:
                    'Symbol, source, discovery reason (matched patterns as sketch icons with name on hover — same sketches as the Patterns guide; screener/membership signals stay as text badges), plus evaluation rank / score / confidence / explanation when evaluated. Evidence opens discovery signals; Factors opens indicators and passed/failed rules. Click a pattern icon to open that entry in the Patterns guide.',
            },
            {
                name: 'Filters',
                description: 'Search by symbol/name and filter by source (screener, pattern, holding, watchlist, mixed).',
            },
        ],
        concepts: [
            {
                name: 'Candidate',
                description: 'A stock admitted into the latest discovery inventory (screener hit, pattern, or membership fallback).',
            },
            {
                name: 'Factor facts vs Strategy weights',
                description:
                    'Evaluation stores measurable facts and an informational equal-weight rank. Strategy weights and buy/sell action labels are applied later in the decision pipeline — not rewritten on this page.',
            },
            {
                name: 'Long-focused evaluation',
                description:
                    'Scores favour long setups. Bearish screener hits are listed and measured the same way; they are not auto-flipped into a sell score. Sell-oriented screeners belong on Strategy Screener Exit when you want exits from holdings.',
            },
            {
                name: 'Discovery → Evaluation link',
                description:
                    'Evaluation requires a completed discovery run. Results attach to candidates via candidate_id; the evaluation run records discovery_run_id.',
            },
        ],
        related: ['trading-os-flow', 'screener', 'strategy'],
    },
    {
        id: 'recommendations',
        keyword: 'recommendations',
        aliases: ['trades', 'approve', 'ideas'],
        title: 'Recommendations',
        routeLabel: '/recommendations',
        match: (p) => pathStarts(p, '/recommendations'),
        summary: 'Review trade ideas and market insights — Approve, Reject, or Defer.',
        overview:
            'This is where final recommended stocks appear after Screeners, Discovery/Evaluation facts, and Strategy filters. Recommendations are generated from Strategy scoring, market opinion, portfolio rules, and capital allocation. Trade recommendations (Open / Increase / Reduce / Exit) can be Approved into pending execution. HOLD / WATCH insights are view-only guidance.',
        controls: [
            { name: 'Approve / Reject / Defer', description: 'Lifecycle actions on actionable recommendations.' },
            { name: 'Review dialog', description: 'Inspect evidence, quantity, and cash impact before deciding.' },
            { name: 'Trade vs insights sections', description: 'Actionable trades are separated from HOLD / WATCH insights.' },
            { name: 'Generate (when available)', description: 'Runs the recommendation pipeline for the active strategy / profile. Telegram (when enabled) notifies only actionable Open / Increase / Reduce / Exit ideas — not HOLD / WATCH.' },
        ],
        concepts: [
            { name: 'Evidence snapshot', description: 'Eligibility, scoring, exit, market opinion, and capital allocation status travel with the idea.' },
            { name: 'Unfunded → Watch', description: 'Ideas that cannot be funded from available cash are demoted to Watch.' },
            { name: 'Reopen', description: 'Undo review decisions back to pending_review when supported.' },
            { name: 'Telegram filter', description: 'HOLD / WATCH stay in-app only; they are not sent as Telegram recommendation alerts.' },
        ],
        related: ['trading-os-flow', 'strategy', 'pending-execution', 'cash', 'review'],
    },
    {
        id: 'strategy',
        keyword: 'strategy',
        aliases: ['scoring', 'eligibility', 'exit-strategy', 'strategy-guide'],
        title: 'Strategy',
        routeLabel: '/strategy',
        match: (p) => pathStarts(p, '/strategy') && !pathStarts(p, '/strategy/registry'),
        summary: 'A portfolio may enable multiple strategies — default Minervini; edit tabs and Save.',
        overview:
            'Strategy is your decision policy. A portfolio may have **multiple enabled strategies** at the same time. It starts with Minervini Strategy (Minervini Trend Template eligibility + momentum scoring). Edit any tab and Save — the editor still saves that strategy in place.\n\n'
            + 'Use Strategy Registry to import/export JSON, validate packs, browse drafts, and **Enable** a definition for this portfolio without disabling other enabled strategies. The Strategy editor `?strategy_id=` query is a UI choice of which strategy to edit — not a database rule that only one strategy can be enabled. Strategies reference Screeners by slug / factory key — they never duplicate Screener condition trees.\n\n'
            + '**AI Strategy Designer** (collapsible panel on this page) does **not** call an LLM. It builds a paste-ready prompt from your style/risk/complexity choices, copies it to the clipboard, and expects you to attach the StoX Trading Artifacts AI Authoring Guide in ChatGPT/Gemini/Claude/etc. Import the resulting Screener/Strategy JSON via the registries after Validate.\n\n'
            + 'Strategy does not invent stocks and does not rewrite Screener conditions. Screeners admit candidates; Strategy scores them, labels an action, applies portfolio/cash/market limits, and watches holdings for exits.\n\n'
            + 'Where do finished ideas appear?\n\n'
            + 'After you save Strategy and run the decision pipeline (Recommendations page → “Run decision pipeline”, or the scheduled daily pipeline), surviving trade ideas land on Recommendations (/recommendations). Approve a buy/sell there → it moves to Pending Execution (/transactions/pending). After you record the broker fill, it becomes a ledger transaction and shows on Holdings / Review. Insights (HOLD / WATCH) also appear on Recommendations but are view-only and are not sent to Telegram.\n\n'
            + 'Filter sequence (high level):\n\n'
            + '```mermaid\n'
            + 'flowchart TD\n'
            + '  A[Screener hits - Eligibility Sources] --> B[Scoring Model overall 0-100]\n'
            + '  B --> C[Recommendation Thresholds]\n'
            + '  C --> D[Open / Increase / Reduce / Exit / Watch / Hold]\n'
            + '  C --> E[Exit Strategy rules - holdings only]\n'
            + '  E -.->|can force Exit| D\n'
            + '  D --> F[Portfolio Rules + Behaviour flags]\n'
            + '  F --> G[Market Gates - may block new Open/Increase]\n'
            + '  G --> H[Capital Allocation + Cash Management]\n'
            + '  H -->|unfunded Open/Increase become Watch| I[Recommendations page]\n'
            + '  I --> J[Approve]\n'
            + '  J --> K[Pending Execution]\n'
            + '  K --> L[Ledger fill]\n'
            + '```\n\n'
            + 'Read Controls below for every tab field (what it means and what number it is compared against). Read Concepts for a scored example of four candidates and three holdings hitting exit rules.',
        controls: [
            {
                name: 'AI Strategy Designer',
                description:
                    'Collapsible panel on /strategy. Fill Investment Style, Risk, Holding Period, Market, Universe, Max Positions, Capital Allocation, Exit Style, Market Preferences, Optimization Priorities, Complexity, Explainability, and optional Additional Constraints. '
                    + 'Generate Prompt builds a client-side template (StoX Default Prompt; more templates can be added later), shows it in a read-only textarea, and auto-copies to the clipboard (toast: “AI prompt copied to clipboard.”). '
                    + 'Copy Again / Select All / Clear / Reset Defaults are available. Form values persist in browser localStorage. Attach /docs/stox-trading-artifacts-ai-guide.md when pasting into an external AI. No backend LLM call.',
            },
            {
                name: 'Strategy Registry',
                description:
                    'Open /strategy/registry to export/import Strategy JSON, validate packs, and Enable strategies for this portfolio (multiple may be enabled).',
            },
            {
                name: 'General tab',
                description:
                    'Name — label for your strategy (default: Minervini Strategy).\n'
                    + 'Description — free-text intent notes.\n'
                    + 'Save overwrites this strategy’s config in place. Optional `?strategy_id=` chooses which enabled strategy the editor is showing.',
            },
            {
                name: 'Eligibility Sources tab',
                description:
                    'Assign Screeners that admit stocks into scoring.\n'
                    + 'On — include this Screener in the union.\n'
                    + 'Priority — ordering / explainability only; a stock is eligible if it passes ANY enabled Screener (not all).\n'
                    + 'Compared against: latest completed Screener run hits (typically within ~72 hours). Stocks not in those hits are not eligible for new Open/Increase (holdings are still reviewed for exits).\n'
                    + 'N conditions ↗ — opens the Screener editor; Strategy never edits condition trees here.',
            },
            {
                name: 'Scoring Model — factors',
                description:
                    'Each enabled factor produces a 0–100 sub-score. Overall score = weighted average of enabled factors. Enabled Weights are auto-normalised to sum to exactly 100 on Save (and when you click Normalise now) — relative proportions are kept. You can edit free-form totals while drafting; Save is only blocked if every enabled weight is zero.\n'
                    + 'On — include factor in the blend; Off disables Weight/Min/Max/Parameters for that row.\n'
                    + 'Weight — share of overall score before/after normalisation (e.g. Relative Strength 35 means 35% when the total is already 100).\n'
                    + 'Min / Max — soft targets used when scoring that factor (Risk uses Max as a risk cap; Risk Min defaults to 0).\n'
                    + 'Compared against: Evaluation factor facts for that stock (RS, RSI/momentum, trend/SMA stack, volume, etc.), not raw rupee prices.',
            },
            {
                name: 'Scoring Model — parameters by factor',
                description:
                    'Relative Strength: lookback_days (sessions) and Benchmark (index dropdown, e.g. NIFTY50) — stock return vs that benchmark.\n'
                    + 'Momentum Score: rsi_period — RSI length for momentum strength.\n'
                    + 'Trend Score: sma_fast / sma_slow — SMA lengths for trend stack.\n'
                    + 'Volume Score: volume_sma_period — average volume window.\n'
                    + 'Risk Score: atr_period — ATR window; higher Risk score is riskier; Max caps acceptable risk.\n'
                    + 'Breakout / Market Regime / Sector Strength: may have no parameters (stub or discovery-fed).',
            },
            {
                name: 'Recommendation Thresholds tab',
                description:
                    'Unit: overall score points 0–100 (the Scoring Model blend). Not ₹, not %.\n'
                    + 'Minimum Overall Score — floor for actionable buy paths (factory ~80).\n'
                    + 'Open Position — score ≥ this → suggest new buy (factory ~85).\n'
                    + 'Increase Position — score ≥ this (and already held) → buy more (factory ~90).\n'
                    + 'Watch — interesting but usually not funded (factory ~60).\n'
                    + 'Reduce Position — score ≤ this → sell partial (factory ~40).\n'
                    + 'Exit Position — score ≤ this → sell all (factory ~20).\n'
                    + 'Compared against: the stock’s overall strategy score in this generation cycle. Portfolio rules, exits, gates, and cash can still change the final action.',
            },
            {
                name: 'Portfolio Rules tab',
                description:
                    'Maximum / Minimum Position Size % — share of portfolio value per idea (compared against suggested allocation %).\n'
                    + 'Maximum Cash Deployment % / Minimum Cash Reserve % — V1 generation-time cash limits compared against cash balance. The V3 portfolio cash reserve (required_cash_reserve) is set under Settings → Portfolio cash reserve % and uses invested/market-value, not % of cash.\n'
                    + 'Recommended minimum holdings — advisory count for generation (the engine does not open weak names just to hit it). Also the divisor for this strategy’s retained capital (allocated capital ÷ this count, nearest rupee). Leave blank if unset; retained capital is then not computed.\n'
                    + 'Maximum New Positions — cap on fresh Opens per generation cycle.\n'
                    + 'Maximum Exposure Per Stock % — concentration ceiling.\n'
                    + 'Behaviour toggles: Allow increase / partial exit / averaging up / averaging down — permit those action types when other rules would suggest them.',
            },
            {
                name: 'Capital Allocation tab',
                description:
                    'Applies only to Open / Increase ideas that survived thresholds, portfolio rules, and market gates.\n'
                    + 'Allocation method: Proportional by score (stronger scores get more cash), Simple ranking, or Equal weight.\n'
                    + 'Tie-breaking — secondary key when scores match (higher score / RS / momentum / breakout).\n'
                    + 'Score bands — map overall-score ranges to target allocation % of portfolio (e.g. 95–100 → 10%).\n'
                    + 'Compared against: available investable cash (Cash balance − reserved − reserve floor). If cash cannot fund the idea, it is demoted to Watch (unfunded).',
            },
            {
                name: 'Exit Strategy — each rule',
                description:
                    'Applies to open holdings. If any enabled rule triggers (mode = any), action is forced to Exit.\n'
                    + 'Moving Average Breakdown (Value = SMA period, e.g. 50) — compared against price vs that SMA; hits when close is below the MA.\n'
                    + 'Relative Strength Weakening (Value = RS score max, e.g. 40) — hits when RS factor score < Value.\n'
                    + 'Trend Weakening (Value = trend score max, e.g. 40) — hits when Trend factor score < Value.\n'
                    + 'Overall Score Exit (Value = overall score max, e.g. 20) — hits when overall score ≤ Value.\n'
                    + 'Maximum Loss (Value = %, e.g. 8) — hits when unrealized P/L % ≤ −Value (vs your cost basis).\n'
                    + 'ATR Stop (Value = multiple, e.g. 2) — hits when unrealized % ≤ −(multiple × ATR%).\n'
                    + 'Trailing Stop (Value = %, e.g. 10) — V1 proxy: hits when unrealized % ≤ −Value (drawdown from cost; not yet a true peak-trail engine).\n'
                    + 'Screener Exit (Value = screener picker) — hits when the holding’s stock_id appears in that screener’s latest completed run (~72h).',
            },
            {
                name: 'Market Gates tab',
                description:
                    'Optional. When Enable market gates is Off, Min sentiment / Allowed phases / Max risk are ignored (and disabled in the UI).\n'
                    + 'When On: compared against Dashboard Market Analysis outputs.\n'
                    + 'Min sentiment (0–100) — block new Open/Increase if market sentiment score < this.\n'
                    + 'Allowed phases — block new Open/Increase unless current market phase is checked.\n'
                    + 'Max risk (raw) — if market raw risk > this, block/shrink new entries.\n'
                    + 'Exits on holdings are not blocked by market gates.',
            },
            {
                name: 'Cash Management tab',
                description:
                    'Reservations enabled — use cash reservations for approved buys.\n'
                    + 'Reserve on approval — when you Approve an Open/Increase on Recommendations, cash is reserved.\n'
                    + 'Release on execution / cancellation / expiry — free the reservation when the idea closes.\n'
                    + 'Compared against: Cash page balance and reserved amounts. Available investable cash drives Capital Allocation.',
            },
            {
                name: 'Page subheader',
                description:
                    'Explains that Strategy is only a set of configurations. After Save and running the decision pipeline, recommended stocks appear on the Recommendations tab (/recommendations). Screeners still select eligible stocks; Strategy does not redefine eligibility rules.',
            },
            {
                name: 'Header card + Save',
                description:
                    'Always-visible snapshot: name, last modified, enabled eligibility Screeners, weight total, exit/market flags.\n'
                    + 'Save — writes the current config in place. After Save, run the pipeline from Recommendations to refresh ideas.',
            },
        ],
        concepts: [
            {
                name: 'Multiple enabled strategies',
                description:
                    'A portfolio may enable more than one strategy. Physical cash stays one pool; each enabled strategy has an allocation % of investable capital (Cash page; must sum to 100 to save). Defaults include Minervini Strategy. The editor `?strategy_id=` chooses which definition to edit. Save still updates that strategy in place.',
            },
            {
                name: 'Registry vs editor',
                description:
                    'Registry manages reusable JSON artifacts, drafts, and selection. The Strategy page edits the active portfolio strategy. Export never includes portfolio-local Screener ids — only slug / factory_key refs.',
            },
            {
                name: 'AI Strategy Designer',
                description:
                    'Prompt builder only — no in-app LLM. Generated text instructs an external assistant to obey the StoX AI Authoring Guide / Contract. You still Validate and Import JSON via Screener Registry and Strategy Registry.',
            },
            {
                name: 'Worked example — scoring four screener hits',
                description:
                    'Assume factory-like weights: RS 35, Trend 20, Momentum 15, Breakout 10, Volume 8, Market Regime 5, Sector 4, Risk 3 (total 100).\n'
                    + 'Four eligible stocks with factor scores (0–100):\n\n'
                    + 'RELIANCE — RS 92, Trend 88, Mom 80, Breakout 70, Vol 75, Mkt 70, Sec 65, Risk 25\n'
                    + 'Overall ≈ 0.35×92 + 0.20×88 + 0.15×80 + 0.10×70 + 0.08×75 + 0.05×70 + 0.04×65 + 0.03×25 ≈ 81.9\n\n'
                    + 'TCS — RS 78, Trend 74, Mom 68, Breakout 60, Vol 55, Mkt 70, Sec 60, Risk 30 → ≈ 70.4\n'
                    + 'INFY — RS 95, Trend 90, Mom 88, Breakout 85, Vol 80, Mkt 75, Sec 70, Risk 20 → ≈ 87.6\n'
                    + 'IDEA — RS 55, Trend 40, Mom 35, Breakout 30, Vol 40, Mkt 50, Sec 45, Risk 60 → ≈ 44.0\n\n'
                    + 'With Open ≥ 85, Increase ≥ 90, Watch ≥ 60, Reduce ≤ 40, Exit ≤ 20:\n'
                    + 'INFY ≈ 87.6 → Open (if not held).\n'
                    + 'RELIANCE ≈ 81.9 → below Open; may be Watch/Hold depending on opinion path (not a funded Open at 85).\n'
                    + 'TCS ≈ 70.4 → Watch band.\n'
                    + 'IDEA ≈ 44.0 → near Reduce if held; not an Open.\n'
                    + 'Ranking for capital: INFY first (highest score), then RELIANCE, then TCS. Only Open/Increase compete for cash.',
            },
            {
                name: 'Worked example — score bands vs thresholds',
                description:
                    'Overall score (0–100) vs factory-like thresholds: Exit ≤20 · Reduce ≤40 · Watch ≥60 · Open ≥85 · Increase ≥90.\n\n'
                    + '```text\n'
                    + 'Symbol    | Score | Band reading\n'
                    + '--------- | ----- | ------------\n'
                    + 'IDEA      | 44    | Near Reduce if held; not an Open\n'
                    + 'TCS       | 70    | Watch band\n'
                    + 'RELIANCE  | 82    | Below Open (≥85); Watch/Hold path\n'
                    + 'INFY      | 88    | Crosses Open (≥85); ranks first for capital\n'
                    + '```',
            },
            {
                name: 'Worked example — three holdings vs exit rules',
                description:
                    'Holding A (HDFC Bank): bought ₹1,500; latest ₹1,360; unrealized ≈ −9.3%. Max Loss Value = 8 → −9.3% ≤ −8% → Maximum Loss triggers → Exit.\n\n'
                    + 'Holding B (Asian Paints): bought ₹3,000; rallied to ₹3,400 then back to ₹3,050; unrealized vs cost ≈ +1.7%. Trailing Stop Value = 10 (V1 proxy vs cost, not peak): +1.7% is not ≤ −10% → does not trigger. (A true peak-trail would measure the drop from ₹3,400 ≈ −10.3% and might fire; V1 does not — it only looks at unrealized % from cost.)\n\n'
                    + 'Holding B′ (same Trailing Stop = 10, different path): bought ₹3,000; latest ₹2,650; unrealized ≈ −11.7%. −11.7% ≤ −10% → Trailing Stop (V1) triggers → Exit. This is the same math as Max Loss with Value = 10; use Max Loss for an explicit hard floor and Trailing when you intend a future peak-based trail.\n\n'
                    + 'Holding C (Tata Motors): close below 50-SMA while still green on P/L. MA Breakdown period = 50 → Moving Average Breakdown triggers → Exit even if score is still moderate.\n\n'
                    + 'Holding D appears in your “Weakening names” Screener Exit list → Screener Exit triggers → Exit regardless of score.\n\n'
                    + 'ATR Stop example: ATR% = 3.0, Value/multiple = 2 → stop at −6%. If unrealized = −6.5% → ATR Stop triggers.',
            },
            {
                name: 'Trailing / max-loss picture',
                description:
                    '```text\n'
                    + 'Price\n'
                    + '  |                 * peak (true trail would anchor here)\n'
                    + '  |               *\n'
                    + '  |        *----*\n'
                    + '  |      *\n'
                    + '  |----*  buy @ 1500 -------------------- cost basis\n'
                    + '  |                 \\\n'
                    + '  |                  * latest 1360  (−9.3% vs cost)\n'
                    + '  +------------------------------------> time\n'
                    + 'Max loss 8%: stop line ≈ 1500 × (1−0.08) = 1380\n'
                    + 'Latest 1360 is below 1380 → Max Loss hit.\n'
                    + '```',
            },
            {
                name: 'After filters — what you see',
                description:
                    'Suppose only INFY Open survives cash allocation and HDFC Bank Exit from Max Loss.\n'
                    + 'You see both on Recommendations (/recommendations): INFY under trade recommendations (Approve / Reject / Defer), HDFC Bank as Exit.\n'
                    + 'Approve INFY → Pending Execution (/transactions/pending) with cash reserved if Cash Management says so.\n'
                    + 'Record the broker fill → Transactions / Holdings update; Review later shows outcomes.\n'
                    + 'RELIANCE/TCS Watch or Hold stay on Recommendations as insights (not Telegram-notified).',
            },
            {
                name: 'Screeners vs Strategy',
                description: 'Screeners admit; Strategy scores, thresholds, allocates, and exits. Edit conditions only in /screeners.',
            },
            {
                name: 'Weights auto-normalise to 100',
                description:
                    'Enabled scoring weights are scaled to sum to exactly 100 on Save (largest-remainder, 2 decimal places). Relative proportions are preserved. Disabled factors keep their stored weight unused. Save is blocked only when no enabled factor has a positive weight.',
            },
            {
                name: 'Factory Momentum Strategy',
                description:
                    'Default seed only: Minervini eligibility; RS35/Trend20/Mom15/Breakout10/Vol8/Mkt5/Sec4/Risk3; Open≥85 Increase≥90 Watch≥60; exit on; market gates off. Fully editable after seed.',
            },
        ],
        related: ['trading-os-flow', 'screener', 'screener-registry', 'strategy-registry', 'authoring-trading-artifacts', 'ai-authoring-contract', 'trading-cookbook', 'recommendations', 'pending-execution', 'cash', 'dashboard', 'backtests'],
    },
    {
        id: 'backtests',
        keyword: 'backtests',
        aliases: ['strategy-backtest', 'backtest', 'paper-trading'],
        title: 'Strategy Backtests',
        routeLabel: '/backtests',
        match: (p) => pathStarts(p, '/backtests'),
        summary: 'Paper-trade the active Strategy over historical dates — runs, progress, trades, timeline, and statistics.',
        overview:
            'Strategy Backtests simulate how your **active Strategy** would have traded over a chosen lookback window (1y / 6m / 3m / 1m / 15d) using historical prices and the same eligibility, scoring, exit, and capital rules as the live Recommendation pipeline — but **without** writing to your ledger or Recommendations queue.\n\n'
            + 'Strategy Backtests require at least one **enabled eligibility Screener** on the Strategy (same union rules as live Recommendations). Without eligibility sources, Start is rejected.\n\n'
            + 'Each run is chunked across HTTP requests (time-budgeted server slices ~20s). The UI polls **Continue** until the run completes or fails. Session continuity uses a browser token (`lido_strategy_backtest_session` in localStorage) so eligibility precompute can resume safely.\n\n'
            + 'Open a completed run for portfolio growth chart, per-stock trade timeline matrix, trades/transactions tables, daily snapshots, and the full statistics grid (return %, CAGR, drawdown, win rate, holding periods, utilization, etc.).\n\n'
            + 'Backtests complement — but do not replace — Screener backtests on the Screener editor (those test eligibility rules only). Strategy backtests exercise the full Strategy policy on a paper portfolio.',
        controls: [
            {
                name: 'New Backtest',
                description: 'Choose period (range_key), initial capital (min ₹1,000), optional name/notes/tags, then Start. A notice warns that the run can take several minutes and that you should keep the page open. Progress shows stage, % complete, current simulation date, and eligibility precompute % while PREPARING.',
            },
            {
                name: 'Continue polling',
                description: 'After Start, the client POSTs /v1/backtests/{id}/continue while continued=true (up to ~2000 slices). Navigate to the detail page automatically when completed.',
            },
            {
                name: 'Open / Delete',
                description: 'History table actions. Delete is permanent. Duplicate is reserved for a future release.',
            },
            {
                name: 'Detail — Save name / notes / tags',
                description: 'PUT /v1/backtests/{id} updates metadata without re-running simulation.',
            },
            {
                name: 'Detail — Resume banner',
                description: 'If you open an in-progress run, the page auto-resumes Continue polling until completed or failed.',
            },
            {
                name: 'Trade timeline',
                description: 'Sticky symbol column × trading-day columns. Cell day count coloured green (profitable close), red (loss), or muted (open). Horizontal scroll for long periods.',
            },
            {
                name: 'Portfolio growth chart',
                description: 'Daily portfolio value line with reference line at initial capital. Tooltip: date, portfolio, cash, invested value.',
            },
            {
                name: 'Collapsible Trades / Transactions',
                description: 'Trades and Transactions tables start collapsed (row count shown in the header). Expand a section to inspect the full table without scrolling past long lists by default.',
            },
            {
                name: 'Scroll to top / bottom',
                description: 'Floating arrow buttons at the bottom-right of the detail page jump to the start or end of the main scroll area.',
            },
        ],
        concepts: [
            {
                name: 'Active Strategy snapshot',
                description: 'Each run locks the active Strategy version (and linked Screener versions) at start time so results stay reproducible.',
            },
            {
                name: 'Paper portfolio',
                description: 'Simulated cash and positions — no broker orders, reservations, or ledger transactions.',
            },
            {
                name: 'Eligibility precompute',
                description: 'PREPARING stage rebuilds historical screener hits per day before day-by-day simulation.',
            },
            {
                name: 'Statistics block',
                description: 'Aggregates at completion: return_pct, CAGR, maximum_drawdown, win_rate, trade counts, holding periods, utilization, cash_remaining, maximum_concurrent_positions. Per-trade CAGR is blank for holds shorter than 30 calendar days (annualizing overnight gains is meaningless and can overflow storage).',
            },
        ],
        related: ['strategy', 'screener', 'screener-editor', 'recommendations', 'review', 'trading-os-flow'],
    },
    {
        id: 'strategy-registry',
        keyword: 'strategy-registry',
        aliases: ['strategy-artifacts', 'strategy-json', 'import-strategy', 'select-strategy'],
        title: 'Strategy Registry',
        routeLabel: '/strategy/registry',
        match: (p) => pathStarts(p, '/strategy/registry') || pathStarts(p, '/settings/strategy-registry'),
        summary: 'Import/export Strategy JSON artifacts — mandatory fields, uniqueness rules, eligibility refs, scoring_model weights, and Enable strategies (multiple may be enabled per portfolio).',
        overview:
            'The Strategy Registry turns portfolio strategies into reusable Trading Artifacts. A portfolio may have **multiple enabled Strategies** at once. '
            + 'The registry adds slug, metadata, artifact_version, definition_hash, and version history on top of the same config the Recommendation engine already uses.\n\n'
            + 'Export downloads the portable Trading Artifact JSON envelope. **Validate** checks the envelope. **Import** stays disabled until validation succeeds, then creates a **draft** — use **Enable** to turn it on without disabling other enabled strategies. '
            + 'Existing Minervini (`momentum_factory`) migrates automatically to slug `momentum_strategy` with eligibility linked to `minervini_trend_template`.\n\n'
            + '## Importing JSON — start here\n\n'
            + 'If Validate or Import reports many field errors, you almost always missed a **mandatory** envelope field, forgot `scoring_model`, enabled weights that do not sum to 100, or embedded a Screener condition tree. '
            + 'Strategies reference Screeners by **slug / factory_key only** — never paste `definition.root` into a Strategy. Use the minimum schema below, then expand.\n\n'
            + '### Minimum valid envelope (copy/paste starting point)\n\n'
            + '```json\n'
            + '{\n'
            + '  "schema_version": "1.0",\n'
            + '  "artifact_type": "strategy",\n'
            + '  "slug": "my_first_strategy",\n'
            + '  "name": "My First Strategy",\n'
            + '  "metadata": {\n'
            + '    "scope": "portfolio",\n'
            + '    "status": "draft",\n'
            + '    "origin": "user",\n'
            + '    "description": "RS-only example strategy"\n'
            + '  },\n'
            + '  "definition": {\n'
            + '    "eligibility_sources": [\n'
            + '      {\n'
            + '        "screener_slug": "minervini_trend_template",\n'
            + '        "screener_factory_key": "minervini_trend_template",\n'
            + '        "enabled": true,\n'
            + '        "priority": 1\n'
            + '      }\n'
            + '    ],\n'
            + '    "scoring_model": [\n'
            + '      {\n'
            + '        "key": "relative_strength",\n'
            + '        "enabled": true,\n'
            + '        "weight": 100,\n'
            + '        "minimum": 70,\n'
            + '        "maximum": null,\n'
            + '        "parameters": {}\n'
            + '      }\n'
            + '    ]\n'
            + '  }\n'
            + '}\n'
            + '```\n\n'
            + 'That JSON has every runtime-required field. Enabled weights sum to **100**. Eligibility points at a Screener by slug '
            + '(import / seed that Screener in this portfolio first if it is missing — factory `minervini_trend_template` is auto-ensured for Minervini).\n\n'
            + '### Multi-factor scoring example\n\n'
            + 'Enabled weights must still sum to 100:\n\n'
            + '```json\n'
            + '"scoring_model": [\n'
            + '  { "key": "relative_strength", "enabled": true, "weight": 50, "minimum": 70, "maximum": null, "parameters": {} },\n'
            + '  { "key": "momentum_score", "enabled": true, "weight": 50, "minimum": 60, "maximum": null, "parameters": {} }\n'
            + ']\n'
            + '```\n\n'
            + '### Mandatory vs optional fields\n\n'
            + '| Field | Required? | Unique? | What to put |\n'
            + '|-------|-----------|---------|-------------|\n'
            + '| `schema_version` | **Yes** | No | Always `"1.0"` |\n'
            + '| `artifact_type` | **Yes** | No | Always `"strategy"` |\n'
            + '| `slug` | **Yes** | **Yes** (per portfolio) | Stable id: `a-z`, `0-9`, `_` only (e.g. `swing_rs`). See **Slug** / **Uniqueness** below |\n'
            + '| `name` | **Yes** | Soft unique (per portfolio) | Human label shown in the UI; Import may append `" (import)"` on collision |\n'
            + '| `metadata` | **Yes** | No | An object — may be `{}`, but prefer `description` / `origin` / `status` |\n'
            + '| `definition` | **Yes** | No | Object with scoring + optional eligibility / editor sections |\n'
            + '| `definition.scoring_model` | **Yes** | Keys unique within the array | Non-empty array of scoring rows; **enabled** weights must sum to **100** (alias: `indicators`) |\n'
            + '| `definition.eligibility_sources` | Optional | Prefer unique screener refs | Array of Screener refs by `screener_slug` / `screener_factory_key` |\n'
            + '| `artifact_id` | Optional | Local DB id | Leave out on create; export may include a portfolio-local id |\n'
            + '| `artifact_version` | Optional | No | Integer ≥ 1; Import starts versions at 1 anyway |\n'
            + '| `definition_hash` | Optional | Content fingerprint | Recalculated on Import — do not invent this |\n'
            + '| `minimum_engine_version` | Optional | No | e.g. `"1.1.0"` |\n'
            + '| `dependencies` | Optional | No | Array of refs; export fills this |\n'
            + '| `validation` | Optional | No | Embedded hints; not executed as rules |\n'
            + '\n'
            + 'Note: the database stores strategy config in `config_json` on version rows, but the **import JSON field name is `definition`** (same idea as Screener’s `definition` vs `definition_json`).\n\n'
            + '**Forbidden:** `definition.root`, `definition.children`, or embedding `definition` / `root` inside an eligibility source — Strategies must not carry Screener trees.\n\n'
            + '### Uniqueness rules\n\n'
            + '| Field | Scope | Rule |\n'
            + '|-------|-------|------|\n'
            + '| `slug` | One portfolio | Must be unique among that portfolio’s strategies. On Import collision the system may suffix `_import_<hex>` (or create-path may use `_2`, `_3`, …). |\n'
            + '| `name` | One portfolio | Soft unique — Import may rename to `"… (import)"` if the display name already exists. |\n'
            + '| `metadata.factory_key` | Built-ins | Stable factory identity (e.g. `momentum_factory`). Not required for user imports. |\n'
            + '| Enablement | One portfolio | Multiple strategies may be `active` (enabled) at once. Import always creates **draft**; **Enable** turns that strategy on without disabling others. |\n'
            + '| `scoring_model[].key` | One envelope | Each catalogue key should appear once; duplicates are collapsed when normalised. |\n'
            + '| `screener_slug` / `screener_factory_key` | Eligibility row | Identify a Screener in this portfolio (or a factory Screener the system can ensure). Not unique across strategies — many strategies may share the same Screener. |\n'
            + '\n'
            + '### What each field means\n\n'
            + '**`schema_version`** — Which envelope contract this file uses. Must be `"1.0"`. Wrong or missing → `SCHEMA_VERSION_UNSUPPORTED` / empty schema errors.\n\n'
            + '**`artifact_type`** — Discriminator so the registry knows this is a Strategy (not Screener/Indicator). Must be `"strategy"`.\n\n'
            + '**`slug`** — Machine-stable key for this strategy inside a portfolio. Strategies are listed, exported, and selected by this id. '
            + 'Use snake_case like `swing_rs` or `momentum_strategy`. Allowed characters after normalisation: `a-z`, `0-9`, `_` '
            + '(spaces and punctuation become `_`). Keep it short, unique, and meaningful — do **not** put a sentence here; that belongs in `name` / `metadata.description`. '
            + 'If the slug already exists on Import, the system may suffix it (e.g. `_import_ab12`) so the import still succeeds.\n\n'
            + '**`name`** — Display title in Registry lists and the Strategy editor. Example: `"Swing RS Strategy"`. If the name collides, Import may append `" (import)"`.\n\n'
            + '**`metadata`** — Descriptive object (required as an object; keys inside are optional):\n\n'
            + '| Key | Required? | Meaning / example |\n'
            + '|-----|-----------|-------------------|\n'
            + '| `scope` | Optional | Usually `"portfolio"` |\n'
            + '| `description` | Optional | Human prose, e.g. `"RS + momentum swing"` |\n'
            + '| `intent` | Optional | Why this strategy exists |\n'
            + '| `summary` | Optional | Short blurb (export often mirrors description) |\n'
            + '| `tags` | Optional | Array of strings, e.g. `["swing", "rs"]` |\n'
            + '| `status` | Optional | Hint: `draft` / `active` / `archived` — Import **always** stores draft regardless |\n'
            + '| `origin` | Optional | `factory` / `user` / `imported` / … |\n'
            + '| `factory_key` | Optional | Built-in id, e.g. `momentum_factory` |\n'
            + '| `is_selected` / `is_enabled` | Export-only | Whether this row is currently enabled (`STATUS_ACTIVE`); Import ignores it — use **Enable**. Multiple strategies may be enabled. |\n'
            + '| `storage` / `legacy_id` | Export-only | Internal pointers; leave out on hand-written JSON |\n'
            + '\n'
            + '**`definition`** — Strategy runtime config. Validate requires scoring; eligibility is strongly recommended for a working Recommendations feed.\n\n'
            + '**`definition.eligibility_sources`** — Which Screeners feed candidates. Each source (do **not** embed condition trees):\n\n'
            + '```json\n'
            + '{\n'
            + '  "screener_slug": "minervini_trend_template",\n'
            + '  "screener_factory_key": "minervini_trend_template",\n'
            + '  "enabled": true,\n'
            + '  "priority": 1\n'
            + '}\n'
            + '```\n\n'
            + '| Field | Required? | Meaning |\n'
            + '|-------|-----------|--------|\n'
            + '| `screener_slug` | One of slug / factory_key / (local) screener_id | Portable Screener id (preferred) |\n'
            + '| `screener_factory_key` | One of the above | Factory Screener key (alias `factory_key` accepted) |\n'
            + '| `screener_id` | Avoid in packs | Portfolio-local DB id — export strips it; do not rely on it for portability |\n'
            + '| `enabled` | Optional | Default `true` |\n'
            + '| `priority` | Optional | Integer order (lower = sooner); default `1` |\n'
            + '| `min_artifact_version` | Optional | Minimum Screener artifact version if you pin one |\n'
            + '| `definition` / `root` | **Forbidden** | Embedding a Screener tree fails Validate / Import |\n'
            + '\n'
            + 'Empty `eligibility_sources` may pass Validate, but Recommendations will have no Screener feed until you add refs.\n\n'
            + '**`definition.scoring_model`** (alias **`indicators`**) — Weighted score rows:\n\n'
            + '```json\n'
            + '{\n'
            + '  "key": "relative_strength",\n'
            + '  "enabled": true,\n'
            + '  "weight": 50,\n'
            + '  "minimum": 70,\n'
            + '  "maximum": null,\n'
            + '  "parameters": {}\n'
            + '}\n'
            + '```\n\n'
            + '| Field | Required? | Meaning |\n'
            + '|-------|-----------|--------|\n'
            + '| `key` | **Yes** | Strategy-scorable catalogue id (see list below) |\n'
            + '| `enabled` | Strongly recommended | Only **enabled** rows count toward the weight sum |\n'
            + '| `weight` | **Yes** if enabled | Share of overall score; all enabled weights must sum to **100** (±0.01) |\n'
            + '| `minimum` | Optional | Soft/hard gate on that factor (number or `null`) |\n'
            + '| `maximum` | Optional | Upper gate when the catalogue supports it |\n'
            + '| `parameters` | Optional | Indicator-specific knobs object (defaults filled on normalise) |\n'
            + '\n'
            + 'Rules:\n'
            + '- At least one enabled row with positive weight is required\n'
            + '- Prefer canonical keys in new JSON (aliases like `momentum` → `momentum_score` may resolve)\n'
            + '- Unknown keys fail Validate with `STRATEGY_KEYS_REGISTRY`\n\n'
            + '**Strategy-scorable keys** (canonical): `relative_strength`, `momentum_score`, `trend_score`, `breakout_score`, `volume_score`, `market_regime`, `sector_strength`, `risk_score`.\n\n'
            + '**Other optional `definition` sections** (appear in Export / Strategy editor; preserved on Import via normalise; not hard-checked by Validate the same way as scoring):\n'
            + '- `thresholds` — label bands (e.g. Open / Increase / Hold / Watch cut-offs)\n'
            + '- `portfolio_rules` — position size %, concentration, etc.\n'
            + '- `exit_strategy` — exit rules (`enabled`, `mode`, `rules`)\n'
            + '- `market_gates` — market-regime gates\n'
            + '- Start from an Export of a working strategy when you need these filled in correctly.\n\n'
            + '**`artifact_version` / `definition_hash`** — Versioning metadata. Export includes them; Import recomputes the hash and starts history for the new local draft.\n\n'
            + '**`dependencies`** — Export lists `uses_screener` / `uses_indicator` refs for packaging. Optional on Import; resolved from eligibility + scoring keys.\n\n'
            + '### Recommended import workflow\n\n'
            + '1. Ensure referenced Screeners exist in this portfolio (Screener Registry → import Screener JSON, or use a factory screener like `minervini_trend_template`).\n'
            + '2. Start from the minimum example above (or Export an existing working strategy and edit a copy — best for thresholds / exits / gates).\n'
            + '3. Paste into Strategy Registry → **Validate** and fix every listed path (`$.slug`, `$.definition.scoring_model`, …).\n'
            + '4. Use **Import** (enabled only after Validate succeeds) — creates a **draft** (does not change Recommendations yet).\n'
            + '5. Click **Enable** on the new row when you want it enabled (other enabled strategies stay enabled).\n'
            + '6. Optionally open **Edit** (`/strategy?strategy_id=…`) to refine tabs visually and Save.\n\n'
            + '### Common validation / import errors\n\n'
            + '| Message / code | Likely cause |\n'
            + '|----------------|--------------|\n'
            + '| `slug is required` | Missing or blank `slug` |\n'
            + '| `name is required` | Missing or blank `name` |\n'
            + '| `definition object is required` | Omitted `definition` (or used a DB-only field name) |\n'
            + '| `metadata object is required` | Omitted `metadata` or not an object |\n'
            + '| `STRATEGY_SCORING_REQUIRED` | Missing `scoring_model` / `indicators` |\n'
            + '| `STRATEGY_WEIGHTS_SUM_100` | Enabled weights ≠ 100, or none enabled |\n'
            + '| `STRATEGY_KEYS_REGISTRY` / not strategy-scorable | Unknown or non-scorable `key` |\n'
            + '| `STRATEGY_ELIGIBILITY_REFS` | Source missing slug, factory_key, and screener_id |\n'
            + '| `STRATEGY_NO_EMBEDDED_SCREENER` | Put a Screener `root` / `children` tree on the Strategy |\n'
            + '| Must not embed Screener definitions | Eligibility row contains `definition` / `root` |\n'
            + '| Slug / name already exists | Pick another or let Import rename |\n'
            + '\n'
            + STRATEGY_REGISTRY_GUIDE_EXTRAS,
        controls: [
            { name: 'Search / filters', description: 'Filter by status (active/draft/archived) and origin (factory/user).' },
            { name: 'Enable', description: 'Turn this strategy on for the portfolio. Other enabled strategies stay enabled. Success shows a toast. Recommendation generate still uses one editor/default strategy until a later V3 workstream.' },
            { name: 'Export JSON', description: 'Download the portable Trading Artifact envelope (schema_version, slug, name, metadata, definition with eligibility_sources + scoring_model, dependencies). Best template for a new import — includes thresholds/exits/gates when present.' },
            { name: 'Validate', description: 'Check pasted JSON against Trading Artifact Strategy rules. On success, a green “Validated successfully” cue appears above Validate/Import and the JSON result panel still shows details. Import stays disabled until this reports ok. Editing the JSON clears validation.' },
            { name: 'Import', description: 'Enabled only after successful Validate. Creates a draft strategy in this portfolio and shows a success toast (not an inline alert). Does not change Recommendations until Enable. Mandatory: schema_version, artifact_type, slug, name, metadata, definition.scoring_model with enabled weights = 100.' },
            { name: 'Download AI authoring guide (.md)', description: 'Download /docs/stox-trading-artifacts-ai-guide.md — consolidated Indicator + Screener + Strategy + Authoring + Cookbook Markdown for AI agents and offline authoring.' },
            { name: 'Edit', description: 'Jump to /strategy?strategy_id=… to edit that strategy’s tabs and Save.' },
            { name: 'Version history', description: 'On detail for owned strategies, list definition snapshots and change notes. Draft definition-hash changes append versions; active editor Save remains in-place BC.' },
        ],
        concepts: [
            {
                name: 'Slug',
                description:
                    'Stable machine id (snake_case: `swing_rs`). Unique per portfolio. Used for uniqueness and selection. Not the display title — that is `name`. Only a–z, 0–9, and underscore after normalisation.',
            },
            {
                name: 'Uniqueness',
                description:
                    'slug is unique per portfolio (Import may suffix on collision). name is soft-unique (may get " (import)"). Multiple strategies may be enabled per portfolio. scoring keys should appear once in scoring_model. Screener refs may be shared across strategies.',
            },
            {
                name: 'Multiple enabled strategies',
                description:
                    'Enablement rule: more than one STATUS_ACTIVE strategy may exist per portfolio. Import always creates draft; Enable turns a strategy on without archiving others. The editor strategy_id query is UI selection, not exclusive-active.',
            },
            {
                name: 'No Screener duplication',
                description:
                    'eligibility_sources reference Screeners by screener_slug / screener_factory_key. Condition trees stay on Screener Registry — never embed root/children on a Strategy.',
            },
            {
                name: 'definition vs config_json',
                description:
                    'Import/export JSON uses the field `definition`. Version rows store the same config in `config_json`. Do not invent a `config_json` field on the envelope.',
            },
            {
                name: 'scoring_model vs indicators',
                description:
                    'Portable JSON prefers scoring_model. The engine also accepts indicators as an alias; Import normalises both. Enabled weights must sum to 100.',
            },
            {
                name: 'Mandatory envelope fields',
                description:
                    'schema_version ("1.0"), artifact_type ("strategy"), slug, name, metadata (object), and definition.scoring_model with enabled weights summing to 100.',
            },
            {
                name: 'Minervini auto-migrate',
                description:
                    'ensureActive / seedFactoryStrategy backfills slug momentum_strategy, metadata, and Minervini screener eligibility links without overwriting user-edited scores.',
            },
            {
                name: 'Optional sections are live',
                description:
                    'thresholds, portfolio_rules, exit_strategy, and market_gates are runtime-usable and Import-preserved — not reserved. Prefer Export-then-edit for full defaults.',
            },
        ],
        related: ['strategy', 'screener-registry', 'indicator-registry', 'recommendations', 'authoring-trading-artifacts', 'trading-artifact-runtime', 'trading-cookbook', 'settings'],
    },
    {
        id: 'review',
        keyword: 'review',
        aliases: ['outcomes', 'performance-review'],
        title: 'Review',
        routeLabel: '/review',
        match: (p) => pathStarts(p, '/review'),
        summary: 'Outcomes dashboard for recommendations, insights, and recent review decisions.',
        overview:
            'Review summarises how recommendations and insights performed after decisions and fills — actionable outcomes, insight outcomes, orders, and recent review decisions. It is the last stage after Recommendations → Pending Execution → Transactions.',
        controls: [
            { name: 'Outcome tables', description: 'Browse actionable vs insight outcomes and linked orders.' },
            { name: 'Recent decisions', description: 'Audit trail of Approve / Reject / Defer activity.' },
        ],
        concepts: [
            { name: 'Closed loop', description: 'Review completes the Trading OS loop after execution.' },
        ],
        related: ['trading-os-flow', 'recommendations', 'pending-execution', 'dashboard'],
    },
    {
        id: 'notifications',
        keyword: 'notifications',
        aliases: ['telegram', 'notification-history'],
        title: 'Notifications',
        routeLabel: '/notification-history',
        match: (p) => pathStarts(p, '/notification-history'),
        summary: 'History of Telegram (and related) notifications sent for this portfolio.',
        overview:
            'Open Notification history from Settings → Portfolio (Alerts & notifications). Inspect outbound messages for this portfolio. Delivery uses Telegram when configured under portfolio settings and alert / schedule rules. Recommendation Telegram messages are sent only for actionable trades (Open / Increase / Reduce / Exit) — HOLD and WATCH insights are not notified.',
        controls: [
            { name: 'History list', description: 'Browse recent messages and delivery status where available.' },
            { name: 'Retry', description: 'Re-attempt a failed delivery when the API supports it.' },
            { name: 'Back to settings', description: 'Return to Settings → Portfolio where Telegram schedules and credentials are configured.' },
        ],
        concepts: [
            { name: 'Not a main tab', description: 'Notification history is reached from Portfolio settings, not the primary nav.' },
            { name: 'Telegram-only channel', description: 'Production notifications are Telegram Bot API based.' },
            { name: 'Actionable only', description: 'Recommendation notify skips HOLD / WATCH; those stay in-app as insights.' },
            { name: 'Schedules', description: 'Calendar reminders, screener results, and alert policies can enqueue messages.' },
        ],
        related: ['alert-policies', 'calendar', 'settings'],
    },
    {
        id: 'patterns',
        keyword: 'patterns',
        aliases: ['candlesticks', 'chart-patterns'],
        title: 'Patterns',
        routeLabel: '/patterns',
        match: (p) => pathStarts(p, '/patterns'),
        summary: 'Educational chart + candlestick guide with SVG sketches and deep links.',
        overview:
            'Patterns is both a learning guide and the reference for scanner IDs. Toggle Chart patterns vs Candlesticks, search, expand cards, and use deep links like `/patterns#hammer`.',
        controls: [
            { name: 'Section toggle', description: 'Switch Chart patterns / Candlesticks (preference remembered).' },
            { name: 'Search', description: 'Filter pattern cards by name.' },
            { name: 'Expandable cards', description: 'Characteristics, meaning, and OHLCV math rules plus SVG sketch.' },
        ],
        concepts: [
            { name: 'Detection window', description: 'Scanners run on cached daily bars; matches complete on the latest bar of the window.' },
            { name: 'Deep links', description: 'Dashboard and watchlist pattern names jump to the matching guide card.' },
        ],
        related: ['watchlist', 'dashboard', 'holdings'],
    },
    {
        id: 'calendar',
        keyword: 'calendar',
        aliases: ['events', 'expiry'],
        title: 'Calendar',
        routeLabel: '/calendar',
        match: (p) => pathStarts(p, '/calendar'),
        summary: 'Per-portfolio market events plus global trade holidays, with optional Telegram reminders.',
        overview:
            'Track F&O / options expiry templates, custom recurring events, and admin-defined trade holidays on a year grid. Trade holidays appear on every portfolio calendar. Scheduled trade-alert Telegram digests are skipped on weekends and trade holidays (markets closed). Optional event reminders still use Telegram when configured.',
        controls: [
            { name: 'Year grid', description: 'Color markers for event days; open a day for details.' },
            { name: 'Templates / custom events', description: 'Add expiry-style or custom recurrence rules for the active portfolio.' },
            { name: 'Trade holiday (admin)', description: 'Mark an event as a global trade holiday so everyone sees it and trade-alert Telegram is suppressed that day.' },
            { name: 'Reminders', description: 'Configure advance notice for portfolio events when Telegram is set up (not used for trade holidays).' },
        ],
        concepts: [
            { name: 'Portfolio-scoped events', description: 'Ordinary events belong to the active portfolio only.' },
            { name: 'Global trade holidays', description: 'Admin-created holidays (profile_id null, category trade_holiday) visible to all portfolios.' },
            { name: 'Notification quiet days', description: 'Weekends and trade holidays skip scheduled trade-alert Telegram; Settings → Test telegram still works any day.' },
        ],
        related: ['dashboard', 'notifications', 'alert-policies'],
    },
    {
        id: 'knowledge',
        keyword: 'knowledge',
        aliases: ['knowledge-board', 'notes'],
        title: 'Knowledge Board',
        routeLabel: '/knowledge-board',
        match: (p) => pathIs(p, '/knowledge-board'),
        summary: 'Research notes with tags, editors, images, and export.',
        overview:
            'Capture market research notes for the portfolio. Use Simple, Formatted (TipTap), or Markdown editors with autosave, images, color palettes, pin/archive, and bulk export.',
        controls: [
            { name: 'Read / Manage toggle', description: 'Clean reading view vs checkboxes and larger action icons (pin, edit, duplicate, archive, delete) on each note card.' },
            { name: 'Editors', description: 'Simple / Formatted / Markdown with autosave.' },
            { name: 'Images', description: 'Embed resized images; click for full-size lightbox.' },
            { name: 'Export', description: 'Plain, Markdown, or AI-friendly bulk export.' },
            { name: 'Tags page', description: 'Open Knowledge Tags from the sidebar (Knowledge group) or manage at `/knowledge-board/tags`.' },
        ],
        concepts: [
            { name: 'Portfolio notes', description: 'Notes belong to the active portfolio context.' },
        ],
        related: ['knowledge-tags', 'watchlist'],
    },
    {
        id: 'knowledge-tags',
        keyword: 'knowledge-tags',
        aliases: ['tags'],
        title: 'Knowledge tags',
        routeLabel: '/knowledge-board/tags',
        match: (p) => pathStarts(p, '/knowledge-board/tags'),
        summary: 'Create and organise tags used on Knowledge Board notes.',
        overview: 'Maintain the tag vocabulary for notes so you can filter and group research consistently.',
        controls: [
            { name: 'Tag list', description: 'Add, rename, or remove tags used across notes.' },
        ],
        concepts: [
            { name: 'Tagging', description: 'Tags are metadata for search and organisation, not trading signals.' },
        ],
        related: ['knowledge'],
    },
    {
        id: 'profile',
        keyword: 'profile',
        aliases: ['account-profile', 'password', 'photo'],
        title: 'Profile',
        routeLabel: '/profile',
        match: (p) => pathIs(p, '/profile'),
        summary: 'Display name, password, and profile photo for your user account.',
        overview:
            'Update how you appear in the header, change your password, and upload or remove a profile photo. Username (email) is read-only. Changing your password keeps this device signed in and automatically signs out other devices.',
        controls: [
            { name: 'Profile photo', description: 'Upload, change, or remove the avatar image.' },
            { name: 'Display name', description: 'Shown next to the avatar in the header menu.' },
            {
                name: 'Change password',
                description:
                    'Requires current password confirmation. On success, other devices are signed out automatically; this device stays signed in.',
            },
        ],
        concepts: [
            { name: 'Session auth', description: 'Sign-in uses Sanctum SPA cookies, not bearer tokens in localStorage.' },
            {
                name: 'Credential-change sessions',
                description:
                    'A successful password change revokes other browser sessions and invalidates remember-me cookies on other devices (PD-006).',
            },
        ],
        related: ['portfolios', 'settings'],
    },
    {
        id: 'portfolios',
        keyword: 'portfolios',
        aliases: ['multi-portfolio', 'profiles'],
        title: 'Portfolios',
        routeLabel: '/portfolios',
        match: (p) => pathStarts(p, '/portfolios'),
        summary: 'Create and manage named portfolios; switch the active one from the header.',
        overview:
            'Each user can own multiple portfolios. The header switcher sets the active portfolio (`X-Profile-Id`) for almost all screens.',
        controls: [
            { name: 'Create portfolio', description: 'Add a named portfolio profile.' },
            { name: 'Header switcher', description: 'Change the active portfolio without leaving the current page.' },
        ],
        concepts: [
            { name: 'Active portfolio', description: 'API calls and UI data scopes follow the selected portfolio.' },
        ],
        related: ['profile', 'settings', 'cash'],
    },
    {
        id: 'settings',
        keyword: 'settings',
        aliases: ['preferences', 'fees', 'portfolio-settings'],
        title: 'Settings',
        routeLabel: '/settings',
        match: (p) => pathStarts(p, '/settings')
            && !pathStarts(p, '/settings/alert-policies')
            && !pathStarts(p, '/settings/sync-logs')
            && !pathStarts(p, '/settings/admin-alerts')
            && !pathStarts(p, '/settings/universe-price-sync')
            && !pathStarts(p, '/settings/data-quality')
            && !pathStarts(p, '/settings/indicators')
            && !pathStarts(p, '/settings/screener-registry')
            && !pathStarts(p, '/settings/strategy-registry')
            && !pathStarts(p, '/settings/users'),
        summary: 'Global (admin), Portfolio, and Account settings — fees, Telegram, cash reserve %, sync, active sessions, and links.',
        overview:
            'Settings is the only Administration item in the primary sidebar. Open it for Global (admins), Portfolio, and Account preferences. On Account, Active sessions lists your signed-in devices so you can revoke one session or log out other devices. Admin tools (users, sync logs, data quality, indicator/screener/strategy registries, universe sync, alert policies) are linked from Settings cards — they are not separate sidebar entries.',
        controls: [
            { name: 'Settings tabs', description: 'Navigate Global / Portfolio / Account sections.' },
            { name: 'Fee components', description: 'Drive auto fees on buy/sell ledger rows.' },
            { name: 'Telegram', description: 'Bot token and chat id for portfolio notifications.' },
            { name: 'Portfolio cash reserve %', description: 'Portfolio-level OD-19 percentage. Required reserve rupees = this % × max(currently held invested amount, current holdings market value). Leave blank for 0 (no configured reserve). Not a % of cash. Withdrawals are not blocked if cash falls below the resulting rupee floor; Dashboard warns instead.' },
            { name: 'Notification history', description: 'Open /notification-history from Portfolio → Alerts & notifications to audit Telegram deliveries.' },
            {
                name: 'Active sessions',
                description:
                    'Account tab: list devices (IP, browser summary, last activity). Log out other devices keeps this browser signed in. Revoke removes one non-current session; revoking the current session signs you out.',
            },
            { name: 'Admin shortcuts', description: 'From Global settings: Admin alerts, Sync logs, Universe price sync, Data Quality, Indicator/Screener/Strategy registries.' },
            { name: 'Screener Registry (admin)', description: 'Admin shortcut to Screener artifact import/export and catalogue.' },
            { name: 'Strategy Registry (admin)', description: 'Admin shortcut to Strategy artifact import/export, selection, and catalogue.' },
        ],
        concepts: [
            {
                name: 'Manual vs automatic session revoke',
                description:
                    'Active sessions controls are manual. Changing your password (or accepting an admin password-reset link) also signs out other devices automatically while keeping the active/new session.',
            },
            { name: 'Admin vs portfolio scope', description: 'Some tools (users, sync logs, universe sync) are admin-only.' },
            { name: 'Portfolio cash reserve', description: 'A non-investable, non-lendable rupee floor. It is not a separate bank account and not strategy cash.' },
            { name: 'Not in sidebar', description: 'Settings sub-pages and registries stay off the primary sidebar; reach them from Settings or parent product pages.' },
        ],
        related: ['alert-policies', 'universe-price-sync', 'data-quality-center', 'indicator-registry', 'screener-registry', 'strategy-registry', 'users', 'notifications', 'cash', 'dashboard'],
    },
    {
        id: 'alert-policies',
        keyword: 'alert-policies',
        aliases: ['alerts', 'rules'],
        title: 'Alert policies',
        routeLabel: '/settings/alert-policies',
        match: (p) => pathStarts(p, '/settings/alert-policies'),
        summary: 'Rule builder on open holdings — evaluate after daily sync (expire then evaluate) or on demand.',
        overview:
            'Define alert policies on current open portfolio holdings using level conditions (holding field + operator + column, formula, or constant). After a successful daily market-data sync with a new trading day, stale alerts expire first, then policies re-evaluate so still-true conditions can create a fresh alert instance. Manual Run now evaluates the active portfolio without redesigning that daily order. Generated alerts appear on the Dashboard; Telegram digests (if you set notification times) may repeat active alerts each slot until expired. Distinct from TOS recommendation Telegram and admin operational alerts.',
        controls: [
            {
                name: 'Rule builder',
                description:
                    'Compose holdings-only conditions (gt/lt/eq). Missing operands skip alert creation. Policies are scoped to the active portfolio.',
            },
            {
                name: 'Evaluate / Run now',
                description:
                    'Runs enabled policies for the active portfolio immediately. Daily sync uses expire-then-evaluate when the price date advances; Run now evaluates without performing trading-day expiry.',
            },
            {
                name: 'Telegram schedules',
                description:
                    'Configured under Portfolio settings. Empty schedules mean in-app only. Digests skip weekends/trade holidays and may include the same active alert in every configured slot.',
            },
        ],
        concepts: [
            {
                name: 'Policy vs alert instance',
                description:
                    'Policies are reusable rules. Alerts are instances keyed by user/profile/stock/policy. Active duplicates are suppressed; after expiry (ack, clear-all, max age, trading-day refresh, or holding closed), a later evaluation may create a new instance while the condition remains true.',
            },
            {
                name: 'F127 vs other Telegram',
                description:
                    'Portfolio alert digests are separate from Trading OS recommendation notifications, India VIX alerts, screener Telegram, and admin ops alerts — they may share the same bot/chat.',
            },
        ],
        related: ['notifications', 'holdings', 'settings', 'dashboard'],
    },
    {
        id: 'sync-logs',
        keyword: 'sync-logs',
        aliases: ['logs'],
        title: 'Sync logs',
        routeLabel: '/settings/sync-logs',
        match: (p) => pathStarts(p, '/settings/sync-logs'),
        summary: 'Admin view of price and maintenance sync log messages.',
        overview: 'Inspect structured sync activity for troubleshooting provider failures and batch jobs.',
        controls: [
            { name: 'Log list', description: 'Filter/browse recent sync messages.' },
        ],
        concepts: [
            { name: 'Operational visibility', description: 'Complements admin operational alerts when jobs fail or stall.' },
        ],
        related: ['universe-price-sync', 'admin-alerts'],
    },
    {
        id: 'admin-alerts',
        keyword: 'admin-alerts',
        aliases: ['operational-alerts'],
        title: 'Admin operational alerts',
        routeLabel: '/settings/admin-alerts',
        match: (p) => pathStarts(p, '/settings/admin-alerts'),
        summary: 'Admin inbox for operational issues (sync, maintenance, data health).',
        overview: 'Review and acknowledge system operational alerts that are separate from portfolio trading alert policies. Universe and daily-sync overdue alerts stay quiet on weekends and trade holidays when the prior session’s last run succeeded (typically Friday). They still fire if that last run failed or was partial, because weekend retry is then expected.',
        controls: [
            { name: 'Alert inbox', description: 'Acknowledge or clear operational alerts.' },
        ],
        concepts: [
            { name: 'Ops vs trading alerts', description: 'Operational alerts concern infrastructure/data health; alert policies concern holdings rules.' },
            { name: 'Weekend overdue silence', description: 'If Friday’s last universe batch and daily holdings sync succeeded, Sat/Sun overdue Telegram is suppressed. Failed Friday runs still alert because weekend retry should run.' },
        ],
        related: ['sync-logs', 'universe-price-sync'],
    },
    {
        id: 'universe-price-sync',
        keyword: 'universe-price-sync',
        aliases: ['universe-sync', 'gap-fill'],
        title: 'Universe price sync',
        routeLabel: '/settings/universe-price-sync',
        match: (p) => pathStarts(p, '/settings/universe-price-sync'),
        summary: 'Admin control of NSE universe OHLCV batch sync, gaps, and depth backfill.',
        overview:
            'Universe sync deepens and refreshes daily bars for the broader equity universe used by Explorer, Screeners, and Market Analysis. Related pages cover gap-fill failures and ignored gaps. Evening maintenance runs 19:00–23:45 on weekdays; weekends are skipped unless the prior session’s last maintenance batch ended failed/partial (then Saturday/Sunday retry to heal). Daily holdings/benchmark/index sync also skip weekends and trade holidays. Ops overdue Telegram follows the same Friday-success silence. Provider “rate limit” alerts require real throttle signals — empty provider responses alone are treated as sync failures, not rate limits.',
        controls: [
            { name: 'Sync status / controls', description: 'Monitor batch progress, windows, and enablement.' },
            { name: 'Gap failures / ignored gaps', description: 'Drill into symbols that failed fill or were intentionally skipped.' },
            { name: 'Manual run', description: 'You can still trigger a batch anytime from the UI/CLI, including weekends, even when the scheduler skips.' },
        ],
        concepts: [
            { name: 'Batch executor', description: 'Per-stock provider fetch loop with rate-limit awareness and sync logging.' },
            { name: 'History depth', description: 'Longer windows improve indicators and backtests.' },
            { name: 'Weekend policy', description: 'No new market bars on Sat/Sun. Scheduler skips unless the prior equity session’s last maintenance batch ended failed/partial. Daily holdings/benchmark/index cron skips weekends and trade holidays too. Ops overdue Telegram follows the same rule: silent when Friday succeeded, still fires when Friday’s last batch failed/partial.' },
            { name: 'Hung batch recovery', description: 'If a batch is killed mid-run, the in-progress lock auto-clears after ~30 minutes and orphan “running” sync-log rows are marked failed.' },
        ],
        related: ['explorer', 'screener', 'sync-logs'],
    },
    {
        id: 'data-quality-center',
        keyword: 'data-quality-center',
        aliases: ['data-quality', 'corporate-action-queue', 'anomaly-review'],
        title: 'Data Quality Center',
        routeLabel: '/settings/data-quality',
        match: (p) => pathStarts(p, '/settings/data-quality') && !pathStarts(p, '/settings/data-quality/history'),
        summary: 'Admin queue for unresolved market-data anomalies, starting with corporate actions.',
        overview:
            'This page is the control center for data integrity checks. Exchange-feed and heuristic detections create immutable issue records. Administrators review pending issues, then accept, reject, or apply a modified ratio. Pending corporate-action issues block affected stocks from discovery, screeners, and recommendation analytics to avoid corrupted decisions.',
        controls: [
            { name: 'Summary cards', description: 'Track pending corporate actions, accepted/rejected totals, and auto-accepted counts.' },
            { name: 'Pending review queue', description: 'Open unresolved anomalies and inspect detection evidence before taking action.' },
            { name: 'Accept / Modify Ratio & Accept / Reject', description: 'Resolve each issue while preserving the detector suggestion in audit history.' },
        ],
        concepts: [
            { name: 'Detection vs resolution', description: 'Detector output is immutable; resolution decisions are append-only audit events that can be reversed later.' },
            { name: 'Data safety gate', description: 'Stocks with pending corporate-action anomalies are excluded from data-driven engines until reviewed.' },
            { name: 'Raw OHLCV immutability', description: 'The subsystem stores adjustment factors for analytics usage and keeps raw market bars intact for audit.' },
        ],
        related: ['corporate-action-history', 'corporate-action', 'universe-price-sync', 'sync-logs'],
    },
    {
        id: 'corporate-action-history',
        keyword: 'corporate-action-history',
        aliases: ['data-quality-history', 'resolution-history'],
        title: 'Corporate Action History',
        routeLabel: '/settings/data-quality/history',
        match: (p) => pathStarts(p, '/settings/data-quality/history'),
        summary: 'Resolved data-quality corporate-action decisions with reversal-ready audit trail.',
        overview:
            'History keeps every resolved issue (manual accepted, modified accepted, auto accepted, rejected, and later reversals). Use this as the canonical timeline to verify who resolved an anomaly, which ratio was suggested vs applied, and whether auto-resolution occurred.',
        controls: [
            { name: 'Resolution table', description: 'Review stock, detection date, resolution date, status, method, source, and ratio deltas.' },
            { name: 'Back navigation', description: 'Jump to Data Quality Center to continue handling pending items.' },
        ],
        concepts: [
            { name: 'Audit permanence', description: 'Resolutions are not deleted; changes are represented as new events in sequence.' },
            { name: 'Auto-resolution traceability', description: 'Auto-accepted records stay visible and can be manually overridden later.' },
        ],
        related: ['data-quality-center', 'corporate-action'],
    },
    {
        id: 'indicator-registry',
        keyword: 'indicator-registry',
        aliases: ['indicators', 'indicator-catalogue', 'liquidity-score', 'tradability-score'],
        title: 'Indicator Registry',
        routeLabel: '/settings/indicators',
        match: (p) => pathStarts(p, '/settings/indicators'),
        summary: 'Complete Indicator catalogue — definitions, parameters (defaults/min/max), screenable vs strategy-scorable, formulas, and how to use each id in Screeners or Strategy.',
        overview:
            'The Indicator Registry is the **metadata source of truth** for every calculator StoX exposes (SD-033). '
            + 'Use `/settings/indicators` to search and filter by category, type, and status. Open any row for description, parameters, consumers, capabilities, a dependency tree, and a formula explanation. '
            + '**Formula text is documentation only** — there is no formula editor and release-shipped calculators are not mutated from the UI.\n\n'
            + 'This page is written for humans and AI agents: every registry **id**, what it means, defaults, ranges, and where you can use it.\n\n'
            + '## How to use indicators\n\n'
            + '| Consumer | Which ids | How you reference them |\n'
            + '|----------|-----------|------------------------|\n'
            + '| **Screener conditions** | `screenable` Primaries (39) | Operand `{ "indicator": "<id>", "params": {…} }` — see Screener Registry |\n'
            + '| **Strategy scoring** | `strategy_scorable` Composites (8) | `scoring_model[].key` — see Strategy Registry |\n'
            + '| **Evaluation facts** | Strategy deps + RS primaries | Computed by Evaluation; feed Strategy scores |\n'
            + '| **Stock Details / Dashboard** | Metrics + Liquidity/Tradability | Display analytics (not Strategy weights) |\n'
            + '| **Admin Registry UI** | All 62 | Browse metadata only |\n'
            + '\n'
            + '**Important:** No indicator is both screenable and strategy-scorable. Screeners use price/volume Primaries (`ema`, `rsi`, …). Strategy uses score Composites (`momentum_score`, `trend_score`, …) that **depend on** Primaries.\n\n'
            + '## Types, status, and capabilities\n\n'
            + '| Concept | Values / meaning |\n'
            + '|---------|------------------|\n'
            + '| **Type** | `primary` = OHLCV calculator; `composite` = combines dependencies into a score; `metric` = descriptive analytics field |\n'
            + '| **Status** | `active` = live; `stub` = placeholder (constant/neutral until model ships); `planned` / `deprecated` reserved |\n'
            + '| **screenable** | May appear on the left/right of a Screener condition |\n'
            + '| **strategy_scorable** | May appear as a Strategy `scoring_model` key with weight |\n'
            + '| **needs_volume** | Requires volume bars; fails/null if volume missing |\n'
            + '| **supports_maximum** | Strategy may apply a **maximum** gate (used by `risk_score`) |\n'
            + '| **Units** | `price`, `percent`, `ratio`, `count`, `currency`, `score_0_100`, `none` |\n'
            + '\n'
            + '**Uniqueness:** each indicator `id` (also used as artifact `slug`) is globally unique in the registry. Aliases (legacy Strategy keys) resolve to a canonical id.\n\n'
            + '## Parameter conventions (Screenable Primaries)\n\n'
            + 'Screener params are numeric with **default / min / max / step**. Period-like params usually allow **1–400** (RSI/ATR period max **200**; Stochastic `smooth` max **50**; MACD `signal` max **100**; Bollinger `mult` **0.5–5** step **0.1**).\n\n'
            + 'Strategy Composite params are `{ type, label, default }` (often no min/max in metadata) and are UI-persisted on the Strategy; Evaluation may still use trading_os defaults for some inputs (TD-19).\n\n'
            + '---\n\n'
            + '## Catalogue A — Screenable Primaries (for Screener JSON)\n\n'
            + 'Use these ids in Screener `left` / `right` operands. Params shown as `name=default (min–max[, step])`.\n\n'
            + '### Price\n\n'
            + '| Id | Name | Meaning | Units | Params |\n'
            + '|----|------|---------|-------|--------|\n'
            + '| `close` | Close | Last traded / session closing price | price | — |\n'
            + '| `open` | Open | Session opening price | price | — |\n'
            + '| `high` | High | Session high | price | — |\n'
            + '| `low` | Low | Session low | price | — |\n'
            + '| `change_pct` | % Change | Percent change vs close `period` bars ago | percent | `period=1 (1–400)` |\n'
            + '| `high_n` | Highest high (N) | Highest high over N bars | price | `period=20 (1–400)` |\n'
            + '| `low_n` | Lowest low (N) | Lowest low over N bars | price | `period=20 (1–400)` |\n'
            + '| `high_52w` | 52-week high | Highest high over ~252 trading days | price | — |\n'
            + '| `low_52w` | 52-week low | Lowest low over ~252 trading days | price | — |\n'
            + '| `range_pct` | Range % (H-L)/C | Intraday range as % of close: `(high−low)/close×100` | percent | — |\n'
            + '\n'
            + '### Trend\n\n'
            + '| Id | Name | Meaning | Units | Params |\n'
            + '|----|------|---------|-------|--------|\n'
            + '| `sma` | SMA | **Simple Moving Average** — arithmetic mean of close over `period` bars. Smooths price; slower than EMA. | price | `period=20 (1–400)` |\n'
            + '| `ema` | EMA | **Exponential Moving Average** — weighted average of close that reacts faster to recent prices than SMA. Common trend line (e.g. 50-day). | price | `period=50 (1–400)` |\n'
            + '| `price_vs_sma_pct` | Price vs SMA % | `(close − SMA) / SMA × 100` — how far price is above/below its SMA | percent | `period=20 (1–400)` |\n'
            + '| `price_vs_ema_pct` | Price vs EMA % | Same idea vs EMA | percent | `period=50 (1–400)` |\n'
            + '| `sma_spread_pct` | SMA spread % | Percent spread between fast and slow SMAs | percent | `fast=20`, `slow=50` (both 1–400) |\n'
            + '| `ema_spread_pct` | EMA spread % | Percent spread between fast and slow EMAs | percent | `fast=12`, `slow=26` (both 1–400) |\n'
            + '\n'
            + '**Example Screener condition (close above 50-EMA):**\n\n'
            + '```json\n'
            + '{\n'
            + '  "type": "condition",\n'
            + '  "left": { "indicator": "close", "params": {} },\n'
            + '  "operator": "gt",\n'
            + '  "weight_factor": 1,\n'
            + '  "right": { "indicator": "ema", "params": { "period": 50 } }\n'
            + '}\n'
            + '```\n\n'
            + '### Momentum\n\n'
            + '| Id | Name | Meaning | Units | Params |\n'
            + '|----|------|---------|-------|--------|\n'
            + '| `rsi` | RSI | **Relative Strength Index** (Wilder) — momentum oscillator typically 0–100. Often >70 overbought, <30 oversold (heuristic). | percent | `period=14 (1–200)` |\n'
            + '| `roc` | ROC % | **Rate of Change** — percent change of close over `period` | percent | `period=12 (1–400)` |\n'
            + '| `stoch_k` | Stochastic %K | Close location in the high–low range over `period` (0–100 style) | percent | `period=14 (1–400)` |\n'
            + '| `stoch_d` | Stochastic %D | Smoothed %K | percent | `period=14 (1–400)`, `smooth=3 (1–50)` |\n'
            + '| `macd` | MACD line | **Moving Average Convergence Divergence** — EMA(fast) − EMA(slow) | price | `fast=12`, `slow=26` (1–400) |\n'
            + '| `macd_signal` | MACD signal | EMA of the MACD line | price | + `signal=9 (1–100)` |\n'
            + '| `macd_hist` | MACD histogram | MACD − signal (momentum of the MACD) | price | same as signal |\n'
            + '\n'
            + '### Volatility\n\n'
            + '| Id | Name | Meaning | Units | Params |\n'
            + '|----|------|---------|-------|--------|\n'
            + '| `atr` | ATR | **Average True Range** — average of true range; volatility in price units | price | `period=14 (1–200)` |\n'
            + '| `bb_mid` | Bollinger mid | Middle Bollinger Band = SMA(`period`) | price | `period=20 (1–400)` |\n'
            + '| `bb_upper` | Bollinger upper | Mid + `mult` × stdev | price | `period=20`, `mult=2 (0.5–5, step 0.1)` |\n'
            + '| `bb_lower` | Bollinger lower | Mid − `mult` × stdev | price | same |\n'
            + '| `bb_pct_b` | Bollinger %B | Where close sits in the band (0 at lower, 1 at upper) | ratio | same |\n'
            + '| `bb_width_pct` | Bollinger width % | Band width as % of mid | percent | same |\n'
            + '\n'
            + '### Volume (needs volume)\n\n'
            + '| Id | Name | Meaning | Units | Params |\n'
            + '|----|------|---------|-------|--------|\n'
            + '| `volume` | Volume | Session share volume | count | — |\n'
            + '| `volume_sma` | Volume SMA | SMA of volume | count | `period=20 (1–400)` |\n'
            + '| `volume_ratio` | Volume / Vol SMA | `volume / volume_sma` — >1 = above average activity | ratio | `period=20 (1–400)` |\n'
            + '| `average_volume` | Average Daily Volume | Mean share volume over N (same math as Volume SMA) | count | `period=20 (1–400)` |\n'
            + '\n'
            + '### Liquidity (needs volume)\n\n'
            + '| Id | Name | Meaning | Units | Params |\n'
            + '|----|------|---------|-------|--------|\n'
            + '| `average_turnover` | Average Daily Turnover | SMA of `close × volume` (typical daily traded value) | currency | `period=20 (1–400)` |\n'
            + '| `relative_turnover` | Relative Turnover | Short ADT / longer baseline ADT; ~1.0 = in-line with own baseline | ratio | `period=20`, `baseline=60` (1–400) |\n'
            + '\n'
            + '### Tradability / Risk heuristics\n\n'
            + '| Id | Name | Meaning | Units | Params |\n'
            + '|----|------|---------|-------|--------|\n'
            + '| `gap_frequency` | Gap Frequency | Rate of opening gaps vs prior close | ratio | `period=60`, `threshold_pct=1 (0.1–20, step 0.1)` |\n'
            + '| `gap_fill_ratio` | Gap Fill Ratio | Fraction of gaps that fill within `fill_window` | ratio | + `fill_window=5 (1–40)` |\n'
            + '| `circuit_frequency` | Circuit Frequency | Heuristic rate of circuit-like sessions (**not** exchange circuit feed) | ratio | `period=60`, `move_pct=9.5 (1–25)`, `range_pct=0.5 (0.05–5)` |\n'
            + '| `circuit_risk` | Circuit Risk | 0–100 severity from frequency + move size | score_0_100 | same move/range params |\n'
            + '\n'
            + '---\n\n'
            + '## Catalogue B — Relative Strength Primaries (Evaluation inputs)\n\n'
            + 'Not screenable. Used as Evaluation / analytics inputs (especially `relative_strength_3m` → Strategy `relative_strength`).\n\n'
            + '| Id | Name | Meaning | Units |\n'
            + '|----|------|---------|-------|\n'
            + '| `relative_strength_1m` | Relative Strength (1m) | Stock vs benchmark return ratio ~1 month | ratio |\n'
            + '| `relative_strength_3m` | Relative Strength (3m) | ~3 months — default Evaluation input for RS score | ratio |\n'
            + '| `relative_strength_6m` | Relative Strength (6m) | ~6 months | ratio |\n'
            + '\n'
            + '---\n\n'
            + '## Catalogue C — Strategy-scorable Composites (for Strategy JSON)\n\n'
            + 'Use these **keys** in `definition.scoring_model`. Values are **0–100** scores. Enabled weights must sum to **100**. '
            + 'Defaults below are Strategy UI defaults (weight / minimum / maximum). Split into two tables on the shared **Key** for print-friendly width.\n\n'
            + '### C1 — Identity and Strategy defaults\n\n'
            + '| Key | Aliases | Name | Meaning | Wt | Min | Max |\n'
            + '|-----|---------|------|---------|----|-----|-----|\n'
            + '| `relative_strength` | — | Relative Strength | Strength vs benchmark (long-leaning) | 35 | 80 | — |\n'
            + '| `momentum_score` | `momentum` | Momentum Score | RSI-based momentum strength | 15 | 70 | — |\n'
            + '| `trend_score` | `trend` | Trend Score | Price vs SMA stack | 20 | 70 | — |\n'
            + '| `breakout_score` | `pattern_bonus` | Breakout Score | Pattern/breakout evidence from Discovery | 10 | 75 | — |\n'
            + '| `volume_score` | `volume` | Volume Score | Volume vs recent average | 8 | 60 | — |\n'
            + '| `market_regime` | — | Market Regime | Broad market regime (**stub**) | 5 | 60 | — |\n'
            + '| `sector_strength` | — | Sector Strength | Sector RS (**stub**) | 4 | 60 | — |\n'
            + '| `risk_score` | `risk` | Risk Score | ATR-based risk; **higher = riskier** | 3 | 0 | **40** |\n'
            + '\n'
            + '### C2 — Params, dependencies, and formula (same Key)\n\n'
            + '| Key | Params | Depends on | Formula (summary) |\n'
            + '|-----|--------|------------|-------------------|\n'
            + '| `relative_strength` | `lookback_days=90`; `benchmark=NIFTY50` | `relative_strength_3m` | RS3m ≥1.05→100; ≥1.0→70; else 30 |\n'
            + '| `momentum_score` | `rsi_period=14` | `rsi` | RSI in [45,70]→100; >70→55; <30→35; else 50 |\n'
            + '| `trend_score` | `sma_fast=20`; `sma_slow=50` | `close`, `sma` | close>fast>slow→100; close>fast→60; else 20 |\n'
            + '| `breakout_score` | — | `discovery_pattern_count` | min(100, 40+20×count); 0 if none |\n'
            + '| `volume_score` | `volume_sma_period=20` | `volume_ratio` | ≥1.2→100; ≥0.8→60; else 30 |\n'
            + '| `market_regime` | — | — | Constant **50** until model ships |\n'
            + '| `sector_strength` | — | — | Constant **50** until model ships |\n'
            + '| `risk_score` | `atr_period=14` | `atr`, `close` | clamp((atr/close×100)×10, 0, 100); supports **maximum** gate |\n'
            + '\n'
            + '**Example Strategy scoring rows (weights = 100):**\n\n'
            + '```json\n'
            + '"scoring_model": [\n'
            + '  {\n'
            + '    "key": "relative_strength",\n'
            + '    "enabled": true,\n'
            + '    "weight": 50,\n'
            + '    "minimum": 70,\n'
            + '    "maximum": null,\n'
            + '    "parameters": {}\n'
            + '  },\n'
            + '  {\n'
            + '    "key": "momentum_score",\n'
            + '    "enabled": true,\n'
            + '    "weight": 50,\n'
            + '    "minimum": 60,\n'
            + '    "maximum": null,\n'
            + '    "parameters": {\n'
            + '      "rsi_period": 14\n'
            + '    }\n'
            + '  }\n'
            + ']\n'
            + '```\n\n'
            + '---\n\n'
            + '## Catalogue D — Liquidity / Tradability Composites\n\n'
            + 'Active for Discovery / Dashboard / Stock Details / Screener consumers. **Not** strategy-scorable and **not** wired into Recommendation scoring.\n\n'
            + '### D1 — Identity\n\n'
            + '| Id | Name | Meaning | Units |\n'
            + '|----|------|---------|-------|\n'
            + '| `liquidity_score` | Liquidity Score | 0–100 liquidity quality | score_0_100 |\n'
            + '| `tradability_score` | Tradability Score | 0–100 ease of trading (higher = easier) | score_0_100 |\n'
            + '\n'
            + '### D2 — Dependencies and formula (same Id)\n\n'
            + '| Id | Depends on | Formula (summary) |\n'
            + '|----|------------|-------------------|\n'
            + '| `liquidity_score` | `relative_turnover`, `average_turnover`, `average_volume` | Map RT/turnover/volume → 0–100; mean of available |\n'
            + '| `tradability_score` | gap + circuit primaries | Invert freqs / use fill; mean of available |\n'
            + '\n'
            + '---\n\n'
            + '## Catalogue E — Discovery + Stock Analytics Metrics\n\n'
            + '| Id | Name | Meaning | Units |\n'
            + '|----|------|---------|-------|\n'
            + '| `discovery_pattern_count` | Discovery Pattern Count | Count of matched patterns on Discovery evidence (not a TI series) | count |\n'
            + '| `distance_52w_high_pct` | Distance from 52-week High % | How far latest close is below/above 52w high | percent |\n'
            + '| `distance_52w_low_pct` | Distance from 52-week Low % | Distance from 52w low | percent |\n'
            + '| `historical_volatility_pct` | Historical Volatility % | Annualised log-return volatility proxy | percent |\n'
            + '| `beta` | Beta (proxy) | Soft vol proxy — **not** formal regression beta | ratio |\n'
            + '| `trend_strength` | Trend Strength | Heuristic 0–100 from close vs SMA50/200 — **≠** Strategy `trend_score` | score_0_100 |\n'
            + '| `maximum_drawdown_pct` | Maximum Drawdown % | Peak-to-trough over loaded history | percent |\n'
            + '| `current_drawdown_pct` | Current Drawdown % | Peak to latest close | percent |\n'
            + '| `average_daily_volume_metric` | Average Daily Volume (analytics) | Descriptive ADV — distinct from Primary `average_volume` | count |\n'
            + '| `liquidity_rating` | Liquidity Rating | High / Medium / Low / Unknown from notional ADV | none |\n'
            + '\n'
            + '---\n\n'
            + '## Quick definitions glossary (common acronyms)\n\n'
            + '| Term | Plain meaning |\n'
            + '|------|---------------|\n'
            + '| **SMA** | Simple Moving Average — equal-weight average of recent closes |\n'
            + '| **EMA** | Exponential Moving Average — recent closes weigh more; reacts faster than SMA |\n'
            + '| **RSI** | Relative Strength Index — 0–100 momentum oscillator from average gains vs losses |\n'
            + '| **MACD** | Moving Average Convergence Divergence — difference of two EMAs (+ signal + histogram) |\n'
            + '| **ATR** | Average True Range — typical bar range including gaps; volatility in price units |\n'
            + '| **Bollinger Bands** | SMA ± (multiplier × standard deviation); width expands/contracts with volatility |\n'
            + '| **ROC** | Rate of Change — percent price change over N bars |\n'
            + '| **Stochastic** | Where close sits in the recent high–low range (%K / smoothed %D) |\n'
            + '| **Relative Strength (RS)** | Here: stock performance vs a benchmark (not the RSI oscillator) |\n'
            + '\n'
            + '## Artifact envelope (optional packaging)\n\n'
            + 'Indicators can be exported as Trading Artifact envelopes (`artifact_type: "indicator"`) for packages. '
            + 'Release calculators stay immutable; create/update only drafts. Envelope needs `schema_version`, `artifact_type`, `slug` (= registry id), `name`, `metadata`, and `definition` with `registry_id` / `indicator_kind` (`primary` / `composite` / `metric`). '
            + 'No executable `code` / `script` / `formula` fields allowed.\n\n'
            + '## Recommended reading order for builders\n\n'
            + '1. Pick consumer: Screener condition vs Strategy weight.\n'
            + '2. Copy the correct id/key from the tables above (never invent ids).\n'
            + '3. Set params within min/max (or omit to use defaults).\n'
            + '4. For Strategy, ensure enabled weights sum to 100; for Screeners, build a valid `definition.root` tree.\n'
            + '5. Open Registry detail for dependency tree + full formula prose when unsure.\n\n'
            + INDICATOR_REGISTRY_GUIDE_EXTRAS,
        controls: [
            { name: 'Search', description: 'Match against indicator id, display name, or description.' },
            { name: 'Category / Type / Status filters', description: 'Narrow the catalogue (e.g. momentum primaries, stub composites, liquidity).' },
            { name: 'Indicator row', description: 'Open the detail page for full metadata, parameters, consumers, and capabilities.' },
            { name: 'Dependency tree', description: 'On detail, expand declared depends_on relationships recursively (e.g. momentum_score → rsi).' },
            { name: 'Formula explanation', description: 'Read-only prose describing how the indicator is computed; not editable in the UI.' },
            { name: 'Catalogue guide', description: 'This documentation topic — full id list, meanings, defaults, ranges, and Screener vs Strategy usage.' },
        ],
        concepts: [
            {
                name: 'Primary / Composite / Metric',
                description:
                    'Primaries are OHLCV calculators (often screenable). Composites combine dependencies into scores (Strategy or Liquidity/Tradability). Metrics are descriptive Stock Analytics / Discovery fields.',
            },
            {
                name: 'Screenable vs strategy-scorable',
                description:
                    'Screenable Primaries appear in Screener conditions. Strategy-scorable Composites appear in Strategy weights. No id is both. Liquidity/Tradability composites are intentionally not strategy-scorable.',
            },
            {
                name: 'EMA / SMA / RSI (plain language)',
                description:
                    'SMA = equal-weight average of closes. EMA = exponential average (faster). RSI = 0–100 momentum from average gains vs losses. Relative Strength (Strategy) is stock vs benchmark — not the RSI oscillator.',
            },
            {
                name: 'Id uniqueness and aliases',
                description:
                    'Registry id is unique. Strategy aliases: momentum→momentum_score, trend→trend_score, pattern_bonus→breakout_score, volume→volume_score, risk→risk_score. Prefer canonical keys in new JSON.',
            },
            {
                name: 'Parameter ranges',
                description:
                    'Screener Primary params declare default/min/max/step (periods usually 1–400). Strategy Composite params declare type/label/default. Stay inside ranges — Validate/Import rejects out-of-range values (no silent clamp). Omitted Screener params use TechnicalIndicatorService fallbacks, which may differ from catalogue UI defaults.',
            },
            {
                name: 'Parameter naming convention',
                description:
                    'Use catalogue param ids exactly: period/fast/slow/signal/mult for Primaries; lookback_days/rsi_period/sma_fast/benchmark etc. for Strategy Composites. Do not invent synonyms.',
            },
            {
                name: 'Circuit heuristics',
                description:
                    'Circuit Frequency / Risk use OHLCV heuristics (large move + locked range), not official exchange circuit feeds.',
            },
            {
                name: 'Immutable calculators',
                description:
                    'Shipping calculators are release-owned. Registry UI is read-only documentation; artifact drafts do not rewrite TechnicalIndicatorService math.',
            },
        ],
        related: ['settings', 'screener', 'screener-registry', 'strategy', 'strategy-registry', 'authoring-trading-artifacts', 'trading-artifact-runtime', 'trading-cookbook', 'data-quality-center'],
    },
    AI_AUTHORING_CONTRACT_TOPIC,
    AUTHORING_TRADING_ARTIFACTS_TOPIC,
    RUNTIME_SEMANTICS_TOPIC,
    TRADING_COOKBOOK_TOPIC,
    {
        id: 'users',
        keyword: 'users',
        aliases: ['user-management', 'invites'],
        title: 'User management',
        routeLabel: '/settings/users',
        match: (p) => pathStarts(p, '/settings/users'),
        summary: 'Admin invite links, password-reset links, and account administration.',
        overview:
            'Registration is invite-only. Admins create invite links and password-reset links for existing accounts without requiring the current password. Invitation URLs are shown only when created or regenerated — copy them immediately. Regenerating invalidates the previous URL and does not extend the original 72-hour expiry. Pending invitees must use the administrator-provided link (login will not reveal the invitation URL).',
        controls: [
            { name: 'Create invite', description: 'Generate a link for a new user. Copy Invitation URL from the banner right away — the list does not re-show a stored URL.' },
            { name: 'Regenerate Invitation URL', description: 'Issues a new URL for a pending invite after confirmation. The old URL stops working; original expiry is unchanged.' },
            { name: 'Password reset link', description: 'Issue a reset URL for an existing account.' },
        ],
        concepts: [
            { name: 'Invite-only', description: 'There is no public self-registration endpoint for guests.' },
            { name: 'Hashed invitation tokens', description: 'Only a hash of the invitation secret is stored. The raw URL is a bearer credential shown once at create/regenerate.' },
            { name: 'Admin role', description: 'Gates global settings and ops tools.' },
        ],
        related: ['settings', 'profile'],
    },
];

const DEFAULT_RICH_CONTENT = {
    controls: [
        {
            name: 'Typical flow',
            description: 'Open this page, verify active portfolio context in the header, perform one meaningful action, then confirm the reflected change in list/cards/history before leaving.',
        },
        {
            name: 'Validation and errors',
            description: 'Form and API validations are shown as inline errors or toast messages. Fix the first reported issue, retry, and re-check dependent sections that consume the same data.',
        },
    ],
    concepts: [
        {
            name: 'Active portfolio context',
            description: 'Most data on this page is scoped by the selected portfolio profile; switching profile can completely change visible rows and metrics.',
        },
        {
            name: 'Data freshness',
            description: 'Many analytics depend on cached daily OHLCV and scheduled sync jobs. If numbers look stale, refresh this page and verify sync status in admin tools.',
        },
    ],
    overview:
        'Practical tip: treat this page as one step in a larger workflow, not an isolated screen. Make one change at a time, verify the downstream effect, and use linked pages to complete the loop (for example: discovery → evaluation → recommendation → pending execution → review).',
};

const DOC_ENRICHMENTS = {
    overview: {
        overview:
            'Documentation is public static HTML (no login or JavaScript required). How to use this effectively: start from a page you are on via the header (?), or open /docs/index.html. Prefer topic URLs like /docs/strategy.html for sharing with humans or AI agents. Legacy /documentation?q=… redirects to these files. Links to product screens still require sign-in.',
    },
    dashboard: {
        overview:
            'Recommended reading order on Dashboard: (1) summary cards for total value and P/L, (2) allocation for concentration risk, (3) market gauges for regime, (4) alerts/patterns for actionable follow-up, and (5) upcoming calendar events. If your view seems old, run Refresh Dashboard to invalidate cache and fetch live API responses.',
        controls: [
            { name: 'Portfolio switch impact', description: 'Switching portfolio in the header reloads dashboard context; always confirm you are reviewing the intended account before taking actions.' },
            { name: 'Follow-up navigation', description: 'Use quick links from alerts, pattern names, and top movers to jump directly into Holdings, Patterns, or Explorer for deeper analysis.' },
        ],
        concepts: [
            { name: 'Decision context', description: 'Dashboard is an orientation page. It helps you decide where to investigate next; it is not intended to execute trades directly.' },
        ],
    },
    transactions: {
        overview:
            'This is the accounting backbone of the app. Enter transactions carefully because holdings, realized P/L, XIRR, and multiple reports derive from this ledger. Prefer editing incorrect rows instead of adding compensating noise rows unless your accounting policy requires audit-style reversals.',
        controls: [
            { name: 'CSV import discipline', description: 'After pasting CSV, review each parsed row (type, quantity, price, date, exchange, fees). Save commits the whole batch or nothing — correcting mistakes before save avoids cleanup. A failed import leaves zero new ledger rows; retry after fixing. A successful batch cannot be re-submitted.' },
            { name: 'Sell prefill from holdings', description: 'When launched from Holdings, symbol/type/quantity are prefilled to reduce input errors; still validate price, date, and fee assumptions before saving.' },
        ],
        concepts: [
            { name: 'Mutability caution', description: 'Editing historical rows can ripple through cost basis and performance metrics. Re-check Holdings and Review pages after major edits.' },
        ],
    },
    'pending-execution': {
        overview:
            'Use this page as an execution queue, not an analysis board. Only approved actionable recommendations appear here. Complete each row after broker fill so your ledger and performance timeline stay aligned with real-world trades.',
        controls: [
            { name: 'Execute after actual fill', description: 'Record the real fill price/quantity from your broker confirmation; avoid placeholder values to keep holdings and P/L accurate.' },
        ],
        concepts: [
            { name: 'Queue hygiene', description: 'If a recommendation is no longer valid, cancel execution promptly so reserved cash is released and stale intent does not pollute future decisions.' },
        ],
    },
    cash: {
        overview:
            'Cash is first-class state in the recommendation engine. Keep this page accurate because available cash directly controls how many recommendations can be funded. The system can demote unfunded ideas to WATCH when cash is insufficient.',
        controls: [
            { name: 'Transaction date usage', description: 'Use the intended accounting date for deposits/withdrawals so statement chronology and downstream analytics remain meaningful.' },
            { name: 'Reservation drilldown', description: 'Expand reserved cash to understand which pending executions are consuming capital before adjusting balances.' },
        ],
        concepts: [
            { name: 'Balance vs available', description: 'Balance is total cash ledger value; available subtracts open reservations and is the true deployable amount.' },
        ],
    },
    'corporate-action': {
        overview:
            'Corporate actions can materially reshape historical data. Always run preview first, inspect the proposed adjustments, and apply only when ratios/dates are confirmed from official exchange/broker notices.',
        controls: [
            { name: 'Preview-first workflow', description: 'Use preview to verify quantities, price restatements, warnings, and impacted transaction scope before applying irreversible changes.' },
        ],
        concepts: [
            { name: 'Historical continuity', description: 'OHLCV restatement ensures charts and indicators remain comparable before vs after split/bonus events.' },
        ],
    },
    holdings: {
        overview:
            'Holdings is your live position cockpit. Start with exposure and unrealized risk, then drill into per-stock history when something looks unusual. Complex view is better for diagnostics; simple view is better for fast monitoring.',
        controls: [
            { name: 'Column presets and sorting', description: 'Use sorting with complex metrics (unrealized %, latest change %, drawdown from highest close) to quickly surface risk concentration and weak performers.' },
            { name: 'Action shortcuts', description: 'Sell, Analyse, and Price history actions are designed for one-click workflow transitions into execution, research, or historical validation.' },
        ],
        concepts: [
            { name: 'Risk-first reading', description: 'Prioritize trailing-stop pressure, latest close behavior, and concentration before focusing on winners.' },
        ],
    },
    'stock-prices': {
        overview:
            'Use this page to validate data quality and behavior over time. It is especially useful before editing rules or acting on detected patterns because you can inspect the exact OHLCV bars behind those outputs.',
        controls: [
            { name: 'Range and sampling', description: 'Adjust time range and sampling to separate long-trend context from short-term noise.' },
            { name: 'Force sync intentionally', description: 'Run force sync when bars are missing/stale or when verifying a newly added symbol; avoid repetitive sync loops.' },
        ],
        concepts: [
            { name: 'Indicator dependency', description: 'Screeners, pattern scans, and evaluation factors depend on this stored history; bar integrity matters system-wide.' },
        ],
    },
    watchlist: {
        overview:
            'Watchlist is for idea incubation before position entry. Keep lists focused and annotated, then use scan + explorer links to move from curiosity to evidence-backed decisions.',
        controls: [
            { name: 'List quality', description: 'Create themed lists (e.g. breakout, value, earnings week) rather than one giant list to keep signal relevance high.' },
            { name: 'Pattern scan cadence', description: 'Re-scan after meaningful price updates or when symbols are newly added to maintain current icon signals.' },
        ],
        concepts: [
            { name: 'Research-to-action bridge', description: 'Watchlist connects to Explorer, Patterns, and eventually Recommendations via Discovery workflows.' },
        ],
    },
    explorer: {
        overview:
            'Explorer helps answer "what is strong relative to what." Use it to compare symbols against benchmarks over consistent windows, then validate candidates in Watchlist/Discovery.',
        controls: [
            { name: 'Consistent window comparisons', description: 'Compare symbols on the same lookback period (1M/3M/6M/1Y) to avoid misleading relative conclusions.' },
        ],
        concepts: [
            { name: 'Relative over absolute', description: 'A stock can rise in absolute terms yet still underperform peers; relative context is often the more useful decision input.' },
        ],
    },
    screener: {
        overview:
            'Screeners define eligibility, so they should be stable and auditable. Keep rule intent clear, avoid overfitting on too many conditions, and verify outputs across different market periods.',
        controls: [
            { name: 'Rule naming and intent', description: 'Use descriptive names and notes so future you can understand why each screener exists without re-reading every condition.' },
            { name: 'Schedule with caution', description: 'Automated schedules are powerful; start with manual runs until logic is trusted.' },
        ],
        concepts: [
            { name: 'Eligibility contract', description: 'If a stock never passes eligibility, strategy scoring never gets a chance to rank it.' },
        ],
    },
    'screener-editor': {
        overview:
            'Editor is where signal quality is won or lost. Build conditions incrementally, test each change with runs/backtests, and document the intended market behavior the rule is trying to capture.',
        controls: [
            { name: 'Incremental tuning', description: 'Change one condition at a time, run again, and compare hit drift in run history instead of editing many conditions at once.' },
            { name: 'Backtest interpretation', description: 'Backtests are scenario evidence, not guarantees; evaluate consistency, not just best-case returns.' },
        ],
        concepts: [
            { name: 'Robustness over optimization', description: 'Prefer rules that behave reasonably across varied periods over narrowly optimized historical winners.' },
        ],
    },
    discovery: {
        overview:
            'Discovery is the candidate funnel plus long-focused evaluation facts on one page. Think of it as "who deserves deeper attention now" with measured score/confidence — not a sell-flipped score for bearish screeners. Candidates still need Strategy scoring later in the pipeline for action labels.\n\n'
            + 'Bearish screeners can contribute hits to Discovery, but Evaluation does not know which screener was “for selling.” Wire sell intent via Strategy Screener Exit on holdings; keep Eligibility Sources for entry-oriented screeners.',
        controls: [
            { name: 'Run provenance', description: 'Check which screener/pipeline run produced candidates before comparing two lists from different run contexts.' },
            { name: 'Score / confidence / explanation', description: 'Come from the latest evaluation result for each candidate (long-focused factor facts). Empty until you run evaluation (or Run discovery, which evaluates afterward).' },
        ],
        concepts: [
            {
                name: 'No separate Evaluations page',
                description: 'Former /evaluations redirects to Discovery. Factor details open from the Factors link on each row.',
            },
        ],
    },
    recommendations: {
        overview:
            'Recommendations are decision proposals synthesized from eligibility, scoring, market context, and cash constraints. Read evidence first, action label second; the reasoning is often more important than the headline action.',
        controls: [
            { name: 'Evidence-first review', description: 'Open each review dialog and inspect eligibility/scoring/market/capital evidence before approving or rejecting.' },
            { name: 'Decision hygiene', description: 'Use Defer when more evidence is needed instead of forcing binary approve/reject too early.' },
        ],
        concepts: [
            { name: 'Lifecycle states', description: 'Recommendations can move through pending_review, pending_execution, executed/cancelled, expired, and reopened paths.' },
        ],
    },
    strategy: {
        overview:
            'Each portfolio has one editable strategy (default Minervini). Change tabs, Save, then run the decision pipeline from Recommendations to refresh ideas.',
        controls: [
            {
                name: 'Weights sum guardrail',
                description:
                    'Enabled scoring weights are auto-normalised to 100 on Save (proportions kept). Use Normalise now on the Scoring Model tab to preview the scaled values before saving.',
            },
            {
                name: 'Where to look after Save',
                description:
                    'Final refined ideas appear on Recommendations (/recommendations), not on the Strategy page itself. Approve → Pending Execution → broker fill on Transactions. HOLD/WATCH stay on Recommendations as insights.',
            },
        ],
        concepts: [
            {
                name: 'Policy layering',
                description:
                    'Eligibility admits → Scoring ranks → Thresholds label → Exit can force sells on holdings → Portfolio rules constrain → Market gates may block new buys → Capital allocation + cash fund what survives → Recommendations page.',
            },
            {
                name: 'No versions or duplicates',
                description:
                    'Strategy versioning, Duplicate, and factory fork were removed. Save updates the single strategy in place.',
            },
        ],
    },
    review: {
        overview:
            'Review closes the learning loop. Use it to evaluate whether recommendation quality is improving over time and to identify recurring misses that require screener/strategy adjustments.',
        controls: [
            { name: 'Outcome segmentation', description: 'Compare actionable trade outcomes separately from HOLD/WATCH insights to avoid mixing fundamentally different intent types.' },
        ],
        concepts: [
            { name: 'Feedback loop', description: 'Insights from Review should feed back into strategy tuning and risk controls, not remain as passive reporting.' },
        ],
    },
    notifications: {
        overview:
            'Notification history is your message audit trail. Use it to confirm whether expected operational and trading notifications were emitted and to troubleshoot missing deliveries. Missing HOLD/WATCH messages are expected — Telegram recommendation alerts only cover actionable Open / Increase / Reduce / Exit ideas.',
    },
    patterns: {
        overview:
            'Patterns blends education with practical scanner linkage. Use it to understand what each detected ID means before acting on a signal from Dashboard, Watchlist, or OHLCV pages.',
        controls: [
            { name: 'Deep-link navigation', description: 'If you arrive with a hash (e.g. /patterns#hammer), use that card as the canonical explanation for matched scanner output.' },
        ],
    },
    knowledge: {
        overview:
            'Knowledge Board is your durable research memory. Capture hypotheses, thesis updates, post-mortems, and checklist templates so decisions remain repeatable instead of purely intuitive.',
        controls: [
            { name: 'Read vs Manage modes', description: 'Use Read for uninterrupted review and Manage for curation operations (select, archive, export, reorder).' },
            { name: 'Structured note hygiene', description: 'Use tags consistently and separate raw observations from actionable conclusions inside each note.' },
        ],
    },
    settings: {
        overview:
            'Settings controls app behavior and data interpretation. Make changes intentionally, especially to fees, telegram, and sync configuration, because they impact multiple workflows downstream.',
    },
    'universe-price-sync': {
        overview:
            'Universe sync health determines quality for Explorer, many Screeners, and market analytics. Treat this page as operational maintenance: monitor drift, investigate gaps, and keep history depth sufficient. Weekday evenings are the normal window; weekends only retry when the prior session’s last batch failed or was partial.',
    },
    users: {
        overview:
            'User management is admin-only and security-sensitive. Keep invite/reset links scoped, time-bound where possible, and aligned with account ownership controls.',
    },
};

function mergeUniqueItems(baseItems = [], extraItems = []) {
    const seen = new Set();
    const merged = [];
    [...baseItems, ...extraItems].forEach((item) => {
        const key = `${item.name}::${item.description}`;
        if (seen.has(key)) return;
        seen.add(key);
        merged.push(item);
    });
    return merged;
}

function enrichDocEntry(doc) {
    const enrich = DOC_ENRICHMENTS[doc.keyword] || {};
    return {
        ...doc,
        overview: [doc.overview, enrich.overview, DEFAULT_RICH_CONTENT.overview].filter(Boolean).join('\n\n'),
        controls: mergeUniqueItems(doc.controls, [
            ...(enrich.controls || []),
            ...DEFAULT_RICH_CONTENT.controls,
        ]),
        concepts: mergeUniqueItems(doc.concepts, [
            ...(enrich.concepts || []),
            ...DEFAULT_RICH_CONTENT.concepts,
        ]),
    };
}

export const APP_DOCUMENTATION = APP_DOCUMENTATION_BASE.map(enrichDocEntry);

/** Prefer longer / more specific route matches first. */
export const APP_DOCUMENTATION_BY_SPECIFICITY = [...APP_DOCUMENTATION].sort((a, b) => {
    const score = (doc) => {
        const label = doc.routeLabel || '';
        if (label.includes(':')) return 80 + label.length;
        return label.length;
    };
    return score(b) - score(a);
});
