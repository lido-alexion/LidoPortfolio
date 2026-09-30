# LidoPortfolio / StoX V9 Wishlist

| Field | Value |
|---|---|
| **Document type** | Canonical V9 planning register |
| **Created** | 2026-09-09 |
| **Status** | **FROZEN / IMPLEMENTATION-READY** |
| **Canonical path** | `docs/archive/specs/LidoPortfolio-V9-Wishlist.md` |
| **Predecessor** | `docs/archive/specs/LidoPortfolio-V8-Wishlist.md` |
| **Final audit / sequence** | [`V9-Final-Cross-Spec-Audit-and-Implementation-Sequence.md`](V9-Final-Cross-Spec-Audit-and-Implementation-Sequence.md) |
| **AI technical architecture** | [`V9-AI-Technical-Architecture-Specification.md`](V9-AI-Technical-Architecture-Specification.md) |

## 1. Purpose

V9 contains later StoX product expansion following the V7 analytical-data work and V8 product/platform foundations.

V8 `V4-FEAT-052` remains the owner of StoX OpenTelemetry integration with the independently deployable LidoTelemetry product. V9 does not duplicate that telemetry scope.

V9 assistance builds progressively from user-journey governance and deterministic help through documentation-grounded AI, governed agentic actions, and contextual embedded AI insights.

V9 historical-data delivery extends the frozen V8 `V4-FEAT-065` historical-data platform through `V9-DATA-002`; FEAT-065 remains authoritative for canonical corpus semantics unless V9-DATA-002 explicitly overrides a product choice.

V9 operational reliability includes centralized automatic GitHub issue creation for unexpected API failures through `V9-OPS-002`, plus AI-assisted ERROR-log triage through `V9-OPS-003`. OPS-003 reuses the same governed V4-FEAT-017 AI platform and the same GitHub issue/deduplication infrastructure rather than introducing separate model or GitHub integrations.

## 2. Current V9 backlog

All registered V9 epics below are **FROZEN / IMPLEMENTATION-READY** subject to their explicit dependency gates.

| ID | Feature | Scope / rationale | Status |
|---|---|---|---|
| V9-UX-001 | User Journey Automation Readiness & E2E Automation | Turn `docs/user-journeys/` into an automation-ready contract; reconcile documented/product UX, implement deterministic Chromium E2E coverage, recovery/error paths, representative mobile coverage, targeted visual regression, lightweight accessibility checks, nightly regression, safe broker simulation and non-destructive production smoke validation. Canonical spec: [`V9-User-Journey-Automation-E2E-Specification.md`](V9-User-Journey-Automation-E2E-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-UX-002 | User Journey Typeahead / “How Do I?” Search | Deterministic non-LLM authenticated help discovery over authoritative journeys and selected help; concise complete steps, prerequisites/warnings, match explanation, safe navigation, alternatives, deep-linkable state, bounded history and diagnostics. Canonical spec: [`V9-User-Journey-Typeahead-How-Do-I-Search-Specification.md`](V9-User-Journey-Typeahead-How-Do-I-Search-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-COMM-001 | StoX Email Notifications & Account Lifecycle Messaging | Canonical notification-event model, in-app history, authenticated email delivery, retry/fallback, preferences, quiet hours/digests, account lifecycle messaging and notification center. Beta access-request resilience makes email verification advisory for Admin approval and preserves manual invitation fallback. Canonical spec: [`V9-StoX-Email-Notifications-Account-Lifecycle-Messaging-Specification.md`](V9-StoX-Email-Notifications-Account-Lifecycle-Messaging-Specification.md). Companion: [`V9-Account-Access-Request-Email-Resilience-Specification.md`](V9-Account-Access-Request-Email-Resilience-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-OPS-001 | Historical Fundamentals Bootstrap Admin Operations | Admin control over the V8 FEAT-054 fundamentals bootstrap engine: full/targeted backfills, cancellation, retry, reruns, scheduling, history, coverage/gap operations, data-quality workflows, notifications and export while preserving deterministic provider/quality rules. Canonical spec: [`V9-Historical-Fundamentals-Bootstrap-Admin-Operations-Specification.md`](V9-Historical-Fundamentals-Bootstrap-Admin-Operations-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-OPS-002 | Automated API Failure GitHub Issue Reporting | Centralized operational reporting for unexpected backend external-API and supported frontend StoX-API failures. Normalize/classify failures, create deterministic low-cardinality fingerprints, persist local occurrences, reconcile duplicate open GitHub issues through stable fingerprint markers, create new recurrence generations after cooldown, strictly redact secrets/PII, and keep GitHub reporting asynchronous/fail-open. Canonical spec: [`V9-OPS-002-Automated-API-Failure-GitHub-Issue-Reporting-Specification.md`](V9-OPS-002-Automated-API-Failure-GitHub-Issue-Reporting-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-OPS-003 | LLM Log Error Triage & GitHub Issue Reporting | Observe centralized ERROR-level application logs, sanitize and deduplicate them, classify them through a dedicated `ops.log_error_triage` capability on the shared V4-FEAT-017 AI platform, and create deduplicated GitHub issues only for sufficiently confident actionable `code_bug` classifications. Reuse OPS-002 GitHub adapter, credential, rate limits and marker-based duplicate reconciliation. Canonical spec: [`V9-OPS-003-LLM-Log-Error-Triage-GitHub-Issue-Reporting-Specification.md`](V9-OPS-003-LLM-Log-Error-Triage-GitHub-Issue-Reporting-Specification.md). | **FROZEN / IMPLEMENTATION-READY AFTER V4-FEAT-017 CORE** |
| V9-DATA-001 | Data Export Framework | Reusable investor-facing CSV/XLSX export for tables, charts and analytical datasets with explicit scope/field selection, provenance, synchronous small exports, cancellable large exports, temporary artifacts and persistent account-private export basket. Canonical spec: [`V9-Data-Export-Framework-Specification.md`](V9-Data-Export-Framework-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-DATA-002 | VPS Historical Data Staging and Delivery | Extend V8 FEAT-065 so the whitelisted StoX VPS acquires maximum provider-available Kite 1-minute OHLCV, validates/seals immutable one-trading-day batches, stages them under bounded retention/quota rules, and exposes authenticated Mac-initiated HTTPS discovery/range-download/lease/acknowledgment. Mac remains canonical. Companion StoX-Kite-Rain app handles network-gated automatic transfer and atomic local import. Canonical spec: [`V9-DATA-002-VPS-Historical-Data-Staging-and-Delivery-Specification.md`](V9-DATA-002-VPS-Historical-Data-Staging-and-Delivery-Specification.md). Companion spec: [SKR-001 StoX-Kite-Rain](https://github.com/lido-alexion/StoX-Kite-Rain/blob/main/docs/SK-001-MacOS-Downloader-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-UX-003 | Customizable Summary Fields & Dashboard Layouts | Configurable summary fields, card visibility/order/size, desktop/mobile variants, local working layouts, named dashboards, import/export, locking, defaults and forward-compatible migration. Canonical spec: [`V9-Customizable-Summary-Fields-Dashboard-Layouts-Specification.md`](V9-Customizable-Summary-Fields-Dashboard-Layouts-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-VIZ-001 | Combo Chart Support | Curated StoX-defined combo-chart library in Stock Details with preset navigation, reusable chart patterns, range/sampling controls, preset axes/renderers, synchronized tooltips, legend controls, per-stock availability and account-wide default/fallback. Canonical spec: [`V9-Combo-Chart-Support-Specification.md`](V9-Combo-Chart-Support-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V4-FEAT-017 | AI Platform & Governance | Shared StoX AI infrastructure: capability registry, ordered inference routing, provider adapters, streaming, structured outputs, failover/circuit breakers, Admin provider/model configuration, traces, prompt/response governance, cost/budgets, prompt registry/versioning and concurrency controls. Canonical spec: [`V9-AI-Platform-Governance-Specification.md`](V9-AI-Platform-Governance-Specification.md). Normative architecture: [`V9-AI-Technical-Architecture-Specification.md`](V9-AI-Technical-Architecture-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-AI-001 | Documentation-Grounded StoX Chatbot | Read-only journey-first StoX assistant for product help/explanation with grounded sources, safe page context, session-only memory, navigation links, follow-ups, feedback/copy/clear and deterministic fallback/refusal. Canonical spec: [`V9-Documentation-Grounded-StoX-Chatbot-Specification.md`](V9-Documentation-Grounded-StoX-Chatbot-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-AI-002 | Agentic StoX Assistant / MCP Action Layer | Governed typed read/mutation tools, deterministic policy mediation, bounded autonomous read investigations, mutation preview/approval, stale-state checks, idempotency, partial-failure rules, destructive safeguards, post-action verification and run history. Broker trading, recurring autonomous mutations and external tools remain out of scope. Canonical spec: [`V9-Agentic-StoX-Assistant-MCP-Action-Layer-Specification.md`](V9-Agentic-StoX-Assistant-MCP-Action-Layer-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |
| V9-AI-003 | Embedded AI Insights & Prompt Execution | Replace legacy copy-prompt AI affordances with managed embedded AI execution while preserving Copy AI Prompt as degraded fallback. Shared stock insight, context-sensitive presentation, structured enrichment, persistent fingerprint cache, provenance, refresh/copy/navigation, V8 fundamental-insight reuse and structured Strategy Designer with AI-002-governed draft creation. Canonical spec: [`V9-Embedded-AI-Insights-Prompt-Execution-Specification.md`](V9-Embedded-AI-Insights-Prompt-Execution-Specification.md). | **FROZEN / IMPLEMENTATION-READY** |

## 3. Key inherited boundaries

### Historical data

`V9-DATA-002` inherits `V4-FEAT-065` unless explicitly overridden. In particular:

- MacBook remains the canonical historical-data/research machine;
- Parquet remains canonical storage;
- current NIFTY 500 + configured broad/sector indices remain the universe;
- DuckDB + Polars/Python analytical access remains intact;
- historical collection remains resumable/idempotent and coverage-aware;
- FEAT-063 prospective microstructure remains separate;
- no automated backup subsystem is introduced for the Mac corpus.

V9-DATA-002 explicitly overrides/adds:

- maximum provider-available historical depth;
- VPS-based Kite acquisition using whitelisted egress;
- temporary bounded VPS staging;
- one-trading-day immutable delivery batches;
- secure Mac-initiated outbound HTTPS transfer through the dedicated transfer surface;
- atomic local import + exact acknowledgment + lifecycle-governed cleanup.

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
3. **Data-platform wave** — `V9-DATA-001` and `V9-DATA-002`, parallelized where dependencies permit. V9-DATA-002 must preserve the FEAT-065 contract and coordinate protocol compatibility with SKR-001.
4. **Deterministic/user-facing feature wave** — `V9-UX-002`, `V9-OPS-001`, `V9-UX-003`, `V9-VIZ-001`.
5. **V4-FEAT-017 core** — shared AI capability registry, provider adapters/routing, structured outputs, prompt registry/governance, budgets/concurrency and failure isolation.
6. **V9-OPS-003** — register `ops.log_error_triage`, add centralized sanitized ERROR-log triage, bounded inference/deduplication and high-confidence code-bug GitHub issue generation using OPS-002 infrastructure. This can proceed in parallel with later user-facing AI work once the V4-FEAT-017 core gate is satisfied.
7. **V9-AI-001** — documentation-grounded chatbot.
8. **V9-AI-002** — governed agentic action layer.
9. **V9-AI-003** — embedded AI insights and managed Strategy Designer execution.
10. **V9-UX-001 final closure** — final journey reconciliation/E2E coverage across the completed V9 product, then release audit.

Independent implementation may be parallelized, but hard dependencies, protected V8 closure boundaries and cross-repo protocol compatibility must be preserved.

## 6. V9-DATA-002 / SKR-001 release gate

V9-DATA-002 is complete only when both sides of the coupled protocol are compatible and green:

- VPS collection/staging/manifest/lease/range-download/ack/retention behavior passes its frozen acceptance criteria;
- SKR-001 signed/notarized macOS utility passes its frozen network, recovery, disk-safety, integrity and atomic-import acceptance criteria;
- shared manifest/protocol versions interoperate;
- existing FEAT-065 corpus remains canonical and DuckDB/Polars-compatible;
- no Kite credential reaches the Mac;
- no inbound listener, SSH tunnel or VPN is required on the Mac;
- cleanup never deletes an unacknowledged/failed payload before a successful local import and matching acknowledgment; acknowledged payloads follow the 7-day grace period.

## 7. Implementation-ready declaration

The previously audited V9 scope had no unresolved product-level contradiction. Subsequent additions have now been separately product-defined and frozen:

- `V9-AI-003` — Embedded AI Insights & Prompt Execution;
- `V9-COMM-001` beta access-request email resilience companion;
- `V9-DATA-002` — VPS Historical Data Staging and Delivery, reconciled against V8 FEAT-065;
- `SKR-001` companion macOS downloader specification;
- `V9-OPS-002` — Automated API Failure GitHub Issue Reporting;
- `V9-OPS-003` — LLM Log Error Triage & GitHub Issue Reporting.

Normative reconciliations include:

- UX-001 starts first as a foundation but closes last as the release-level journey/E2E gate;
- COMM-001 remains the shared notification framework;
- OPS-002 is an additive deterministic operational reliability layer, separate from FEAT-052 telemetry and COMM-001 notification delivery;
- OPS-002 default success semantics are 2xx, with policy-based expected non-2xx exclusion, local + GitHub-marker deduplication, strict redaction and fail-open queued GitHub integration;
- OPS-003 consumes ERROR logs asynchronously, sanitizes before inference, uses only the governed `ops.log_error_triage` capability on V4-FEAT-017, and automatically creates an issue only for actionable `code_bug` classifications meeting the default 0.85 confidence/evidence gates;
- OPS-003 reuses OPS-002 GitHub credentials/adapter/rate limits/deduplication rather than creating a second integration, and its own AI/GitHub failures are excluded from recursive triage;
- DATA-002 changes FEAT-065 acquisition/delivery topology but does not replace its canonical research-data contract;
- the Mac remains the canonical historical corpus; VPS copies are temporary bounded staging;
- AI-002 extends AI-001 from visible-page explanation to governed account-data reasoning;
- AI-003 consumes the same shared platform and reuses AI-002 for Strategy draft mutation;
- deterministic calculations remain in StoX services rather than being delegated to LLMs;
- V8 telemetry ownership and FEAT-063/FEAT-065 data-domain boundaries remain intact.

**StoX V9 is hereby declared FROZEN / IMPLEMENTATION-READY, including V9-DATA-002, V9-AI-003, V9-OPS-002 and V9-OPS-003 subject to its V4-FEAT-017 core dependency gate.**

The implementation agent may begin automatically from this register and its linked frozen specifications. No additional planning handoff is required unless implementation discovers a genuinely new material product decision or a direct frozen-spec contradiction.