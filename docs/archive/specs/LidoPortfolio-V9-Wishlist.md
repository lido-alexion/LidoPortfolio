# LidoPortfolio / StoX V9 Wishlist

| Field | Value |
|---|---|
| **Document type** | Canonical V9 planning register |
| **Created** | 2026-09-09 |
| **Status** | EARLY PLANNING |
| **Canonical path** | `specs/LidoPortfolio-V9-Wishlist.md` |
| **Predecessor** | `specs/LidoPortfolio-V8-Wishlist.md` |

## 1. Purpose

V9 currently contains later StoX product expansion that follows the V7 analytical-data work and V8 standalone Telemetry Platform.

The Telemetry separation remains deliberate: V8 builds the reusable Telemetry product; V9 changes StoX to become a properly instrumented producer/consumer of that product.

The V9 assistance roadmap also builds on the task-oriented user-journey corpus under `docs/user-journeys/`. The human-readable journeys remain useful independently; V9 can progressively expose the same knowledge through deterministic search, conversational assistance, automation-ready UI contracts and eventually agentic actions.

## 2. Current V9 backlog

| ID | Feature | Scope / rationale | Status |
|---|---|---|---|
| V4-FEAT-017 | AI Assistant | Introduce an AI-assisted StoX experience after earlier platform, safety, analytical-data and ML foundations are established. Architecture, tool/data access, explainability, interaction model and decision-authority boundaries require dedicated V9 deliberation. AI must not silently override deterministic Strategy behavior or bypass artifact/versioning and execution safeguards. The three assistance epics below refine this broad item into documentation Q&A, deterministic journey discovery and later agentic execution. | OPEN |
| V4-FEAT-019 | ETF / Options / Crypto Expansion | Expand StoX beyond its current equity-centric instrument model. ETF, Options and Crypto have materially different market-data, pricing, lifecycle, accounting, settlement, risk and execution needs and may be decomposed into separate V9 epics during planning. | OPEN |
| TBD | StoX Telemetry Platform Integration | Integrate StoX with the standalone Telemetry Platform after the V8 platform is available. Scope includes StoX-side SDK/API integration, product/environment credentials, identity/session/context mapping, operational telemetry, product-usage events, correlation propagation, privacy-safe metadata, delivery/failure isolation, and any StoX-facing analytics/query/deep-link integration deliberately selected during V9 planning. | OPEN |
| TBD | Historical Fundamentals Bootstrap Admin Operations | Enhance the V8 FEAT-054 dedicated, resumable historical-fundamentals bootstrap workflow into a full Admin UI-driven operational capability. Add UI controls for starting targeted/full backfills, pausing/resuming, retrying failures, inspecting per-stock/cadence/source progress, viewing checkpoints and coverage/provenance diagnostics, and rerunning after mapping/source improvements. Preserve the V8 bootstrap service as the execution engine; V9 should add operational UX rather than create a parallel ingestion path. | WISHLIST |
| TBD | Data Export Framework | Add investor-facing export capabilities across StoX data surfaces. Support exporting data shown in tables and chart series, with formats and scope to be decided during V9 planning. Prefer reusable export plumbing rather than page-specific implementations. | WISHLIST |
| TBD | Customizable Summary Fields & Dashboard Layouts | Allow investors to customize which fields/metrics appear in stock summaries and to personalize dashboard card composition/layout. Scope may include pin/unpin, reorder, visibility preferences and reusable layout persistence, while preserving sensible defaults and responsive behavior. | WISHLIST |
| TBD | Combo Chart Support | Add reusable multi-series chart support for compatible metrics, including shared range/frequency controls, legends, tooltips and dual-axis handling where appropriate. Preserve V8 one-metric-per-chart as the default. | WISHLIST |
| V9-UX-001 | User Journey Automation Readiness & E2E Automation | Evolve the `docs/user-journeys/` human workflow corpus into an automation-ready contract. Review each important journey for usability and missing states; simplify unnecessarily difficult flows where accepted; add stable semantic UI hooks/IDs such as `data-testid` only where needed; map journey IDs to automated cases; implement Playwright/Selenium-style end-to-end automation for the high-value screener -> strategy -> recommendation -> review -> pending execution -> transaction workflows and recovery paths. Human journey documentation remains the starting point; automation selectors/code must not pollute the user-facing instructions. | WISHLIST |
| V9-AI-001 | Documentation-Grounded StoX Chatbot | Add a read-only LLM chatbot that answers StoX “how do I?” and product-usage questions from the maintained documentation/user-journey corpus, for example “How do I create a screener?” or “How do I cancel a pending execution?”. Support configurable model providers: a low/no-cost Gemini path where available, configurable frontier-model providers, and an approved Codex/OpenAI path where technically/licensing-wise appropriate. Retrieval must ground answers in StoX documentation and expose relevant page/journey links. This phase explains and navigates only: it must not mutate StoX data, approve recommendations, or place trades. | WISHLIST |
| V9-UX-002 | User Journey Typeahead / “How Do I?” Search | Add deterministic, non-LLM discovery of user journeys from a search/help box. Maintain searchable question-style titles/aliases such as “How do I create a screener?”, “How do I edit a strategy?” and “How do I cancel an order?”. As the user types words such as `screener`, perform simple text/token matching against journey question headings/aliases and show typeahead suggestions. Selecting a suggestion opens the corresponding rendered documentation page directly at that journey/section. Keep this fast, deterministic and usable without an LLM. | WISHLIST |
| V9-AI-002 | Agentic StoX Assistant / MCP Action Layer | Extend the assistance experience from read-only guidance to explicitly authorized actions. A user should eventually be able to request, for example, “Create a screener with price above MA200 and RSI below 70,” and have the assistant construct and perform the corresponding StoX workflow through governed tools/APIs. Define an MCP/tool layer (or then-current equivalent), typed action contracts, permissions, validation, previews, confirmations, audit/provenance, idempotency and recovery. High-impact operations must preserve StoX deterministic strategy, recommendation, execution and broker-safety boundaries; the agent must not silently approve or execute money-moving actions. Reuse the documented user journeys as task semantics and the automation-ready UI/API contracts where useful, rather than teaching the model to click arbitrary UI. | WISHLIST |

## 3. Assistance roadmap relationship

The assistance epics are intentionally progressive rather than one monolithic chatbot project:

1. **V9-UX-001 — Automation readiness:** turn documented human journeys into stable, testable product workflows and build automated E2E coverage.
2. **V9-UX-002 — Deterministic discovery:** let users find a known “How do I?” journey quickly through typeahead/text matching and open the exact documentation section.
3. **V9-AI-001 — Conversational documentation:** let an LLM retrieve, synthesize and explain the same maintained StoX documentation without gaining write authority.
4. **V9-AI-002 — Agentic actions:** only after governed tool/action contracts exist, allow the assistant to perform explicitly authorized StoX operations.

Typeahead and chatbot may share the same maintained journey/question metadata, but deterministic typeahead must not depend on an LLM. The agentic phase may use the chatbot UX, but read-only retrieval and write/action authority must remain separable capabilities.

## 4. Inherited boundary

The Telemetry Platform remains independently deployable and product-independent. StoX must not absorb Telemetry storage, analytics, dashboards or platform administration into its own codebase.

Telemetry failure must not block StoX business workflows. StoX audit/business evidence remains distinct from telemetry.

AI/ML additions must preserve inherited deterministic, explainable Strategy behavior unless a later explicit product decision supersedes it. Instrument expansion must preserve existing equity behavior non-breakingly unless an instrument-specific specification explicitly changes a shared abstraction.

Documentation-grounded AI must distinguish sourced StoX behavior from model-generated explanation. Model-provider configuration must not weaken authorization, privacy, audit or execution safeguards. Agentic capabilities must use explicit governed actions rather than unrestricted database mutation or arbitrary UI control for money-moving workflows.

Detailed V9 behavior will be frozen during V9 planning against the completed V7/V8 foundations and the then-current StoX implementation.
