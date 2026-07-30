/**
 * In-app contextual documentation index.
 * Each entry is reachable via static HTML at /docs/{keyword}.html (and legacy /documentation?q= redirects there).
 */

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
                    'Primary navigation is sidebar-only and fully configuration-driven (`config/navigation.js` + `navigation/` registry). Sections: Favourites, Quick Actions, then groups — Portfolio, Market, Trading, Knowledge, Administration. Reusable primitives: NavMenuItem, NavGroup, NavBadge, NavTooltip. Icons come from a single registry (no duplicated Lucide imports in rows). Routes use ROUTES constants. Editors stay internal. Future plugins register via navigationRegistry.registerModule. Ctrl/Cmd+B toggles the sidebar. See Documentation topic and specs/architecture/15-Sidebar-Navigation-Architecture.md.',

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
        related: ['trading-os-flow', 'dashboard', 'settings'],
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
            + 'Configure Screeners + Strategy first, then run the decision pipeline from Recommendations (or the scheduled daily pipeline). Full write-up: specs/architecture/07-Trading-OS-Pages-and-Flow.md.',
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
        summary: 'Portfolio snapshot: value, allocation, market analytics, alerts, calendar, and pattern signals.',
        overview:
            'The Dashboard is the home screen for the active portfolio. It summarises performance, allocation, market context, alerts, upcoming calendar events, and pattern signals on holdings. A client-side cache can show the last load instantly until you refresh.',
        controls: [
            { name: 'Refresh dashboard', description: 'Clears the local cache and reloads dashboard + pattern scan data.' },
            { name: 'Sync prices for today', description: 'Admin: pulls latest holdings prices for the current session day.' },
            { name: 'Allocation charts', description: 'Donut / table views of market % and invested % by holding.' },
            { name: 'Market Analytics gauges', description: 'Market Health summary card stays visible; gauge diagnostics are collapsible and explain the score. Diagnostics still include trend, momentum, volatility, risk, sentiment, phase, breadth, and regime. The Market breadth gauge title links to Market Depth.' },
            { name: 'Stocks Above heatmap', description: 'Market-depth view of how many stocks sit above key moving averages.' },
            { name: 'Pattern signals', description: 'Matched pattern names link into the Patterns guide.' },
            { name: 'Calendar card', description: 'Upcoming portfolio events for the next ~31 days.' },
        ],
        concepts: [
            { name: 'Portfolio XIRR', description: 'Money-weighted return for the active portfolio based on cash flows and current value.' },
            { name: 'Market phase / sentiment', description: 'Deterministic market regime from the primary benchmark (e.g. Nifty 50); used later by Strategy market gates.' },
            { name: 'Dashboard cache', description: 'Responses are cached per user + portfolio (~24h) for snappy revisits; mutations invalidate it.' },
        ],
        related: ['trading-os-flow', 'holdings', 'market-depth', 'patterns', 'calendar', 'review'],
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
            { name: 'Bulk CSV import', description: 'Upload a file, review the table, then save accepted rows.' },
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
        aliases: ['balance', 'deposit', 'withdraw'],
        title: 'Cash',
        routeLabel: '/cash',
        match: (p) => pathStarts(p, '/cash'),
        summary: 'Cash balance, reserved capital, available investable cash, and the cash ledger.',
        overview:
            'Manage portfolio cash independently of stock trades. Balance minus reserved cash is available to fund new recommendations. Deposit, withdraw, and adjust entries appear on the cash account statement.',
        controls: [
            { name: 'Deposit / Withdraw / Adjust', description: 'Change cash with amount stepper, optional remarks, and transaction date.' },
            { name: 'Reserved cash', description: 'Expand to see reservations tied to pending-execution buys.' },
            { name: 'Statement', description: 'Chronological cash ledger for the active portfolio.' },
        ],
        concepts: [
            { name: 'Available investable cash', description: 'Balance − reserved. Withdrawals cannot exceed this amount.' },
            { name: 'Capital allocation', description: 'The recommendation pipeline funds ideas from available cash; unfunded ideas become Watch.' },
        ],
        related: ['recommendations', 'pending-execution', 'strategy'],
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
            { name: 'Average buy / invested', description: 'Cost basis from open buy lots after fees allocation rules.' },
            { name: 'Unrealized P/L', description: 'Mark-to-market vs latest cached close.' },
            { name: 'Trailing stop metric', description: 'Shown on holdings; alert policies can act on it — not a separate automatic Telegram spam path.' },
        ],
        related: ['transactions', 'stock-prices', 'watchlist', 'dashboard'],
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
        summary: 'Named research lists with prices, pattern scans, and links into Explorer.',
        overview:
            'Maintain multiple watchlists per portfolio. Select a symbol for its price panel (`/watchlist/{SYMBOL}`), scan for patterns, and compare relative strength in Explorer.',
        controls: [
            { name: 'List switcher / add stock', description: 'Manage lists and quick-add symbols with notes.' },
            { name: 'Scan my watchlist', description: 'Runs pattern detection and can persist icons until prices refresh or expiry.' },
            { name: 'Compare strength', description: 'Deep-links into Explorer relative-strength views.' },
        ],
        concepts: [
            { name: 'Persisted pattern scans', description: 'Results stay valid until expiry or newer OHLCV arrives.' },
            { name: 'Holding badge', description: 'Shows when a watched symbol is also an open position.' },
        ],
        related: ['explorer', 'patterns', 'holdings'],
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
            { name: 'Share across portfolios', description: 'Reuse the same screener definition where supported.' },
            { name: 'Screener Registry', description: 'Open the registry to export/import Screener JSON, view versions, and copy shared screeners.' },
            { name: 'Guide tab (editor)', description: 'Plain-language indicator definitions and Investopedia links.' },
        ],
        concepts: [
            { name: 'Eligibility vs scoring', description: 'Screeners admit candidates; Strategy scoring ranks them afterward.' },
            { name: 'Scopes', description: 'Holdings, watchlist, all equities, or index constituents.' },
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
        summary: 'First-class Screener artifacts — metadata, versions, validate, and import/export JSON without changing the run engine.',
        overview:
            'The Screener Registry evolves existing portfolio_screeners into reusable Trading Artifacts. Each screener keeps the same definition_json condition tree used by the Screener execution engine. The registry adds slug, intent/summary/tags, artifact_version, definition_hash, and version history. Export downloads the approved Trading Artifact JSON envelope; Import / Create from JSON validates then creates a new screener in the active portfolio. Shared screeners from other portfolios appear as read-only registry entries and can be copied with Import copy. Admin settings also link the same UI under Settings → Screener Registry.',
        controls: [
            { name: 'Search / filters', description: 'Filter by status, ownership (own vs shared), and origin (factory / user / shared).' },
            { name: 'Export JSON', description: 'Download the Screener artifact envelope (schema_version, metadata, definition.root, dependencies).' },
            { name: 'Validate', description: 'Check pasted JSON against the Trading Artifact Screener rules before import.' },
            { name: 'Create from JSON', description: 'Import a validated envelope as a new screener in this portfolio.' },
            { name: 'Import copy (shared)', description: 'Copy a shared screener from another portfolio into yours (same as Shared screens import).' },
            { name: 'Open editor', description: 'Jump to the classic Screener editor to change conditions or run screens.' },
            { name: 'Version history', description: 'On detail for owned screeners, list definition snapshots and change notes.' },
        ],
        concepts: [
            { name: 'No execution redesign', description: 'Runs, schedules, and backtests still use ScreenerRunService and the existing definition tree.' },
            { name: 'Shared → Registry', description: 'is_shared screeners surface naturally in the registry list with ownership=shared; copying them creates a local owned artifact.' },
            { name: 'Version bump', description: 'Changing definition_json (via editor or registry update) increments artifact_version and appends portfolio_screener_versions.' },
        ],
        related: ['screener', 'screener-editor', 'indicator-registry', 'settings'],
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
        summary: 'One strategy per portfolio — default Minervini; edit tabs and Save.',
        overview:
            'Strategy is your decision policy. Each portfolio has exactly one active strategy. It starts as Minervini Strategy (Minervini Trend Template eligibility + momentum scoring). Edit any tab and Save — the active editor still saves in place.\n\n'
            + 'Use Strategy Registry to import/export JSON, validate packs, browse drafts, and Select which definition is active for this portfolio. Strategies reference Screeners by slug / factory key — they never duplicate Screener condition trees.\n\n'
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
                name: 'Strategy Registry',
                description:
                    'Open /strategy/registry to export/import Strategy JSON, validate packs, and Select the active strategy for this portfolio.',
            },
            {
                name: 'General tab',
                description:
                    'Name — label for your strategy (default: Minervini Strategy).\n'
                    + 'Description — free-text intent notes.\n'
                    + 'One active strategy per portfolio: Save overwrites the current active config in place.',
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
                    + 'Maximum Cash Deployment % / Minimum Cash Reserve % — how much cash may be invested vs kept aside (compared against cash balance).\n'
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
                name: 'One active strategy per portfolio',
                description:
                    'Exactly one strategy is active (selected). Defaults are Minervini Strategy (Minervini Trend Template screener + momentum weights/thresholds). Use Strategy Registry to switch selection; the editor Save still updates the active config in place.',
            },
            {
                name: 'Registry vs editor',
                description:
                    'Registry manages reusable JSON artifacts, drafts, and selection. The Strategy page edits the active portfolio strategy. Export never includes portfolio-local Screener ids — only slug / factory_key refs.',
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
        related: ['trading-os-flow', 'screener', 'screener-registry', 'strategy-registry', 'recommendations', 'pending-execution', 'cash', 'dashboard'],
    },
    {
        id: 'strategy-registry',
        keyword: 'strategy-registry',
        aliases: ['strategy-artifacts', 'strategy-json', 'import-strategy', 'select-strategy'],
        title: 'Strategy Registry',
        routeLabel: '/strategy/registry',
        match: (p) => pathStarts(p, '/strategy/registry') || pathStarts(p, '/settings/strategy-registry'),
        summary: 'Reusable Strategy artifacts — metadata, versions, validate/import/export JSON, and Select the one active strategy per portfolio.',
        overview:
            'The Strategy Registry evolves portfolio_tos_strategies into first-class Trading Artifacts. Each portfolio still has exactly one active Strategy (selection). Import creates drafts; Select activates one and archives the previous active. Export emits portable JSON: Screener refs by screener_slug / screener_factory_key and Indicator keys by registry id — never portfolio-local Screener ids and never embedded Screener trees. Existing Minervini (momentum_factory) migrates automatically to slug momentum_strategy with eligibility linked to minervini_trend_template.',
        controls: [
            { name: 'Search / filters', description: 'Filter by status (active/draft/archived) and origin (factory/user).' },
            { name: 'Select', description: 'Make this strategy the portfolio’s only active strategy (archives the previous active).' },
            { name: 'Export JSON', description: 'Download the portable Trading Artifact envelope.' },
            { name: 'Validate', description: 'Check pasted JSON against Strategy artifact rules before import.' },
            { name: 'Create from JSON', description: 'Import as a draft; does not change Recommendations until Select.' },
            { name: 'Edit active', description: 'Jump to /strategy to edit the selected strategy’s tabs and Save.' },
            { name: 'Version history', description: 'Draft updates that change definition hash append version rows; active editor Save remains in-place BC.' },
        ],
        concepts: [
            { name: 'Exactly one active', description: 'Selection rule: one STATUS_ACTIVE strategy per portfolio drives Recommendation scoring.' },
            { name: 'No Screener duplication', description: 'eligibility_sources reference Screeners; condition trees stay on Screener Registry.' },
            { name: 'Minervini auto-migrate', description: 'ensureActive / seedFactoryStrategy backfills slug, metadata, and Minervini screener eligibility links without overwriting user-edited scores.' },
        ],
        related: ['strategy', 'screener-registry', 'indicator-registry', 'recommendations', 'settings'],
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
        summary: 'Per-portfolio market events with optional Telegram reminders.',
        overview:
            'Track F&O / options expiry templates and custom recurring events on a year grid. Optional reminders fire before the event day via Telegram.',
        controls: [
            { name: 'Year grid', description: 'Color markers for event days; open a day for details.' },
            { name: 'Templates / custom events', description: 'Add expiry-style or custom recurrence rules.' },
            { name: 'Reminders', description: 'Configure advance notice when Telegram is set up.' },
        ],
        concepts: [
            { name: 'Portfolio-scoped events', description: 'Each portfolio has its own calendar.' },
        ],
        related: ['dashboard', 'notifications'],
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
            'Update how you appear in the header, change your password, and upload or remove a profile photo. Username (email) is read-only.',
        controls: [
            { name: 'Profile photo', description: 'Upload, change, or remove the avatar image.' },
            { name: 'Display name', description: 'Shown next to the avatar in the header menu.' },
            { name: 'Change password', description: 'Requires current password confirmation.' },
        ],
        concepts: [
            { name: 'Session auth', description: 'Sign-in uses Sanctum SPA cookies, not bearer tokens in localStorage.' },
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
        summary: 'Global (admin), Portfolio, and Account settings — fees, Telegram, sync, and links.',
        overview:
            'Settings is the only Administration item in the primary sidebar. Open it for Global (admins), Portfolio, and Account preferences. Admin tools (users, sync logs, data quality, indicator/screener/strategy registries, universe sync, alert policies) are linked from Settings cards — they are not separate sidebar entries.',
        controls: [
            { name: 'Settings tabs', description: 'Navigate Global / Portfolio / Account sections.' },
            { name: 'Fee components', description: 'Drive auto fees on buy/sell ledger rows.' },
            { name: 'Telegram', description: 'Bot token and chat id for portfolio notifications.' },
            { name: 'Notification history', description: 'Open /notification-history from Portfolio → Alerts & notifications to audit Telegram deliveries.' },
            { name: 'Admin shortcuts', description: 'From Global settings: Admin alerts, Sync logs, Universe price sync, Data Quality, Indicator/Screener/Strategy registries.' },
            { name: 'Screener Registry (admin)', description: 'Admin shortcut to Screener artifact import/export and catalogue.' },
            { name: 'Strategy Registry (admin)', description: 'Admin shortcut to Strategy artifact import/export, selection, and catalogue.' },
        ],
        concepts: [
            { name: 'Admin vs portfolio scope', description: 'Some tools (users, sync logs, universe sync) are admin-only.' },
            { name: 'Not in sidebar', description: 'Settings sub-pages and registries stay off the primary sidebar; reach them from Settings or parent product pages.' },
        ],
        related: ['alert-policies', 'universe-price-sync', 'data-quality-center', 'indicator-registry', 'screener-registry', 'strategy-registry', 'users', 'notifications'],
    },
    {
        id: 'alert-policies',
        keyword: 'alert-policies',
        aliases: ['alerts', 'rules'],
        title: 'Alert policies',
        routeLabel: '/settings/alert-policies',
        match: (p) => pathStarts(p, '/settings/alert-policies'),
        summary: 'Rule builder on holdings — evaluate after sync or on demand.',
        overview:
            'Define alert policies with columns, formulas, and constants against holdings. Policies generate alerts (including trailing-stop style metrics) that can notify via Telegram on schedule.',
        controls: [
            { name: 'Rule builder', description: 'Compose conditions evaluated against holding metrics.' },
            { name: 'Evaluate', description: 'Run on demand or after daily sync.' },
        ],
        concepts: [
            { name: 'Policy vs one-off alert', description: 'Policies are reusable rules; alerts are evaluation outcomes.' },
        ],
        related: ['notifications', 'holdings', 'settings'],
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
        overview: 'Review and acknowledge system operational alerts that are separate from portfolio trading alert policies.',
        controls: [
            { name: 'Alert inbox', description: 'Acknowledge or clear operational alerts.' },
        ],
        concepts: [
            { name: 'Ops vs trading alerts', description: 'Operational alerts concern infrastructure/data health; alert policies concern holdings rules.' },
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
            'Universe sync deepens and refreshes daily bars for the broader equity universe used by Explorer, Screeners, and Market Analysis. Related pages cover gap-fill failures and ignored gaps.',
        controls: [
            { name: 'Sync status / controls', description: 'Monitor batch progress, windows, and enablement.' },
            { name: 'Gap failures / ignored gaps', description: 'Drill into symbols that failed fill or were intentionally skipped.' },
        ],
        concepts: [
            { name: 'Batch executor', description: 'Per-stock provider fetch loop with rate-limit awareness and sync logging.' },
            { name: 'History depth', description: 'Longer windows improve indicators and backtests.' },
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
        summary: 'Admin read-only catalogue of Primary, Composite, and Metric indicators with dependency trees and formula documentation.',
        overview:
            'The Indicator Registry is the metadata source of truth for indicators (SD-033). Use the list to search and filter by category, type, and status. Open any row for description, parameters, consumers, capabilities, a dependency tree, and a formula explanation. Formula text is documentation only — there is no formula editor. Liquidity and Tradability indicators are available for Screeners and future Discovery / Dashboard / Stock Details consumers; they are not auto-wired into Strategy scoring or the Recommendation Engine.',
        controls: [
            { name: 'Search', description: 'Match against indicator id, display name, or description.' },
            { name: 'Category / Type / Status filters', description: 'Narrow the catalogue (e.g. liquidity composites, planned stubs).' },
            { name: 'Indicator row', description: 'Open the detail page for full metadata.' },
            { name: 'Dependency tree', description: 'On detail, expand declared depends_on relationships recursively.' },
            { name: 'Formula explanation', description: 'Read-only prose describing how the indicator is computed; not editable in the UI.' },
        ],
        concepts: [
            { name: 'Primary / Composite / Metric', description: 'Primaries are OHLCV calculators (often screenable). Composites combine dependencies. Metrics are descriptive analytics fields.' },
            { name: 'Screenable vs strategy-scorable', description: 'Screenable indicators appear in Screener conditions. Strategy-scorable composites appear in Strategy weights — Liquidity/Tradability composites are intentionally not strategy-scorable yet.' },
            { name: 'Circuit heuristics', description: 'Circuit Frequency / Risk use OHLCV heuristics (large move + locked range), not official exchange circuit feeds.' },
        ],
        related: ['settings', 'screener', 'strategy', 'data-quality-center'],
    },
    {
        id: 'users',
        keyword: 'users',
        aliases: ['user-management', 'invites'],
        title: 'User management',
        routeLabel: '/settings/users',
        match: (p) => pathStarts(p, '/settings/users'),
        summary: 'Admin invite links, password-reset links, and account administration.',
        overview:
            'Registration is invite-only. Admins create invite links and password-reset links for existing accounts without requiring the current password.',
        controls: [
            { name: 'Create invite', description: 'Generate a link for a new user to set a password and sign in.' },
            { name: 'Password reset link', description: 'Issue a reset URL for an existing account.' },
        ],
        concepts: [
            { name: 'Invite-only', description: 'There is no public self-registration endpoint for guests.' },
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
            { name: 'CSV import discipline', description: 'After uploading CSV, review each parsed row (type, quantity, price, date, exchange, fees). Correcting import mistakes before save avoids cleanup work later.' },
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
            'Universe sync health determines quality for Explorer, many Screeners, and market analytics. Treat this page as operational maintenance: monitor drift, investigate gaps, and keep history depth sufficient.',
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
