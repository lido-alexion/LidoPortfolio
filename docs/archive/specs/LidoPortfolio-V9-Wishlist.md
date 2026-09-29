# LidoPortfolio / StoX V9 Wishlist

| Field | Value |
|---|---|
| **Document type** | Canonical V9 planning register |
| **Created** | 2026-09-09 |
| **Status** | **FROZEN / IMPLEMENTATION-READY** |
| **Canonical path** | `specs/LidoPortfolio-V9-Wishlist.md` |
| **Predecessor** | `specs/LidoPortfolio-V8-Wishlist.md` |
| **Final audit / sequence** | [`V9-Final-Cross-Spec-Audit-and-Implementation-Sequence.md`](V9-Final-Cross-Spec-Audit-and-Implementation-Sequence.md) |
| **AI technical architecture** | [`V9-AI-Technical-Architecture-Specification.md`](V9-AI-Technical-Architecture-Specification.md) — normative implementation blueprint for `V4-FEAT-017`, `V9-AI-001`, `V9-AI-002`, and `V9-AI-003` |

## 1. Purpose

V9 contains later StoX product expansion that follows the V7 analytical-data work and the V8 product/platform foundations.

StoX telemetry instrumentation and integration with the existing standalone LidoTelemetry product are owned by V8 `V4-FEAT-052`. V9 does not carry a separate StoX telemetry-integration epic; later telemetry-facing UX or analytics capabilities, if ever needed, must be registered explicitly as distinct future work rather than reopening FEAT-052 scope.

The V9 assistance roadmap also builds on the task-oriented user-journey corpus under `docs/user-journeys/`. The human-readable journeys remain useful independently; V9 can progressively expose the same knowledge through deterministic search, conversational assistance, automation-ready UI contracts, governed agentic actions, and contextual embedded AI insights.

The original registered V9 specification set passed the final cross-specification audit in [`V9-Final-Cross-Spec-Audit-and-Implementation-Sequence.md`](V9-Final-Cross-Spec-Audit-and-Implementation-Sequence.md). `V9-AI-003` was subsequently added as an explicitly frozen extension. Its dependency placement and implementation order are normative in this register and its own frozen specification. StoX V9 remains **IMPLEMENTATION-READY**.

For the AI implementation wave, [`V9-AI-Technical-Architecture-Specification.md`](V9-AI-Technical-Architecture-Specification.md) is a normative companion to all four AI epic specifications. It freezes the preferred PHP/Laravel + Python split, private HTTP/SSE inter-service contract, Laravel security/domain authority, Python inference/RAG/orchestration role, FastMCP/Pydantic choices, non-use of LangChain as the core orchestration framework, internal tool gateway, deployment topology, failure isolation, testing strategy, and prohibited shortcuts. The implementation agent must read this architecture specification together with `V4-FEAT-017`, `V9-AI-001`, `V9-AI-002`, and `V9-AI-003` before implementing AI scope.

## 2. Current V9 backlog

All registered V9 epics are frozen and implementation-ready. The table order remains the planning/register order; the dependency-aware execution sequence is defined in Section 5.

| ID | Feature | Scope / rationale | Status |
|---|---|---|---|
| V9-UX-001 | User Journey Automation Readiness & E2E Automation | Evolve the `docs/user-journeys/` human workflow corpus into an automation-ready contract. Review every in-scope journey against the authoritative golden/user journey, resolve all documented UX/product differences before automation, and implement Chromium E2E coverage with deterministic seeded data, key recovery/error paths, representative mobile coverage, targeted visual regression, lightweight accessibility checks, nightly regression, safe broker simulation, and non-destructive production smoke validation. Canonical frozen specification: [`V9-User-Journey-Automation-E2E-Specification.md`](V9-User-Journey-Automation-E2E-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-UX-002 | User Journey Typeahead / “How Do I?” Search | Add deterministic, non-LLM in-product help discovery through a global authenticated search entry and keyboard shortcut. Search authoritative journeys plus selected user-facing help using titles, aliases, keywords and synonyms; present concise complete textual steps directly in the result, material prerequisites/warnings, lightweight match explanations, safe page-navigation actions, alternative matches, deep-linkable/shareable help state, bounded recent history, light help-only personalization, feedback, and privacy-conscious diagnostics. Search discovery ignores permissions but normal StoX authorization still governs target routes/actions. Canonical frozen specification: [`V9-User-Journey-Typeahead-How-Do-I-Search-Specification.md`](V9-User-Journey-Typeahead-How-Do-I-Search-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-COMM-001 | StoX Email Notifications & Account Lifecycle Messaging | Add a canonical StoX notification-event model with in-app history and authenticated email delivery. Preserve the existing invitation generation/content behavior while automating delivery; add delivery state, retry/manual fallback, optional-email preferences, quiet hours/digests, critical product notifications, and a notification center. Canonical frozen specification: [`V9-StoX-Email-Notifications-Account-Lifecycle-Messaging-Specification.md`](V9-StoX-Email-Notifications-Account-Lifecycle-Messaging-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-OPS-001 | Historical Fundamentals Bootstrap Admin Operations | Extend the V8 FEAT-054 historical-fundamentals bootstrap engine with Admin-only operational control: full/targeted backfills, graceful cancellation, failed-item retry, forced reruns with preserved provenance, full recurring scheduling, shared presets, run history, coverage/gap management, data-quality warning workflows, targeted gap-driven reruns, operational notifications, and CSV export. Preserve the V8 ingestion engine and deterministic provider/quality rules. Canonical frozen specification: [`V9-Historical-Fundamentals-Bootstrap-Admin-Operations-Specification.md`](V9-Historical-Fundamentals-Bootstrap-Admin-Operations-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-DATA-001 | Data Export Framework | Add a reusable investor-facing export framework across StoX. Support CSV/XLSX export of tables, chart data and supported analytical datasets; explicit scope and field selection; raw precision and useful provenance; synchronous small exports and cancellable background large exports; 24-hour temporary artifacts; and a persistent account-private multi-dataset XLSX export basket. Enforce normal StoX authorization and explicit size/safety limits. Canonical frozen specification: [`V9-Data-Export-Framework-Specification.md`](V9-Data-Export-Framework-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-UX-003 | Customizable Summary Fields & Dashboard Layouts | Add bounded main-dashboard personalization: configurable summary fields, card visibility/order/size, separate desktop/mobile variants, local unnamed working layouts, server-saved named dashboards, JSON import/export, locking, default dashboards, and forward-compatible migration as cards/features evolve. Canonical frozen specification: [`V9-Customizable-Summary-Fields-Dashboard-Layouts-Specification.md`](V9-Customizable-Summary-Fields-Dashboard-Layouts-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-VIZ-001 | Combo Chart Support | Add a curated StoX-defined combo-chart library inside the existing Stock Details chart experience. Show one preset at a time with grouped dropdown selection plus cyclic previous/next arrows; reuse existing Price + Volume and Indices multi-series patterns; support in-chart range/sampling controls, preset-specific renderers/axes, synchronized tooltips, legend show/hide, per-stock availability, and one account-wide user-selected default with Price + Volume fallback. Canonical frozen specification: [`V9-Combo-Chart-Support-Specification.md`](V9-Combo-Chart-Support-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V4-FEAT-017 | AI Platform & Governance | Provide the shared StoX AI infrastructure consumed by focused AI features: code-defined capability registry, capability-specific ordered inference routing, common hosted/self-hosted provider adapters, streaming, structured outputs, failover/circuit breakers, live Admin provider/model configuration, full routing traces, selective prompt/response logging with retention controls, cost accounting and hierarchical budgets, prompt registry/versioning/governance, service classes, and concurrency controls. Preserve deterministic StoX Strategy/execution safeguards. Canonical frozen specification: [`V9-AI-Platform-Governance-Specification.md`](V9-AI-Platform-Governance-Specification.md). Normative technical architecture: [`V9-AI-Technical-Architecture-Specification.md`](V9-AI-Technical-Architecture-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-AI-001 | Documentation-Grounded StoX Chatbot | Add a read-only, user-journey-first StoX assistant for product help and explanations of analytics already visible to the user. Require grounded answers with source links/snippets and grounding-quality state; use safe current-page context and session-only conversation memory; provide a global assistant drawer, navigation-only deep links, contextual follow-up prompts, feedback, copy and clear-chat actions; refuse unsupported answers and preserve deterministic help fallback. Canonical frozen specification: [`V9-Documentation-Grounded-StoX-Chatbot-Specification.md`](V9-Documentation-Grounded-StoX-Chatbot-Specification.md). Technical implementation follows [`V9-AI-Technical-Architecture-Specification.md`](V9-AI-Technical-Architecture-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-AI-002 | Agentic StoX Assistant / MCP Action Layer | Extend the StoX assistant with governed, tool-augmented account reasoning and explicitly authorized actions. Add typed MCP-style read/mutation tools, deterministic policy mediation, bounded autonomous read-tool investigations, deterministic derived-analysis preference, concise user-visible tool traces, grouped mutation preview/approval, stale-state checks, idempotency, stop-on-partial-failure semantics, destructive-action safeguards, post-action verification, user-visible run history and fresh-plan retry. Keep broker trading, recurring autonomous mutations and external tools out of scope. Canonical frozen specification: [`V9-Agentic-StoX-Assistant-MCP-Action-Layer-Specification.md`](V9-Agentic-StoX-Assistant-MCP-Action-Layer-Specification.md). Technical implementation follows [`V9-AI-Technical-Architecture-Specification.md`](V9-AI-Technical-Architecture-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-AI-003 | Embedded AI Insights & Prompt Execution | Replace existing user-facing copy-to-clipboard AI prompt workflows with managed StoX AI execution while preserving Copy AI Prompt as degraded fallback. Add one shared stock-analysis insight capability across the seven audited placements, context-sensitive inline/right-pane/near-full-page-modal presentation, structured enriched stock insight, persistent fingerprint-based global-vs-held cache scoping, provenance, refresh/copy/navigation actions, V8 fundamental-insight reuse, and managed structured Strategy Designer generation with explicit AI-002-governed Create draft strategy. Canonical frozen specification: [`V9-Embedded-AI-Insights-Prompt-Execution-Specification.md`](V9-Embedded-AI-Insights-Prompt-Execution-Specification.md). Technical implementation follows [`V9-AI-Technical-Architecture-Specification.md`](V9-AI-Technical-Architecture-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |

## 3. Email notifications & account lifecycle messaging

Detailed requirements are frozen in [`V9-StoX-Email-Notifications-Account-Lifecycle-Messaging-Specification.md`](V9-StoX-Email-Notifications-Account-Lifecycle-Messaging-Specification.md).

## 4. Assistance roadmap relationship

The assistance epics are intentionally progressive rather than one monolithic chatbot project:

1. **V9-UX-001 — Automation readiness:** turn documented human journeys into stable, testable product workflows and build automated E2E coverage.
2. **V9-UX-002 — Deterministic discovery:** let users find a known “How do I?” journey quickly through typeahead/text matching and open the exact documentation section.
3. **V9-AI-001 — Conversational documentation:** let an LLM retrieve, synthesize and explain the same maintained StoX documentation without gaining write authority.
4. **V9-AI-002 — Tool-augmented reasoning and agentic actions:** allow account-specific read reasoning through governed tools and explicitly authorized StoX mutations, while preserving deterministic computation and execution safeguards.
5. **V9-AI-003 — Embedded contextual AI:** execute existing product-specific AI prompts through the shared AI platform, render structured insight directly where users already work, cache results safely, and retain copy-prompt fallback when managed AI is unavailable.

Typeahead and chatbot may share the same maintained journey/question metadata, but deterministic typeahead must not depend on an LLM. V9-AI-002 may extend the chatbot UX with governed read and mutation tools, but read-only investigation and write/action authority must remain separable capabilities. V9-AI-003 reuses the same shared inference platform and uses V9-AI-002 rather than creating a second mutation framework for Strategy draft creation.

## 5. Frozen implementation sequence

The original dependency graph and sequencing rationale remain authoritative for the previously audited epics in [`V9-Final-Cross-Spec-Audit-and-Implementation-Sequence.md`](V9-Final-Cross-Spec-Audit-and-Implementation-Sequence.md). The subsequently frozen `V9-AI-003` extension is inserted before final V9-UX-001 closure as follows.

Recommended release sequence:

1. **V9-UX-001 foundation slice** — journey governance, conformance workflow, automation harness, deterministic test-data and CI conventions. Keep the epic open.
2. **V9-COMM-001** — canonical notification framework and account-lifecycle messaging.
3. **V9-DATA-001** — shared export framework.
4. **Deterministic/user-facing feature wave** — `V9-UX-002`, `V9-OPS-001`, `V9-UX-003`, and `V9-VIZ-001`, parallelized where dependencies permit.
5. **V4-FEAT-017** — shared AI platform/governance, implemented according to the frozen [`V9-AI-Technical-Architecture-Specification.md`](V9-AI-Technical-Architecture-Specification.md).
6. **V9-AI-001** — documentation-grounded chatbot using the shared Python AI runtime/RAG architecture.
7. **V9-AI-002** — tool-augmented reasoning and governed actions using FastMCP and the private Laravel agent-tool gateway described by the technical architecture.
8. **V9-AI-003** — embedded stock insights and managed Strategy Designer prompt execution, reusing the shared platform and AI-002 mutation path for Create draft strategy.
9. **V9-UX-001 final closure** — final journey reconciliation/E2E coverage across the completed V9 product, including V9-AI-003 journeys, followed by release audit.

Implementation may parallelize independent AI-003 read-only/cache/UI groundwork after V4-FEAT-017 is stable, but V9-AI-003 is not complete until its Strategy draft-creation path integrates with V9-AI-002 and all acceptance criteria pass.

## 6. Inherited boundary

V8 `V4-FEAT-052` owns StoX OpenTelemetry instrumentation and export to the independently deployable LidoTelemetry product. StoX must not absorb LidoTelemetry storage, analytics, dashboards or platform administration into its own codebase.

Telemetry failure must not block StoX business workflows. StoX audit/business evidence remains distinct from telemetry.

AI/ML additions must preserve inherited deterministic, explainable Strategy behavior unless a later explicit product decision supersedes it.

Documentation-grounded AI must distinguish sourced StoX behavior from model-generated explanation. Model-provider configuration must not weaken authorization, privacy, audit or execution safeguards. Agentic capabilities must use explicit governed actions rather than unrestricted database mutation or arbitrary UI control for money-moving workflows.

Embedded AI insights must preserve deterministic StoX facts as authoritative evidence, keep account-specific holding context out of global caches, and preserve existing copy-prompt affordances as degraded recovery when managed AI is unavailable.

## 7. Implementation-ready declaration

The previously audited V9 scope had no unresolved product-level contradiction or PO decision. The subsequently added `V9-AI-003` epic has now been separately product-defined and frozen with its dependencies, cache/privacy boundaries, UX behavior, fallback semantics, testing requirements, and acceptance criteria resolved.

Normative V9 reconciliations include:

- UX-001 starts first as a foundation but closes last as the release-level journey/E2E gate;
- V9-COMM-001 is the shared notification framework for dependent epics;
- user-specific AI budget/path exhaustion removes only the affected path for that user while preserving the single canonical capability order;
- AI-002 intentionally extends AI-001 from visible-page explanation to governed account-data reasoning;
- AI-003 consumes the same shared platform and reuses AI-002 for Strategy draft mutation rather than creating parallel AI/mutation infrastructure;
- deterministic calculations remain in StoX services rather than being delegated to the LLM;
- AI-003 reuses current valid V8 fundamental AI interpretation instead of duplicating the fundamental AI engine;
- AI-003 global stock caches contain no private holding/watchlist context; held-stock insights are active-portfolio/account scoped;
- OPS operational export and VIZ chart-data export reuse the shared DATA framework where practical;
- V8 telemetry and historical-fundamentals ownership boundaries remain intact;
- the AI implementation uses the frozen Laravel + Python architecture defined in `V9-AI-Technical-Architecture-Specification.md` rather than leaving core technology/service boundaries to implementation inference.

**StoX V9 is hereby declared FROZEN / IMPLEMENTATION-READY, including `V9-AI-003`.**

The implementation agent may begin automatically from this register, the linked frozen specifications, the AI technical architecture, and the final audit/sequence document as qualified by the AI-003 insertion above. No additional planning handoff is required unless implementation discovers a genuinely new material product decision or a direct frozen-spec contradiction.
