# LidoPortfolio / StoX V9 Wishlist

| Field | Value |
|---|---|
| **Document type** | Canonical V9 planning register |
| **Created** | 2026-09-09 |
| **Status** | Active planning register; each epic status is recorded in the table below. |
| **Canonical path** | `docs/archive/specs/LidoPortfolio-V9-Wishlist.md` |
| **Predecessor** | `docs/archive/specs/LidoPortfolio-V8-Wishlist.md` |
| **Final audit / sequence** | [`V9-Final-Cross-Spec-Audit-and-Implementation-Sequence.md`](V9-Final-Cross-Spec-Audit-and-Implementation-Sequence.md) |
| **AI technical architecture** | [`V9-AI-Technical-Architecture-Specification.md`](V9-AI-Technical-Architecture-Specification.md) |

## 1. Purpose

V9 contains later StoX product expansion following the V7 analytical-data work and V8 product/platform foundations.

V8 `V4-FEAT-052` remains the owner of StoX OpenTelemetry integration with the independently deployable LidoTelemetry product. V9 does not duplicate that telemetry scope.

V9 assistance builds progressively from user-journey governance and deterministic help through documentation-grounded AI, governed agentic actions, and contextual embedded AI insights.

V9-DATA-002 historical-data delivery was retired on 2026-10-06 and is no longer part of the active V9 roadmap.

V9 operational reliability includes centralized automatic GitHub issue creation for unexpected API failures through `V9-OPS-002`, plus AI-assisted ERROR-log triage through `V9-OPS-003`. OPS-003 reuses the same governed V4-FEAT-017 AI platform and the same GitHub issue/deduplication infrastructure rather than introducing separate model or GitHub integrations.

V9 also adds `V9-UX-004`, a guided, educational wizard over the existing V8 production ML acceptance workflow. It improves sequencing, recovery and explanation without changing V8 data, training, candidate, promotion or lifecycle authority.

## 2. Current V9 backlog

V9 epics are frozen for implementation unless a row is explicitly marked **PO REVIEW** or lists a dependency gate.

Before starting implementation, mark the epic **IMPLEMENTATION IN PROGRESS** and record its owning thread in this register. Keep that status while implementation or acceptance work remains open, including external blockers. Parallel threads must not pick up an epic already in progress; coordinate with its existing owner. Update the register when ownership or completion status changes.

| ID | Feature | Scope / rationale | Status |
|---|---|---|---|
| V9-UX-001 | User Journey Automation Readiness & E2E Automation | Turn `docs/user-journeys/` into an automation-ready contract; reconcile documented/product UX, implement deterministic Chromium E2E coverage, recovery/error paths, representative mobile coverage, targeted visual regression, lightweight accessibility checks, nightly regression, safe broker simulation and non-destructive production smoke validation. Canonical spec: [`V9-User-Journey-Automation-E2E-Specification.md`](V9-User-Journey-Automation-E2E-Specification.md). | **IMPLEMENTED / VERIFIED** — 22 October 2026: agreed Investor journeys and all 16 frozen completion criteria accepted; PR220 copy/feedback qualification, healthy build1521/public+AUTH smoke, ordinary nightly and authorized non-destructive real-Kite receipt recorded. See [implementation audit](../../audits/V9-UX-001-implementation-audit.md) and [finite acceptance ledger](../../audits/V9-UX-001-finite-acceptance-ledger.md). |
| V9-UX-002 | User Journey Typeahead / “How Do I?” Search | Deterministic non-LLM authenticated help discovery over authoritative journeys and selected help; concise complete steps, prerequisites/warnings, match explanation, safe navigation, alternatives, deep-linkable state, bounded history and diagnostics. Canonical spec: [`V9-User-Journey-Typeahead-How-Do-I-Search-Specification.md`](V9-User-Journey-Typeahead-How-Do-I-Search-Specification.md). | **IMPLEMENTED / VERIFIED** — Backend CI: 2,258 passed, 1 skipped / 15,437 assertions; focused feedback/auth: 2 passed / 10 assertions; frontend: 204 Node + 152 Vitest + 52 node-unit tests, typecheck/build passed; browser: 59 passed, 56 expected skips, 0 failures, focused mobile/desktop passed; migration portability, static docs, OpenAPI and diff checks passed. See [implementation audit](../../audits/V9-UX-002-implementation-audit.md). |
| V9-COMM-001 | StoX Email Notifications & Account Lifecycle Messaging | Canonical notification-event model, in-app history, authenticated email delivery, retry/fallback, preferences, quiet hours/digests, account lifecycle messaging and notification center. Beta access-request resilience makes email verification advisory for Admin approval and preserves manual invitation fallback. Canonical spec: [`V9-StoX-Email-Notifications-Account-Lifecycle-Messaging-Specification.md`](V9-StoX-Email-Notifications-Account-Lifecycle-Messaging-Specification.md). Companion: [`V9-Account-Access-Request-Email-Resilience-Specification.md`](V9-Account-Access-Request-Email-Resilience-Specification.md). | **IMPLEMENTED / VERIFIED** — Backend CI: 2,242 tests (2,241 passed, 1 skipped; 14,964 assertions); frontend: 202 Node + 145 Vitest + 52 node-unit tests; typecheck/build, two Playwright journeys, migration portability, static docs, OpenAPI passed. See [implementation audit](../../audits/V9-COMM-001-implementation-audit.md). |
| V9-OPS-001 | Historical Fundamentals Bootstrap Admin Operations | Admin control over the V8 FEAT-054 fundamentals bootstrap engine: full/targeted backfills, cancellation, retry, reruns, scheduling, history, coverage/gap operations, data-quality workflows, notifications and export while preserving deterministic provider/quality rules. Canonical spec: [`V9-Historical-Fundamentals-Bootstrap-Admin-Operations-Specification.md`](V9-Historical-Fundamentals-Bootstrap-Admin-Operations-Specification.md). | **IMPLEMENTED / VERIFIED — 23 October 2026.** Root accepted all 18 frozen §17 criteria. PR257 merged; exact master `24bae26435efb586455e9a0f4e1516f25b1eb138` released as build1544 through normal CI1544: 2,444 PHP tests / 17,540 assertions, 618 component tests, 488 browser cases and mandatory authenticated smoke 2/2, first attempt. [Canonical implementation/closure audit](../../current/V9-OPS-001-IMPLEMENTATION-AUDIT.md). Existing V8 engine, provider/quality/provenance rules and explicit Admin authority preserved. |
| V9-OPS-002 | Automated API Failure GitHub Issue Reporting | Centralized operational reporting for unexpected backend external-API and supported frontend StoX-API failures. Normalize/classify failures, create deterministic low-cardinality fingerprints, persist local occurrences, reconcile duplicate open GitHub issues through stable fingerprint markers, create new recurrence generations after cooldown, strictly redact secrets/PII, and keep GitHub reporting asynchronous/fail-open. Canonical spec: [`V9-OPS-002-Automated-API-Failure-GitHub-Issue-Reporting-Specification.md`](V9-OPS-002-Automated-API-Failure-GitHub-Issue-Reporting-Specification.md). | **IMPLEMENTED / VERIFIED** — OPS-002 + shared reporter: 22 MySQL tests / 114 assertions; frontend: 202 node tests + 142 Vitest tests; typecheck, build, migrations, static docs and OpenAPI passed. See [acceptance audit](../../audits/V9-OPS-002-implementation-audit.md) |
| V9-OPS-003 | LLM Log Error Triage & GitHub Issue Reporting | Observe centralized ERROR-level application logs, sanitize and deduplicate them, classify them through a dedicated `ops.log_error_triage` capability on the shared V4-FEAT-017 AI platform, and create deduplicated GitHub issues only for sufficiently confident actionable `code_bug` classifications. Reuse OPS-002 GitHub adapter, credential, rate limits and marker-based duplicate reconciliation. Canonical spec: [`V9-OPS-003-LLM-Log-Error-Triage-GitHub-Issue-Reporting-Specification.md`](V9-OPS-003-LLM-Log-Error-Triage-GitHub-Issue-Reporting-Specification.md). | **IMPLEMENTED / VERIFIED** |
| V9-OPS-004 | CI Watcher Recovery & Release Runner Diagnostics | Supported watcher configuration lifecycle, truthful reviewer startup/waiting states and safe owned recovery, runner-to-VPS SSH diagnostics and normal-release recovery evidence. Separate operational follow-up from UX-001; preserve all existing production and approval boundaries. Canonical spec: [`V9-OPS-004-CI-Watcher-Recovery-Release-Runner-Diagnostics-Specification.md`](V9-OPS-004-CI-Watcher-Recovery-Release-Runner-Diagnostics-Specification.md). Tracking: [#251](https://github.com/lido-alexion/LidoPortfolio/issues/251). | **IMPLEMENTED / VERIFIED — 23 October 2026.** Root accepted all frozen AC01–AC12. PR261/263 source and actual watcher lifecycle/reviewer recovery receipts qualified; normal [CI1554](https://github.com/lido-alexion/LidoPortfolio/actions/runs/39971543972) attempt1 released exact master `4e223f6f805ae959eedd04c454af6890ebdcf8b7` as build1554. Full relevant tests, independent artifact hashes, strict SSH/current-master activation, exact runtime/public health and mandatory authenticated smoke2/2 passed. [Canonical closure audit](../../audits/V9-OPS-004-implementation-audit.md) and [release evidence](../../audits/V9-OPS-004-release-runner-evidence-index.json). Earlier SSH cause remains explicitly UNKNOWN; bounded ordinary recovery satisfies the frozen criteria. Existing production/approval boundaries and other epic closures preserved. |
| V9-DATA-001 | Data Export Framework | Reusable investor-facing CSV/XLSX export for tables, charts and analytical datasets with explicit scope/field selection, provenance, synchronous small exports, cancellable large exports, temporary artifacts and persistent account-private export basket. Canonical spec: [`V9-Data-Export-Framework-Specification.md`](V9-Data-Export-Framework-Specification.md). | **IMPLEMENTED / VERIFIED** |
| V9-DATA-003 | Forward Data Collection, Recovery & Readiness | Campaign-independent official NSE evidence, fair fundamentals polling, sourced effective-dated sectors, corporate-action feed operation, daily-price recovery and shared dataset freshness/coverage/backlog monitoring. Reuse domain engines; preserve PIT provenance and training/promotion gates. Canonical spec: [`V9-DATA-003-Forward-Data-Collection-Recovery-Readiness-Specification.md`](V9-DATA-003-Forward-Data-Collection-Recovery-Readiness-Specification.md). | **IMPLEMENTED** — implementation and in-control fixes are complete; outstanding live provider/data/authorization evidence is detailed as follow-up investigation in the canonical spec. This status does not claim all production datasets are complete. |
| V9-UX-003 | Customizable Summary Fields & Dashboard Layouts | Configurable summary fields, card visibility/order/size, desktop/mobile variants, local working layouts, named dashboards, import/export, locking, defaults and forward-compatible migration. Canonical spec: [`V9-Customizable-Summary-Fields-Dashboard-Layouts-Specification.md`](V9-Customizable-Summary-Fields-Dashboard-Layouts-Specification.md). | **IMPLEMENTED / VERIFIED** — Owner: remaining V9 epics orchestration thread. PR #170 merged normally at `51fec37be88399275948a74491dfef7350a61a30`; exact-head PR CI #1175 (`38701307615`) and master CI #1176 (`38703908998`) passed, including MySQL/PHPUnit, OpenAPI, frontend/browser and Python contracts. Serialized production Deploy #549 (`38807937333`) activated descendant `805adcee157fd5081fa2d2aa981c021028e3c9fa`, passed exact-SHA runtime health and authenticated read-only smoke (2/2), with evidence artifact `12007112526`. Post-merge master CI #1196 (`38807937231`) passed on that deployed SHA; latest master `1e47c3fa` contains it. The former `combo_chart` blocker recorded for Deploy #541 was resolved in the VIZ-001 delivery; see [implementation audit](../../audits/V9-UX-003-implementation-audit.md). |
| V9-UX-004 | Guided Production ML Acceptance Wizard | Replace the technical all-in-one Production ML acceptance panel with a recoverable seven-step wizard covering readiness, discovery preflight, dated NSE sources, preview/apply backfill, final preflight, explicit 1m/3m/6m candidate training and completion review. Include deterministic plain-language education, accessible tooltips/info help, blocker-to-action guidance, asynchronous recovery and Advanced diagnostics while preserving V8 ownership and explicit promotion/lifecycle decisions. Canonical spec: [`V9-UX-004-ML-Acceptance-Guided-Wizard-Specification.md`](V9-UX-004-ML-Acceptance-Guided-Wizard-Specification.md). | **IMPLEMENTED / VERIFIED — 22 October 2026.** Root accepted all20 frozen §18 criteria. PR186 merged; exact master `554c7d60453b4313d4450206240155820b2371da` released as build1531 through normal CI1531, with482 browser/600 component/2378 PHP tests and authenticated smoke2/2. [Canonical implementation/closure audit](../../current/V9-UX-004-IMPLEMENTATION-AUDIT.md). Existing training, promotion and lifecycle authority preserved. |
| V9-VIZ-001 | Combo Chart Support | Curated StoX-defined combo-chart library in Stock Details with preset navigation, reusable chart patterns, range/sampling controls, preset axes/renderers, synchronized tooltips, legend controls, per-stock availability and account-wide default/fallback. Canonical spec: [`V9-Combo-Chart-Support-Specification.md`](V9-Combo-Chart-Support-Specification.md). | **IMPLEMENTED** — AC1–AC16 are audited against merged implementation and regression evidence in the [closure audit](../../audits/V9-VIZ-001-implementation-audit.md). PR #182 merged normally as `805adcee157fd5081fa2d2aa981c021028e3c9fa` after exact-head CI1195 (`38801907328`) passed. Serialized build549 (`38807937333`) deployed that exact SHA, passed exact-SHA runtime health, and passed authenticated read-only VIZ smoke; evidence artifact `12007112526`. Post-merge master CI1196 (`38807937231`) passed on the same SHA at 2026-10-15 21:27 UTC. See the closure audit for the full AC evidence. |
| V4-FEAT-017 | AI Platform & Governance | Shared StoX AI infrastructure: capability registry, ordered inference routing, provider adapters, streaming, structured outputs, failover/circuit breakers, Admin provider/model configuration, traces, prompt/response governance, cost/budgets, prompt registry/versioning and concurrency controls. Canonical spec: [`V9-AI-Platform-Governance-Specification.md`](V9-AI-Platform-Governance-Specification.md). Normative architecture: [`V9-AI-Technical-Architecture-Specification.md`](V9-AI-Technical-Architecture-Specification.md). | **IMPLEMENTED / VERIFIED** |
| V9-AI-001 | Documentation-Grounded StoX Chatbot | Read-only journey-first StoX assistant for product help/explanation with grounded sources, safe page context, session-only memory, navigation links, follow-ups, feedback/copy/clear and deterministic fallback/refusal. Canonical spec: [`V9-Documentation-Grounded-StoX-Chatbot-Specification.md`](V9-Documentation-Grounded-StoX-Chatbot-Specification.md). | **IMPLEMENTED / VERIFIED** |
| V9-AI-002 | Agentic StoX Assistant / MCP Action Layer | Governed typed read/mutation tools, deterministic policy mediation, bounded autonomous read investigations, mutation preview/approval, stale-state checks, idempotency, partial-failure rules, destructive safeguards, post-action verification and run history. Broker trading, recurring autonomous mutations and external tools remain out of scope. Canonical spec: [`V9-Agentic-StoX-Assistant-MCP-Action-Layer-Specification.md`](V9-Agentic-StoX-Assistant-MCP-Action-Layer-Specification.md). | **IMPLEMENTED / VERIFIED** |
| V9-AI-003 | Embedded AI Insights & Prompt Execution | Replace legacy copy-prompt AI affordances with managed embedded AI execution while preserving Copy AI Prompt as degraded fallback. Shared stock insight, context-sensitive presentation, structured enrichment, persistent fingerprint cache, provenance, refresh/copy/navigation, V8 fundamental-insight reuse and structured Strategy Designer with AI-002-governed draft creation. Canonical spec: [`V9-Embedded-AI-Insights-Prompt-Execution-Specification.md`](V9-Embedded-AI-Insights-Prompt-Execution-Specification.md). | **IMPLEMENTED / VERIFIED** |

### 2.1 Implementation progress snapshot

As of 2026-10-03, the shared AI platform and the complete user-facing V9 AI sequence are implemented and verified on `master`:

- `V4-FEAT-017` — shared AI platform/governance implemented and hardened;
- `V9-AI-001` — documentation-grounded assistant implemented;
- `V9-AI-002` — governed MCP/tool action layer implemented;
- `V9-AI-003` — embedded stock insights and managed Strategy Designer implemented.

AI-003 is recorded by commit `40d89d3b`; earlier AI implementation evidence is retained in the corresponding audit/spec documentation. Operational capability routes still require normal Admin provider-path configuration where applicable.

V9-DATA-003 is an early data reliability priority. Reconcile any forward-data remediation already underway into its evidence and shared adapters; do not duplicate ingestion engines.

## 3. Key inherited boundaries

### Telemetry and operational incident reporting

V8 `V4-FEAT-052` owns StoX telemetry instrumentation/export. StoX must not absorb LidoTelemetry storage, analytics, dashboards or platform administration.

`V9-OPS-002` is deterministic operational work tracking for unexpected API failures. It may reuse safe trace/correlation IDs and emit reporting telemetry through the existing FEAT-052 abstraction, but it does not replace telemetry and must not require telemetry availability to create/reconcile GitHub incidents.

`V9-OPS-003` adds AI-assisted triage of ERROR-level logs. It must use the V4-FEAT-017 capability/routing/prompt-governance path and reuse OPS-002's GitHub transport/deduplication primitives. It must not send raw logs or sensitive context to either the model or GitHub, and it must not create a parallel AI or observability subsystem.

### AI

AI implementation follows [`V9-AI-Technical-Architecture-Specification.md`](V9-AI-Technical-Architecture-Specification.md): Laravel remains authoritative for auth/domain/business writes; Python owns inference/RAG/orchestration; browser never calls Python directly; Python never directly accesses StoX MariaDB; FastMCP tools route through governed Laravel services; deterministic StoX calculations remain authoritative.

`V9-OPS-003` is an operational consumer of the same AI platform. It may begin once the V4-FEAT-017 core capability registry, routing/adapters, structured-output validation, prompt registry, budgets/concurrency and failure isolation are stable; it does not need to wait for the user-facing AI epics.

## 4. Assistance roadmap relationship

1. **V9-UX-001** — automation-ready journey foundation.
2. **V9-UX-002** — deterministic help discovery.
3. **V9-AI-001** — documentation-grounded conversational assistance.
4. **V9-AI-002** — governed account-data reasoning/actions.
5. **V9-AI-003** — contextual embedded AI in existing product surfaces.

Typeahead remains independent of LLM availability. AI-002 extends AI-001 with governed account tools. AI-003 reuses the same AI platform and AI-002 mutation path rather than creating a second action framework. Operational AI capability `ops.log_error_triage` is separate from this user-facing assistance progression and may be implemented as soon as the required V4-FEAT-017 core is available.

## 5. Frozen implementation sequence

Dependency-aware release sequence:

1. **V9-UX-001 foundation slice** — journey governance, conformance workflow, automation harness, deterministic test-data and CI conventions. Keep open until final closure.
2. **Shared non-AI infrastructure** — `V9-COMM-001` notification framework/account lifecycle messaging and `V9-OPS-002` automated API-failure incident reporting may proceed independently/parallel where safe. OPS-002 remains additive and must not rewrite protected V8 provider/domain semantics during V8 closure.
3. **Data-platform wave** — prioritize `V9-DATA-003` collection/recovery foundations and production daily-data adapters; Admin fundamentals operations reuse the corrected FEAT-054 engine. V9-DATA-001 may proceed independently.
4. **Deterministic/user-facing feature wave** — `V9-UX-002`, `V9-OPS-001`, `V9-UX-003`, `V9-UX-004`, `V9-VIZ-001`. UX-004 depends on the implemented V8 production-acceptance domain and must preserve its server-authoritative gates.
5. **V4-FEAT-017 core** — shared AI capability registry, provider adapters/routing, structured outputs, prompt registry/governance, budgets/concurrency and failure isolation.
6. **V9-OPS-003** — register `ops.log_error_triage`, add centralized sanitized ERROR-log triage, bounded inference/deduplication and high-confidence code-bug GitHub issue generation using OPS-002 infrastructure. This can proceed in parallel with later user-facing AI work once the V4-FEAT-017 core gate is satisfied.
7. **V9-AI-001** — documentation-grounded chatbot.
8. **V9-AI-002** — governed agentic action layer.
9. **V9-AI-003** — embedded AI insights and managed Strategy Designer execution.
10. **V9-UX-001 final closure** — final journey reconciliation/E2E coverage across the completed V9 product, then release audit.

Independent implementation may be parallelized, but hard dependencies, protected V8 closure boundaries and cross-repo protocol compatibility must be preserved.

## 7. Implementation-ready declaration

The previously audited V9 scope had no unresolved product-level contradiction. Subsequent additions have now been separately product-defined and frozen:

- `V9-AI-003` — Embedded AI Insights & Prompt Execution;
- `V9-COMM-001` beta access-request email resilience companion;
- `V9-OPS-002` — Automated API Failure GitHub Issue Reporting;
- `V9-OPS-003` — LLM Log Error Triage & GitHub Issue Reporting;
- `V9-UX-004` — Guided Production ML Acceptance Wizard;
- `V9-DATA-003` — Forward Data Collection, Recovery & Readiness.

Normative reconciliations include:

- UX-001 starts first as a foundation but closes last as the release-level journey/E2E gate;
- COMM-001 remains the shared notification framework;
- OPS-002 is an additive deterministic operational reliability layer, separate from FEAT-052 telemetry and COMM-001 notification delivery;
- OPS-002 default success semantics are 2xx, with policy-based expected non-2xx exclusion, local + GitHub-marker deduplication, strict redaction and fail-open queued GitHub integration;
- OPS-003 consumes ERROR logs asynchronously, sanitizes before inference, uses only the governed `ops.log_error_triage` capability on V4-FEAT-017, and automatically creates an issue only for actionable `code_bug` classifications meeting the default 0.85 confidence/evidence gates;
- OPS-003 reuses OPS-002 GitHub credentials/adapter/rate limits/deduplication rather than creating a second integration, and its own AI/GitHub failures are excluded from recursive triage;
- AI-002 extends AI-001 from visible-page explanation to governed account-data reasoning;
- AI-003 consumes the same shared platform and reuses AI-002 for Strategy draft mutation;
- UX-004 is a deterministic educational/orchestration layer over V8 production acceptance and does not change training, qualification, promotion or lifecycle rules;
- deterministic calculations remain in StoX services rather than being delegated to LLMs;
- V8 telemetry ownership remains intact.

**V9-DATA-002 is retired (2026-10-06) and removed from the active register. V9-DATA-003 is IMPLEMENTED, with external operational investigations retained in its canonical spec; V9-AI-003 and V9-UX-004 retain their existing statuses. V9-OPS-002 and V9-OPS-003 are IMPLEMENTED / VERIFIED.**

The implementation agent may begin automatically from this register and its linked frozen specifications, subject to each active row's listed dependency gates. Retired V9-DATA-002/SKR-001 references are historical only; the linked Windows companion project is outside this repository's active scope.
