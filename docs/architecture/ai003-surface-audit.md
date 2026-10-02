# AI-003 integration audit

Baseline: `68eaf153` (2026-10-02). The seven existing stock affordances all import
`components/AnalyseStockButton.jsx` and build fallback text through
`utils/stockAnalysisPrompt.js`.

| Placement | Source | Presentation | Capability |
|---|---|---|---|
| Holdings symbol | HoldingsPage.jsx | pane/modal | stock_analysis_insight |
| Dashboard symbol #1 | DashboardPage.jsx (first call) | pane/modal | stock_analysis_insight |
| Dashboard symbol #2 | DashboardPage.jsx (second call) | pane/modal | stock_analysis_insight |
| Watchlist row | WatchlistPage.jsx (second call) | pane/modal | stock_analysis_insight |
| Watchlist selected stock | WatchlistPage.jsx (first call) | inline | stock_analysis_insight |
| Explorer result | StockExplorerPage.jsx (first call) | inline | stock_analysis_insight |
| Explorer manual fallback | StockExplorerPage.jsx (second call) | inline | stock_analysis_insight |
| Strategy Designer | strategy/AIStrategyPromptBuilder.jsx | existing panel | strategy_designer |

Stock fallback fetches `/stocks/{id}/market-prices` and uses Clipboard API;
Strategy fallback uses `strategyPrompt/generatePrompt.js`, defaults and templates,
with a textarea/execCommand fallback. Preserve both builders. Stock details use
`/holdings/{stockId}/prices`; Explorer also accepts `?symbol=`.

Laravel identity must be resolved without the lazy portfolio initializer, using
owned PortfolioProfile and authenticated user. AiToolCatalog's portfolio.holdings
projection uses PortfolioCalculationService and nulls incomplete valuations and
weights. It is the shared authoritative holding projection. Never read watchlist
notes for embedded evidence.

FundamentalSignalsService owns deterministic fundamentals. FEAT-062's
AIInsightsService currently returns interpretation without persisting it; reuse
requires retaining successful interpretations keyed by their deterministic input,
then reading them without invoking the FEAT-062 generator. No independent
fundamental generation is allowed in the stock assembler.

AI-002 strategy.create is enabled. AiAgentService owns preview, grouped approval,
plan hash, expiry, stale-state checks, execution and verification. AiToolCatalog
validates through StrategyArtifactRegistry and creates Library drafts through
LegacyArtifactAuthoringService. AI-003 must pass structured envelopes into this
existing path. Generation alone never calls mutate/createDraft.

Implementation and acceptance status are tracked separately; this inventory is
not a claim that the epic has passed its verification gates.
