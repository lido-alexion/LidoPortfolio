# V9-AI-003 — Embedded AI Insights & Prompt Execution Specification

| Field | Value |
|---|---|
| **Epic** | `V9-AI-003` |
| **Feature** | Embedded AI Insights & Prompt Execution |
| **Status** | **FROZEN / IMPLEMENTATION-READY** |
| **Release** | V9 |
| **Depends on** | `V4-FEAT-017` AI Platform & Governance; `V9-AI-002` for governed Strategy draft creation; V8 `V4-FEAT-062` where fundamental AI insight is available |
| **Technical architecture** | [`V9-AI-Technical-Architecture-Specification.md`](V9-AI-Technical-Architecture-Specification.md) |

## 1. Purpose

StoX currently exposes user-facing AI prompt affordances that generate a prompt and copy it to the clipboard so the user can manually paste it into an external AI chat interface.

V9 SHALL convert those existing prompt affordances into first-class StoX AI experiences that execute through the shared V9 AI infrastructure, present the result in the most appropriate product context, cache valid responses, and preserve the existing copy-prompt behavior as a degraded fallback when managed AI execution is unavailable.

This epic does not introduce a parallel AI platform. All inference SHALL use `V4-FEAT-017` and the frozen V9 AI technical architecture.

Core principle:

> Existing external-AI prompts become managed StoX AI capabilities, while the current copy-prompt workflow remains the last-resort recovery path.

## 2. Audited existing prompt inventory

The current implementation contains two distinct user-facing external-AI prompt capabilities.

### 2.1 Stock analysis prompt

Shared implementation:

- `app/resources/js/src/components/AnalyseStockButton.jsx`
- `app/resources/js/src/utils/stockAnalysisPrompt.js`

The shared `AnalyseStockButton` currently fetches recent stock market-price/OHLCV data, builds the stock-analysis prompt, and copies it to the clipboard.

The audited UI placements are:

1. Holdings — beside stock symbol in the Holdings table.
2. Dashboard — stock-analysis icon in stock-symbol context #1.
3. Dashboard — stock-analysis icon in stock-symbol context #2.
4. Watchlist — per-stock row/list action.
5. Watchlist — selected-stock detail panel beside the stock heading.
6. Stock Explorer — normal analysis result/latest-close stock card.
7. Stock Explorer — manual-fallback analysis result/latest-close stock card.

These seven placements SHALL consume one logical AI capability rather than seven independent implementations.

Recommended stable capability ID:

`stock_analysis_insight`

### 2.2 AI Strategy Designer prompt

Shared implementation:

- `app/resources/js/src/components/strategy/AIStrategyPromptBuilder.jsx`
- `app/resources/js/src/strategyPrompt/`

The existing Strategy page AI Strategy Designer builds a structured external-AI strategy-design prompt from user-selected inputs and copies it to the clipboard.

Recommended stable capability ID:

`strategy_designer`

### 2.3 Explicitly excluded clipboard functions

General clipboard functions such as debug-report copy, Knowledge Board export copy, invitation/user-management copy actions, and similar non-AI clipboard utilities are not part of this epic.

## 3. Shared AI-platform integration

Both capabilities SHALL consume `V4-FEAT-017` and the frozen V9 AI technical architecture.

They SHALL use the shared:

- capability registry;
- prompt/template registry and versioning;
- provider/model routing and failover;
- budgets and path eligibility;
- structured output validation;
- streaming support;
- concurrency/service-class controls;
- circuit breakers/health handling;
- inference/routing logging;
- cost accounting;
- operational/admin diagnostics.

The implementation MUST NOT create direct provider-specific calls from React or Laravel feature code when the shared AI capability layer exists.

## 4. Stock-analysis evidence model

The new stock insight SHALL preserve the intent of the existing stock-analysis prompt but enrich the supplied evidence with relevant authoritative StoX data where available.

Potential evidence includes:

- recent OHLCV / market-price context;
- relative-strength calculations and benchmark context;
- detected technical/pattern signals;
- deterministic fundamental metrics/signals;
- valuation context;
- existing V8 `V4-FEAT-062` fundamental AI interpretation when current and valid;
- active-portfolio holding context when the stock is currently held.

StoX SHALL perform deterministic calculations itself. The LLM interprets, connects, summarizes, and explains the supplied evidence rather than acting as the authoritative calculator.

## 5. Reuse of V8 fundamental AI insight

Where V8 `V4-FEAT-062` has a current valid fundamental AI insight, `stock_analysis_insight` SHALL reuse it as an input rather than independently asking another LLM to redo the same fundamental interpretation.

The broader stock insight is an orchestration/synthesis layer, not a second fundamental-analysis engine.

If the V8 fundamental AI interpretation is unavailable or stale, the stock insight MAY continue using available deterministic fundamental evidence and SHALL disclose the missing component.

## 6. Partial-evidence behavior

Missing subcomponents SHALL NOT block the whole stock insight.

The capability SHALL produce the best-supported response from the authoritative evidence currently available and clearly disclose material missing inputs, for example:

- fundamental AI interpretation unavailable;
- pattern scan unavailable;
- relative-strength data unavailable;
- historical data incomplete.

The model MUST NOT invent conclusions to fill missing evidence.

The whole insight fails only when the managed inference path cannot return a valid response under the shared AI platform contract.

## 7. Stock insight structured response contract

The investor-facing stock result SHALL be structured rather than free-form.

The normalized response SHALL support at least:

- **Summary**;
- **Technical / price context**;
- **Fundamental context**;
- **Positive signals**;
- **Risks / watch items**;
- **What to check next**;
- **Data limitations / as-of information**.

The backend contract SHOULD use a validated structured schema suitable for consistent rendering across inline, pane, and modal presentations.

The output SHALL remain analytical and non-prescriptive. It MUST NOT provide:

- Buy/Sell/Hold recommendations;
- target prices;
- future-price predictions presented as authoritative;
- personalized instructions to add/reduce/exit a position.

## 8. Holding-aware personalization

### 8.1 Active portfolio only

When the stock is held in the currently active portfolio, the insight MAY use relevant active-portfolio context, including where available:

- quantity;
- average cost;
- invested value;
- current/unrealized P/L;
- portfolio weight;
- concentration/exposure context.

It SHALL NOT automatically aggregate holdings across other portfolios.

### 8.2 Bounded interpretation

Holding context may influence interpretation, such as noting concentration or relating current price to average cost, but the response remains analytical and non-prescriptive.

### 8.3 User-visible personalization indicator

When holding context is included, the insight SHALL show a subtle indicator such as:

> **Personalized with your active portfolio holding**

The detailed fields used are reflected through the concise provenance/data-used section.

## 9. Watchlist boundary

Watchlist membership alone SHALL NOT make an insight account-scoped.

Private watchlist notes/research state SHALL NOT be included in the AI prompt/input under this epic.

A non-held stock remains eligible for the global stock-level cache even when it appears in one or more user watchlists.

## 10. Cache model

AI insight caching SHALL be server-side and persistent across application/runtime restarts and deployments.

### 10.1 Data-fingerprint validity

Cache validity is based on a deterministic input fingerprint, not a fixed TTL.

A cached result remains valid while all material inputs remain unchanged.

Typical invalidators include:

- newer or revised OHLCV/market data used by the capability;
- changed deterministic signal/evidence payload;
- changed holding/account context where applicable;
- prompt/template version change;
- capability version change;
- response-schema version change.

Provider/model changes alone SHALL NOT invalidate an otherwise valid cache entry.

### 10.2 Stock cache scopes

The stock capability uses two mutually exclusive cache scopes.

#### Global stock scope

When the stock is **not held** in the active portfolio:

- use a global stock-level cache;
- input contains only canonical/non-private stock evidence;
- result may be reused across authorized users/accounts.

Conceptual scope:

`global_stock`

#### Account holding scope

When the stock **is held** in the active portfolio:

- use an account/active-portfolio-scoped cache;
- include applicable holding-specific evidence;
- do not expose a separate global-vs-personal toggle to the user.

Conceptual scope:

`account_holding`

The context class MUST be part of cache identity so global and holding-personalized responses can never be mixed.

A stock may therefore have both a global cached response and one or more account/portfolio-scoped responses.

### 10.3 Strategy Designer cache scope

Strategy Designer responses are account-scoped only, even when normalized inputs are identical across users.

Cache identity SHALL include the normalized Strategy Designer input fingerprint plus relevant prompt/capability/schema versions.

### 10.4 Persistence metadata

Persist sufficient cache metadata to support correctness and auditability, including where applicable:

- capability ID;
- cache scope;
- stock/entity ID;
- account/portfolio ID when scoped;
- input fingerprint;
- prompt/template version;
- capability/schema version;
- generated timestamp;
- data-as-of metadata;
- provider/model/routing metadata;
- structured validated response;
- refresh/failure metadata where useful.

### 10.5 User-visible history

This epic does not provide user-visible historical AI-insight version browsing.

The UI uses the current valid response for the applicable fingerprint/context. Older inference events may remain in platform operational/audit logs according to AI governance rules.

## 11. Manual refresh/regeneration

Users SHALL have a **Refresh insight** / **Regenerate** action even while the current cache remains valid.

Manual refresh:

- bypasses the valid cache;
- performs a new managed inference using current configuration;
- replaces the current cached response for the same applicable fingerprint/context only after successful validation.

A failed refresh MUST NOT discard an existing valid cached result.

If refresh fails while a valid cached result exists, continue showing it with a subtle status such as:

> Fresh AI refresh unavailable; showing the latest cached insight.

The copy-prompt recovery action remains available in this degraded state.

## 12. Invocation model

Generation is explicit/on-demand only.

StoX SHALL NOT proactively precompute AI insights for:

- all holdings;
- all watchlist stocks;
- all stocks visible in a table;
- background portfolio-wide insight batches.

Clicking the existing AI icon starts/opens the insight directly.

Behavior:

- valid cache -> open and render immediately;
- no valid cache -> open the surface and start managed generation immediately.

The icon itself SHALL remain visually consistent and SHALL NOT show cache-status badges or generated/not-generated decoration.

## 13. Interactive execution and streaming

AI insight generation is interactive with a bounded wait.

Requirements:

- open the presentation surface immediately;
- show a clear generating/loading state;
- use streaming where the selected provider/path supports it;
- progressively render content only where technically safe;
- normalize/validate the final structured response before treating it as a valid cached result;
- do not silently continue as a background job after interactive timeout.

If streaming fails mid-response, partial output MUST NOT be presented as a valid final result.

## 14. Failure and clipboard fallback

The current prompt-copy workflow SHALL remain the user-facing recovery path when managed AI cannot return a valid response.

Examples include:

- all eligible inference paths unavailable;
- timeout;
- hard-budget exhaustion;
- rate limit;
- circuit breaker/manual hold;
- provider/runtime failure;
- malformed output after bounded validation/repair/failover;
- AI runtime unavailable.

Fallback behavior:

### 14.1 Valid cached response exists

- keep the valid cached response visible;
- explain that fresh managed AI is unavailable;
- expose **Copy AI Prompt** as recovery.

### 14.2 No valid cached response exists

- show a clear degraded/unavailable state;
- expose the existing **Copy AI Prompt** behavior.

Normal successful UX SHALL NOT expose the raw prompt by default.

## 15. Stock insight presentation rules

Presentation is context-sensitive and prefers retaining page context.

### 15.1 Dedicated stock-analysis contexts

Where the page is already focused on a single stock and has sufficient layout space, prefer an **expandable inline AI Insights section**.

Applies to:

- Watchlist selected-stock panel;
- Stock Explorer normal analysis result;
- Stock Explorer manual-fallback result.

### 15.2 Dense/compact contexts

For table/list/dashboard placements such as:

- Holdings;
- Dashboard stock contexts;
- Watchlist stock rows;

use the following responsive behavior:

1. **Extra-large screens:** prefer a persistent right-side AI Insights pane using approximately **35% of viewport width**, retaining the underlying page/context on the left.
2. **Layouts where the side pane would materially crowd the page:** use a **near-full-page modal** rather than a small dialog.

Inline/context-retaining presentation is preferred over modal presentation whenever practical.

### 15.3 Secondary actions

The opened stock insight surface SHALL support contextually appropriate actions including:

- Refresh insight;
- Copy insight;
- Open stock details;
- Copy AI Prompt only when managed-AI recovery/fallback is applicable.

Provider/model information is not shown in normal investor-facing UX.

## 16. Provenance / data-used presentation

Stock insights SHALL expose concise provenance through a compact **Based on** / **Data used** area.

Where applicable, show information such as:

- OHLCV through `<date>`;
- fundamentals through `<period>`;
- relative-strength benchmark and as-of date;
- pattern scan as-of date;
- whether active-portfolio holding context was included.

The UI SHALL NOT expose:

- private model chain-of-thought;
- internal orchestration prompts;
- raw routing internals;
- provider/model details in normal user-facing presentation.

Admin operational visibility remains governed by `V4-FEAT-017`.

## 17. Copy insight

Successful AI result surfaces SHALL provide an explicit **Copy insight** action.

The copied representation SHOULD preserve useful section headings and concise provenance/as-of information where practical.

**Copy AI Prompt** is a degraded/manual recovery action, not the primary successful-state action.

## 18. Open stock details navigation

From compact contexts such as Holdings, Dashboard, or Watchlist rows, the insight pane/modal SHALL provide **Open stock details** or equivalent safe navigation to the relevant stock analysis/details surface.

This is navigation only and does not perform a mutation.

## 19. Strategy Designer managed execution

The existing AI Strategy Designer SHALL be converted from a copy-only external-AI workflow into managed AI execution through the `strategy_designer` capability.

The existing structured user inputs remain the basis of the request.

### 19.1 Result presentation

The AI-generated strategy result SHALL remain in the Strategy Designer context rather than forcing the user into a generic stock-insight modal.

The response SHALL be persisted by normalized input fingerprint within the account. Returning to the same inputs SHALL immediately show the matching cached result.

Changing material inputs produces a different fingerprint and the old response MUST NOT be presented as current for the new configuration.

### 19.2 Structured output

Strategy Designer output SHALL be fully structured and validated.

It SHALL cover at least:

- strategy summary;
- target market/universe;
- entry logic;
- exit logic;
- allocation / position-sizing logic;
- risk controls;
- assumptions;
- caveats;
- explainability / rationale;
- StoX artifact compatibility notes.

The response contract SHOULD be designed so downstream governed conversion into a StoX draft strategy is deterministic and inspectable rather than requiring arbitrary parsing of prose.

### 19.3 Advisory-first behavior

A successful Strategy Designer result remains advisory until the user explicitly chooses **Create draft strategy**.

AI generation itself MUST NOT automatically create or modify a StoX Strategy artifact.

### 19.4 Create draft strategy

Selecting **Create draft strategy** enters the governed mutation workflow owned by `V9-AI-002`, including applicable:

- authorization;
- plan/preview;
- grouped confirmation;
- stale-state validation;
- deterministic artifact validation;
- mutation audit;
- post-action verification.

No implicit draft creation is allowed.

## 20. Prompt visibility

For successful managed AI execution, normal users SHALL NOT see the exact underlying prompt by default.

Normal successful UX exposes:

- insight/result;
- provenance/data used;
- refresh/regenerate;
- copy insight/result;
- navigation/action affordances applicable to the feature.

Raw/exact prompt visibility for Admin follows the shared AI platform governance/logging configuration.

## 21. Provider/model visibility and cache semantics

Normal users SHALL NOT see provider/model identity for successful responses.

A provider/model configuration change alone SHALL NOT invalidate a valid cached response.

Manual refresh/regeneration naturally uses the current effective route and may therefore produce a response through a different provider/model.

## 22. Authorization and privacy

- All surfaces are authenticated and follow normal StoX authorization.
- Global stock cache entries MUST contain only canonical/non-private stock evidence.
- Account/portfolio-specific holding evidence MUST never enter a global cache entry.
- Watchlist notes/private research context are excluded from this epic's AI input.
- Laravel remains authoritative for user/account/portfolio scope and data access under the frozen AI technical architecture.
- Python/FastMCP MUST NOT bypass Laravel authorization or directly access the StoX database.

## 23. Relationship to other AI epics

### 23.1 `V4-FEAT-017`

Owns shared inference/routing/provider/budget/health/prompt/logging infrastructure. AI-003 consumes it and SHALL NOT duplicate it.

### 23.2 `V9-AI-001`

Owns documentation-grounded conversational help. AI-003 owns embedded contextual execution of existing product-specific AI prompt affordances.

### 23.3 `V9-AI-002`

Owns governed account tools and mutations. AI-003 uses AI-002 for **Create draft strategy** and does not define a second mutation framework.

### 23.4 V8 `V4-FEAT-062`

Owns the deterministic fundamental-signals engine and associated fundamental AI interpretation. AI-003 reuses those outputs when relevant/current rather than rebuilding the engine.

## 24. Technical architecture constraints

Implementation SHALL follow `V9-AI-Technical-Architecture-Specification.md`.

In particular:

- React -> Laravel remains the public application path;
- Laravel -> private Python AI runtime handles managed inference;
- Python does not directly query/write MariaDB;
- business/domain authorization remains in Laravel;
- AI features use shared capability/provider plumbing;
- no LangChain/LangGraph dependency is introduced as the core StoX orchestration framework;
- FastMCP/Pydantic patterns are used where the shared architecture calls for governed tool contracts;
- AI failure must not break deterministic/non-AI StoX functionality.

## 25. Testing requirements

Automated coverage SHALL include at least:

### Stock insight

- all seven audited placements still open the same logical capability;
- responsive pane vs near-full-page-modal behavior;
- inline behavior on dedicated stock contexts;
- global vs account-holding cache scope selection;
- held-stock personalization indicator;
- watchlist membership does not leak private context into global cache;
- fingerprint hit/miss/invalidation;
- provider/model change does not invalidate cache;
- manual refresh bypasses cache;
- failed refresh preserves valid cache;
- partial evidence does not block valid generation;
- missing evidence appears in limitations/provenance;
- fallback prompt appears on managed-AI failure;
- partial streamed result is not persisted/rendered as complete after failure;
- Copy insight;
- Open stock details navigation.

### Strategy Designer

- normalized input fingerprinting;
- account-only cache scope;
- restored inputs restore matching cached result;
- changed inputs do not show mismatched previous result;
- structured output validation;
- refresh/regenerate behavior;
- degraded Copy AI Prompt fallback;
- no automatic strategy mutation after generation;
- Create draft strategy enters AI-002 governed mutation path.

### Security/regression

- global cache cannot contain account/portfolio-specific data;
- authorization is enforced server-side;
- direct Python DB access is absent;
- existing prompt-generation code remains usable for fallback;
- non-AI StoX behavior remains functional when the AI runtime is unavailable.

## 26. Acceptance criteria

The epic is implementation-complete only when:

1. all audited external-AI prompt affordances are mapped to managed V9 AI capabilities;
2. the seven stock placements consume one `stock_analysis_insight` capability;
3. Strategy Designer consumes one managed `strategy_designer` capability;
4. normal successful interaction no longer requires manual external prompt paste;
5. existing Copy AI Prompt behavior remains available as degraded recovery;
6. stock responses use the frozen structured multi-section contract;
7. relevant authoritative StoX evidence is enriched into stock insight inputs;
8. V8 fundamental AI interpretation is reused rather than redundantly regenerated where valid;
9. missing evidence degrades gracefully rather than blocking the whole insight;
10. non-held stocks use the global cache and held stocks use active-portfolio/account-scoped cache;
11. global cache entries contain no private holding/watchlist context;
12. watchlist notes are excluded;
13. persistent data-fingerprint-based caching works across restarts/deployments;
14. provider/model changes alone do not invalidate cache;
15. users can manually refresh/regenerate;
16. failed refresh preserves an existing valid result;
17. stock insights are explicit/on-demand only, not proactively precomputed;
18. streaming/loading behavior is implemented with bounded interactive timeout;
19. all dense stock surfaces use right-side pane on extra-large screens and near-full-page modal when pane layout is unsuitable;
20. dedicated stock-analysis surfaces use expandable inline presentation where practical;
21. stock results expose concise provenance/as-of metadata;
22. held-stock results visibly indicate active-portfolio personalization;
23. Copy insight and Open stock details actions work;
24. Strategy Designer responses are fully structured and account-scoped;
25. Strategy generation is advisory-first and does not mutate StoX automatically;
26. Create draft strategy uses the existing AI-002 governed mutation/confirmation path;
27. normal users do not see raw prompts or provider/model implementation details in successful UX;
28. all inference routes through the shared AI platform and frozen Laravel/Python technical architecture;
29. AI failure never breaks deterministic/non-AI StoX functionality;
30. required user journeys and V9-UX-001 E2E coverage are updated before final V9 closure.

## 27. Frozen product decisions summary

- Replace existing external-prompt workflow with managed StoX AI execution.
- Preserve Copy AI Prompt as degraded fallback.
- One stock capability shared by all seven current stock placements.
- Context-sensitive UI: inline where natural; ~35% right pane on extra-large screens; near-full-page modal where pane is unsuitable.
- Clicking the existing AI icon opens/generates insight directly.
- No cache-state decoration on the icon.
- Explicit/on-demand generation only.
- Interactive bounded execution; no silent background continuation.
- Stream progressively where supported, but only validated complete results become canonical/cacheable.
- Data-fingerprint cache invalidation; no fixed TTL requirement.
- Manual refresh/regenerate allowed.
- Failed refresh keeps valid cached result visible.
- Persistent server-side cache.
- Non-held stock -> global canonical cache.
- Held stock -> active-portfolio/account-scoped enriched cache.
- Held users see only the enriched insight, not a global/personal toggle.
- Watchlist membership/notes do not affect AI input/cache scope.
- Holding context may be interpreted, but no personalized trading recommendation.
- Structured stock response with summary, technical, fundamental, positive, risk/watch, follow-up, limitations/as-of sections.
- Concise provenance/data-used area is mandatory.
- Copy insight supported.
- Open stock details supported from compact contexts.
- Normal successful UX hides raw prompt and provider/model identity.
- Provider/model changes alone do not invalidate cache.
- Reuse current valid V8 fundamental AI interpretation.
- Missing evidence does not block the broader insight.
- Strategy Designer cache is account-scoped by normalized input fingerprint.
- Strategy Designer results persist and restore for matching inputs.
- Strategy Designer output is fully structured.
- Strategy Designer is advisory-first.
- Create draft strategy is explicit and governed through V9-AI-002.
