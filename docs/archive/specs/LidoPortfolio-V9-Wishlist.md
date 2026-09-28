# LidoPortfolio / StoX V9 Wishlist

| Field | Value |
|---|---|
| **Document type** | Canonical V9 planning register |
| **Created** | 2026-09-09 |
| **Status** | EARLY PLANNING |
| **Canonical path** | `specs/LidoPortfolio-V9-Wishlist.md` |
| **Predecessor** | `specs/LidoPortfolio-V8-Wishlist.md` |

## 1. Purpose

V9 contains later StoX product expansion that follows the V7 analytical-data work and the V8 product/platform foundations.

StoX telemetry instrumentation and integration with the existing standalone LidoTelemetry product are owned by V8 `V4-FEAT-052`. V9 does not carry a separate StoX telemetry-integration epic; later telemetry-facing UX or analytics capabilities, if ever needed, must be registered explicitly as distinct future work rather than reopening FEAT-052 scope.

The V9 assistance roadmap also builds on the task-oriented user-journey corpus under `docs/user-journeys/`. The human-readable journeys remain useful independently; V9 can progressively expose the same knowledge through deterministic search, conversational assistance, automation-ready UI contracts and eventually agentic actions.

## 2. Current V9 backlog

The backlog below is ordered by intended development priority. `V9-UX-001` is the highest-priority V9 epic. Later items may still be developed in parallel where dependencies permit, but this order is the default planning sequence.

| ID | Feature | Scope / rationale | Status |
|---|---|---|---|
| V9-UX-001 | User Journey Automation Readiness & E2E Automation | Evolve the `docs/user-journeys/` human workflow corpus into an automation-ready contract. Review every in-scope journey against the authoritative golden/user journey, resolve all documented UX/product differences before automation, and implement Chromium E2E coverage with deterministic seeded data, key recovery/error paths, representative mobile coverage, targeted visual regression, lightweight accessibility checks, nightly regression, safe broker simulation, and non-destructive production smoke validation. Canonical frozen specification: [`V9-User-Journey-Automation-E2E-Specification.md`](V9-User-Journey-Automation-E2E-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-UX-002 | User Journey Typeahead / “How Do I?” Search | Add deterministic, non-LLM in-product help discovery through a global authenticated search entry and keyboard shortcut. Search authoritative journeys plus selected user-facing help using titles, aliases, keywords and synonyms; present concise complete textual steps directly in the result, material prerequisites/warnings, lightweight match explanations, safe page-navigation actions, alternative matches, deep-linkable/shareable help state, bounded recent history, light help-only personalization, feedback, and privacy-conscious diagnostics. Search discovery ignores permissions but normal StoX authorization still governs target routes/actions. Canonical frozen specification: [`V9-User-Journey-Typeahead-How-Do-I-Search-Specification.md`](V9-User-Journey-Typeahead-How-Do-I-Search-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-COMM-001 | StoX Email Notifications & Account Lifecycle Messaging | Add a canonical StoX notification-event model with in-app history and authenticated email delivery. Preserve the existing invitation generation/content behavior while automating delivery; add delivery state, retry/manual fallback, optional-email preferences, quiet hours/digests, critical product notifications, and a notification center. Canonical frozen specification: [`V9-StoX-Email-Notifications-Account-Lifecycle-Messaging-Specification.md`](V9-StoX-Email-Notifications-Account-Lifecycle-Messaging-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-OPS-001 | Historical Fundamentals Bootstrap Admin Operations | Enhance the V8 FEAT-054 dedicated, resumable historical-fundamentals bootstrap workflow into a full Admin UI-driven operational capability. Add UI controls for starting targeted/full backfills, pausing/resuming, retrying failures, inspecting per-stock/cadence/source progress, viewing checkpoints and coverage/provenance diagnostics, and rerunning after mapping/source improvements. Preserve the V8 bootstrap service as the execution engine; V9 should add operational UX rather than create a parallel ingestion path. | WISHLIST |
| V9-DATA-001 | Data Export Framework | Add investor-facing export capabilities across StoX data surfaces. Support exporting data shown in tables and chart series, with formats and scope to be decided during V9 planning. Prefer reusable export plumbing rather than page-specific implementations. | WISHLIST |
| V9-UX-003 | Customizable Summary Fields & Dashboard Layouts | Allow investors to customize which fields/metrics appear in stock summaries and to personalize dashboard card composition/layout. Scope may include pin/unpin, reorder, visibility preferences and reusable layout persistence, while preserving sensible defaults and responsive behavior. | WISHLIST |
| V9-VIZ-001 | Combo Chart Support | Add reusable multi-series chart support for compatible metrics, including shared range/frequency controls, legends, tooltips and dual-axis handling where appropriate. Preserve V8 one-metric-per-chart as the default. | WISHLIST |
| V4-FEAT-017 | AI Assistant | Introduce an AI-assisted StoX experience after earlier platform, safety, analytical-data and ML foundations are established. Architecture, tool/data access, explainability, interaction model and decision-authority boundaries require dedicated V9 deliberation. AI must not silently override deterministic Strategy behavior or bypass artifact/versioning and execution safeguards. The three assistance epics below refine this broad item into documentation Q&A, deterministic journey discovery and later agentic execution. | OPEN |
| V9-AI-001 | Documentation-Grounded StoX Chatbot | Add a read-only LLM chatbot that answers StoX “how do I?” and product-usage questions from the maintained documentation/user-journey corpus, for example “How do I create a screener?” or “How do I cancel a pending execution?”. Support configurable model providers: a low/no-cost Gemini path where available, configurable frontier-model providers, and an approved Codex/OpenAI path where technically/licensing-wise appropriate. Retrieval must ground answers in StoX documentation and expose relevant page/journey links. This phase explains and navigates only: it must not mutate StoX data, approve recommendations, or place trades. | WISHLIST |
| V9-AI-002 | Agentic StoX Assistant / MCP Action Layer | Extend the assistance experience from read-only guidance to explicitly authorized actions. Define a governed tool/action layer with typed contracts, permissions, validation, previews, confirmations, audit/provenance, idempotency and recovery. Preserve StoX deterministic strategy and execution safeguards. | WISHLIST |

## 3. Email notifications & account lifecycle messaging

Detailed requirements are frozen in [`V9-StoX-Email-Notifications-Account-Lifecycle-Messaging-Specification.md`](V9-StoX-Email-Notifications-Account-Lifecycle-Messaging-Specification.md).

## 4. Assistance roadmap relationship

The assistance epics are intentionally progressive rather than one monolithic chatbot project:

1. **V9-UX-001 — Automation readiness:** turn documented human journeys into stable, testable product workflows and build automated E2E coverage.
2. **V9-UX-002 — Deterministic discovery:** let users find a known “How do I?” journey quickly through typeahead/text matching and open the exact documentation section.
3. **V9-AI-001 — Conversational documentation:** let an LLM retrieve, synthesize and explain the same maintained StoX documentation without gaining write authority.
4. **V9-AI-002 — Agentic actions:** only after governed tool/action contracts exist, allow the assistant to perform explicitly authorized StoX operations.

Typeahead and chatbot may share the same maintained journey/question metadata, but deterministic typeahead must not depend on an LLM. The agentic phase may use the chatbot UX, but read-only retrieval and write/action authority must remain separable capabilities.

## 5. Inherited boundary

V8 `V4-FEAT-052` owns StoX OpenTelemetry instrumentation and export to the independently deployable LidoTelemetry product. StoX must not absorb LidoTelemetry storage, analytics, dashboards or platform administration into its own codebase.

Telemetry failure must not block StoX business workflows. StoX audit/business evidence remains distinct from telemetry.

AI/ML additions must preserve inherited deterministic, explainable Strategy behavior unless a later explicit product decision supersedes it.

Documentation-grounded AI must distinguish sourced StoX behavior from model-generated explanation. Model-provider configuration must not weaken authorization, privacy, audit or execution safeguards. Agentic capabilities must use explicit governed actions rather than unrestricted database mutation or arbitrary UI control for money-moving workflows.

Detailed V9 behavior will be frozen during V9 planning against the completed V7/V8 foundations and the then-current StoX implementation.
