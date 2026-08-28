# Documentation Map

**Purpose:** Single root index for every major Markdown document in this repository.  
**Audience:** Humans and AI agents ingesting or re-understanding the project from scratch.  
**Rule:** Prefer reading **top → bottom** within each path below. Do not jump to implementation before intent unless you only need a runbook.

**Entry point:** Always start at [README.md](README.md). This file (`DOCS.md`) is the ingestion docs tree linked from the README.

---

## Start here (choose a path)

| Goal | Start at | Then |
|------|----------|------|
| **Any session (default)** | [README.md](README.md) | Follow its **Project documentation ingest** link into this file |
| **Full product understanding** | [README.md](README.md) → this file §1 → §2 → §3 | §4–§6 as needed |
| **Run / develop the app today** | [README.md](README.md) → [implementation.md](implementation.md) | Deploy docs if shipping |
| **Trading OS intent vs what shipped** | [specs/README.md](specs/README.md) | Governance → Audit |
| **V3 product specification** | [specs/LidoPortfolio-V3-Specification.md](specs/LidoPortfolio-V3-Specification.md) | **v0.28**; **V3 STRICTLY COMPLETE** (2026-08-26); OD-01–OD-24 + DEP-* frozen in §33; recall/bridge delta [architecture/V3-WS4-Recall-Bridge-Implementation-Delta.md](specs/architecture/V3-WS4-Recall-Bridge-Implementation-Delta.md) |
| **V4 deferred work** | [specs/LidoPortfolio-V4-Wishlist.md](specs/LidoPortfolio-V4-Wishlist.md) | **Wishlist / deferred register** after V3 freeze — active V4 features (FEAT-001, FEAT-002, FEAT-005, FEAT-006, FEAT-021–029, FEAT-032 COMPLETE; others OPEN) + frozen SPEC-001–007 (product rules; FEAT-001 implements SPEC-007; FEAT-002 GTT Target/Stop-Loss; SPEC-001 merge, SPEC-003 CA restatement, SPEC-004 LOAN/RECALL/BRIDGE, and SPEC-005 explicit SELL attribution implemented 2026-08-28) + V5-deferred FEAT IDs |
| **Authority / conflicts** | [specs/architecture/governance/DOCUMENT_PRECEDENCE.md](specs/architecture/governance/DOCUMENT_PRECEDENCE.md) | — |

**Agents:** User sessions start at **README.md**. Use this file only after that pointer. For day-to-day coding, still use [implementation.md](implementation.md) as the living technical reference.


---

## Master tree (reading order)

```text
DOCS.md                          ← you are here (documentation root)
│
├── 1. PRODUCT & ORIENTATION          (what the app is)
├── 2. REQUIREMENTS & ARCHITECTURE    (intent — read before code)
├── 3. GOVERNANCE & V1.0 SCOPE        (what we actually accepted)
├── 4. IMPLEMENTATION STATUS & AUDIT  (what was built / verified)
├── 5. LIVING TECHNICAL REFERENCE     (how to run and change code)
└── 6. DEPLOYMENT & OPERATIONS        (production)
```

---

## 1. Product & orientation

*Read first for context. Short.*

| Order | Document | Why |
|------:|----------|-----|
| 1.1 | [README.md](README.md) | Product overview, features, quick start |
| 1.2 | [app/README.md](app/README.md) | Application-root notes (if present) |
| 1.3 | [app/CHANGELOG.md](app/CHANGELOG.md) | App changelog |
| 1.4 | [app/public/docs/](app/public/docs/) | **Static HTML** in-app help (crawlable; generated from `appDocumentation.js`) |

---

## 2. Requirements & architecture (intent)

*Long-term design reference. **Do not expect code to match every SHALL** — see §3 for accepted deviations.*

Subtree hub: **[specs/README.md](specs/README.md)** · Architecture hub: **[specs/architecture/README.md](specs/architecture/README.md)**

### 2.A Architecture (read in numeric order)

| Order | Document |
|------:|----------|
| 2.0 | [specs/architecture/platform/README.md](specs/architecture/platform/README.md) | **Platform Architecture** entry point |
| 2.1 | [specs/architecture/platform/01-Vision.md](specs/architecture/platform/01-Vision.md) |
| 2.2 | [specs/architecture/platform/02-Guiding-Principles.md](specs/architecture/platform/02-Guiding-Principles.md) |
| 2.3 | [specs/architecture/platform/03-Core-Concepts.md](specs/architecture/platform/03-Core-Concepts.md) |
| 2.4 | [specs/architecture/platform/04-System-Architecture.md](specs/architecture/platform/04-System-Architecture.md) |
| 2.5 | [specs/architecture/platform/05-Daily-Decision-Pipeline.md](specs/architecture/platform/05-Daily-Decision-Pipeline.md) |
| 2.6 | [specs/architecture/platform/06-Engine-Overview.md](specs/architecture/platform/06-Engine-Overview.md) |
| 2.6a | [specs/architecture/ui/07-Trading-OS-Pages-and-Flow.md](specs/architecture/ui/07-Trading-OS-Pages-and-Flow.md) | Which UI page shows what; recommendation path through pages |
| 2.6b | [specs/architecture/indicators/08-Indicator-Architecture-Analysis.md](specs/architecture/indicators/08-Indicator-Architecture-Analysis.md) | **As-built** indicator architecture (dual catalogues) |
| 2.6c | [specs/architecture/indicators/09-Indicator-Registry.md](specs/architecture/indicators/09-Indicator-Registry.md) | **Target** Indicator Registry architecture (SD-033) — **implemented foundation + Admin UI** |
| 2.6d | [specs/architecture/indicators/10-Indicator-Registry-Implementation-Plan.md](specs/architecture/indicators/10-Indicator-Registry-Implementation-Plan.md) | Epics / Stories / Tasks implementation plan |
| 2.6e | [specs/architecture/indicators/11-Trading-Artifact-Framework.md](specs/architecture/indicators/11-Trading-Artifact-Framework.md) | **Target** Trading Artifact Framework (SD-034) — Indicators, Screeners, Strategies |
| 2.6f | [specs/architecture/indicators/13-Indicator-Lifecycle.md](specs/architecture/indicators/13-Indicator-Lifecycle.md) | Indicator lifecycle, Liquidity/Tradability V1, capability gates |
| 2.6g | [specs/architecture/indicators/14-Indicator-Registry-Diagrams.md](specs/architecture/indicators/14-Indicator-Registry-Diagrams.md) | Class / component / registry / dependency / lifecycle diagrams |
| 2.6h | [specs/architecture/ui/15-Sidebar-Navigation-Architecture.md](specs/architecture/ui/15-Sidebar-Navigation-Architecture.md) | Sidebar / navigation registry, config, extensibility how-to |
| 2.6i | [specs/architecture/live-trading/README.md](specs/architecture/live-trading/README.md) | Live Trading subsystem hub (glossary → overview → execution → security) |
| 2.6j | [specs/architecture/integrations/README.md](specs/architecture/integrations/README.md) | External integrations domain (placeholder) |
| 2.6k | [specs/architecture/MIGRATION-SUMMARY.md](specs/architecture/MIGRATION-SUMMARY.md) | Architecture folder reorganizations (2026-08-06) |

### 2.B Domain & contracts

| Order | Document |
|------:|----------|
| 2.7 | [specs/architecture/platform/System-Domain-Model.md](specs/architecture/platform/System-Domain-Model.md) |
| 2.8 | [specs/architecture/data/Database-Schema-Specification.md](specs/architecture/data/Database-Schema-Specification.md) |
| 2.9 | [specs/architecture/domains/REST-API-Specification.md](specs/architecture/domains/REST-API-Specification.md) |
| 2.10 | [specs/architecture/domains/Application-Architecture-Specification.md](specs/architecture/domains/Application-Architecture-Specification.md) |

### 2.C Engine specifications (pipeline order)

| Order | Document | Notes |
|------:|----------|-------|
| 2.11 | [specs/architecture/data/Data-Engine-Specification.md](specs/architecture/data/Data-Engine-Specification.md) | |
| 2.12 | *(no Discovery Engine Spec file)* | Discovery intent is in architecture; implementation wraps PatternScan + Screener |
| 2.13 | [specs/architecture/domains/Evaluation-Engine-Specification.md](specs/architecture/domains/Evaluation-Engine-Specification.md) | |
| 2.14 | [specs/architecture/domains/Recommendation-Engine-Specification.md](specs/architecture/domains/Recommendation-Engine-Specification.md) | |
| 2.14a | [specs/architecture/portfolio/Cash-Management-Specification.md](specs/architecture/portfolio/Cash-Management-Specification.md) | SD-026 cash ledger + reserved cash |
| 2.14b | [specs/architecture/domains/Strategy-Configuration-Specification.md](specs/architecture/domains/Strategy-Configuration-Specification.md) | SD-027/030 strategy scoring + Screener eligibility |
| 2.14c | [specs/architecture/domains/Screener-Specification.md](specs/architecture/domains/Screener-Specification.md) | Sole eligibility engine; Strategy consumes Screeners |
| 2.14d | [specs/architecture/portfolio/Analytics-Architecture-Specification.md](specs/architecture/portfolio/Analytics-Architecture-Specification.md) | SD-031 analytics ownership |
| 2.14e | [specs/architecture/domains/Market-Analysis-Engine-Specification.md](specs/architecture/domains/Market-Analysis-Engine-Specification.md) | SD-032 market analytics / sentiment / phase |
| 2.14f | [specs/architecture/portfolio/Dashboard-Specification.md](specs/architecture/portfolio/Dashboard-Specification.md) | Dashboard page purpose |
| 2.14g | [specs/architecture/portfolio/Watchlist-Specification.md](specs/architecture/portfolio/Watchlist-Specification.md) | Watchlist research workspace |
| 2.14h | [specs/architecture/portfolio/Portfolio-Specification.md](specs/architecture/portfolio/Portfolio-Specification.md) | Holdings / Portfolio workspace |
| 2.14i | [specs/architecture/portfolio/Portfolio-Analytics-Specification.md](specs/architecture/portfolio/Portfolio-Analytics-Specification.md) | Portfolio-wide analytics + market context |
| 2.14j | [specs/architecture/domains/Strategy-Specification.md](specs/architecture/domains/Strategy-Specification.md) | Strategy overview + market gates |
| 2.14k | [specs/architecture/portfolio/Discovery-Specification.md](specs/architecture/portfolio/Discovery-Specification.md) | Discovery page purpose |
| 2.14l | [specs/architecture/domains/Indicator-Registry-Specification.md](specs/architecture/domains/Indicator-Registry-Specification.md) | SD-033 unified Indicator Registry (specialization of SD-034) |
| 2.14m | [specs/architecture/domains/Indicator-Registry-API.md](specs/architecture/domains/Indicator-Registry-API.md) | Admin `GET /api/v1/indicators*` API |
| 2.14n | [specs/architecture/domains/Trading-Artifact-Framework-Specification.md](specs/architecture/domains/Trading-Artifact-Framework-Specification.md) | SD-034 Trading Artifact Framework (**registry infrastructure landed**) |
| 2.14o | [specs/architecture/domains/Trading-Artifact-JSON-Specification.md](specs/architecture/domains/Trading-Artifact-JSON-Specification.md) | Declarative JSON formats for Indicator / Screener / Strategy |
| 2.14p | [specs/architecture/domains/Trading-Artifact-Registry-Migration.md](specs/architecture/domains/Trading-Artifact-Registry-Migration.md) | Registry infrastructure migration / BC guide |
| 2.14q | [specs/architecture/domains/Screener-Registry-Migration.md](specs/architecture/domains/Screener-Registry-Migration.md) | Screener Registry (metadata, versions, import/export UI) migrate notes |
| 2.14r | [specs/architecture/domains/Strategy-Registry-Migration.md](specs/architecture/domains/Strategy-Registry-Migration.md) | Strategy Registry (selection, portable JSON, Minervini migrate) |
| 2.14s | [specs/architecture/domains/StoX-Trading-Artifacts-AI-Guide.md](specs/architecture/domains/StoX-Trading-Artifacts-AI-Guide.md) | **AI download pack** — Introduction + **AI Authoring Contract (normative)** + Hard Rules + Workflow + Registries + Cookbook + Examples + Runtime appendix (`/docs/stox-trading-artifacts-ai-guide.md`) |
| 2.14q | [specs/architecture/domains/artifacts/README.md](specs/architecture/domains/artifacts/README.md) | Worked JSON examples (Momentum, Swing, Liquidity, Breakout, …) |
| 2.15 | [specs/architecture/domains/Notification-Engine-Specification.md](specs/architecture/domains/Notification-Engine-Specification.md) | |
| 2.16 | [specs/architecture/domains/Execution-Engine-Specification.md](specs/architecture/domains/Execution-Engine-Specification.md) | |
| 2.17 | [specs/architecture/domains/Review-Engine-Specification.md](specs/architecture/domains/Review-Engine-Specification.md) | |

### 2.D Original implementation plan (intent timeline)

| Order | Document |
|------:|----------|
| 2.18 | [specs/architecture/domains/Implementation-Roadmap.md](specs/architecture/domains/Implementation-Roadmap.md) | Includes append-only **Version 1.0 Baseline** pointer to governance |

---

## 3. Governance & Version 1.0 scope

*Bridge between intent (§2) and what V1.0 actually ships. Read after architecture.*

Hub: [specs/architecture/governance/README.md](specs/architecture/governance/README.md)

| Order | Document | Why |
|------:|----------|-----|
| 3.0 | [specs/architecture/governance/ARCHITECTURE_REPOSITORY_GOVERNANCE.md](specs/architecture/governance/ARCHITECTURE_REPOSITORY_GOVERNANCE.md) | Architecture repo constitution (**mandatory authoring principles** + Golden Rule) |
| 3.0a | [specs/architecture/governance/ARCHITECTURE_REPOSITORY_BASELINE_V1.md](specs/architecture/governance/ARCHITECTURE_REPOSITORY_BASELINE_V1.md) | **Architecture Repository V1.0 Frozen** baseline |
| 3.0b | [specs/architecture/governance/SPECIFICATION_HEADER_TEMPLATE.md](specs/architecture/governance/SPECIFICATION_HEADER_TEMPLATE.md) | Standard header template for new specs |
| 3.0c | [specs/architecture/platform/README.md](specs/architecture/platform/README.md) | **Platform Architecture** entry point |
| 3.1 | [specs/architecture/governance/DOCUMENT_PRECEDENCE.md](specs/architecture/governance/DOCUMENT_PRECEDENCE.md) | How to resolve conflicts |
| 3.2 | [specs/architecture/governance/SPECIFICATION_DECISIONS.md](specs/architecture/governance/SPECIFICATION_DECISIONS.md) | Accepted deviations (SD-xxx) |
| 3.3 | [specs/architecture/governance/MVP_SCOPE.md](specs/architecture/governance/MVP_SCOPE.md) | What is in / out of V1.0 |
| 3.4 | [specs/architecture/governance/VERSION_1_BASELINE.md](specs/architecture/governance/VERSION_1_BASELINE.md) | Frozen baseline |
| 3.5 | [specs/architecture/governance/PRODUCT_BACKLOG.md](specs/architecture/governance/PRODUCT_BACKLOG.md) | Deferred work / roadmap |

### 3.A V2 planning (SD-035 — **CLOSED**; not active V1 requirements)

***SD-035 V2 = CLOSED.*** All eleven deferred capabilities are complete — authoritative snapshot: [docs/v2/V2-FINAL-RECONCILIATION.md](docs/v2/V2-FINAL-RECONCILIATION.md) (§3.L). Planning docs below retain historical scores/phases; do not treat them as an open backlog. **F042/F043** §3.B–§3.C; **F003/F005** §3.D; **F127** §3.E; **F019** §3.F; **F014** §3.G; **F060** §3.H; **F137** §3.I; **F143** §3.J; **F144** §3.K.

| Order | Document | Why |
|------:|----------|-----|
| 3.6 | [docs/v2/V2-PRIORITIZATION.md](docs/v2/V2-PRIORITIZATION.md) | Historical priority scores + final CLOSED state table |
| 3.7 | [docs/v2/V2-DEPENDENCIES.md](docs/v2/V2-DEPENDENCIES.md) | Dependencies (all satisfied) + historical analysis |
| 3.8 | [docs/v2/V2-ROADMAP.md](docs/v2/V2-ROADMAP.md) | V2 CLOSED + historical phase delivery record |

### 3.B F042 — Data Quality (**COMPLETE**)

*V2 Market Data Quality — F042 hardening complete (`F042_COMPLETE_WITH_NON_BLOCKERS`). Specs and historical audits preserved below.*

| Order | Document | Why |
|------:|----------|-----|
| 3.9 | [docs/v2/F042-DATA-QUALITY-SPEC.md](docs/v2/F042-DATA-QUALITY-SPEC.md) | F042 requirements, acceptance criteria, workflow, data model |
| 3.10 | [docs/v2/F042-IMPLEMENTATION-GAP-MATRIX.md](docs/v2/F042-IMPLEMENTATION-GAP-MATRIX.md) | Implementation vs requirements (OHLCV repair handed to F043) |
| 3.11 | [docs/v2/F042-F043-BOUNDARY.md](docs/v2/F042-F043-BOUNDARY.md) | F020 / F042 / F043 responsibility boundaries (historical F042 view) |
| 3.12 | [docs/v2/F042-POLICY-DECISIONS.md](docs/v2/F042-POLICY-DECISIONS.md) | F042 policy semantics (auto-accept, re-detection, gating, handoff) |
| 3.13 | [docs/v2/F042-FINAL-COMPLIANCE-AUDIT.md](docs/v2/F042-FINAL-COMPLIANCE-AUDIT.md) | Historical post-hardening F042 compliance audit |

### 3.C F043 — Corporate Action Price Repair (**COMPLETE**)

*V2 Market Data Quality — F043 complete (`F043_COMPLETE`), including double-restatement / single-writer hardening. Specs and historical audits preserved below.*

| Order | Document | Why |
|------:|----------|-----|
| 3.14 | [docs/v2/F043-CORPORATE-ACTION-PRICE-REPAIR-SPEC.md](docs/v2/F043-CORPORATE-ACTION-PRICE-REPAIR-SPEC.md) | F043 requirements, single-writer invariant, acceptance criteria |
| 3.15 | [docs/v2/F043-IMPLEMENTATION-GAP-MATRIX.md](docs/v2/F043-IMPLEMENTATION-GAP-MATRIX.md) | Current repair code vs formal F043 requirements |
| 3.16 | [docs/v2/F043-F042-BOUNDARY.md](docs/v2/F043-F042-BOUNDARY.md) | F043-side F020/F042/F043 boundary + OHLCV single-writer model |
| 3.17 | [docs/v2/F043-FINAL-COMPLIANCE-AUDIT.md](docs/v2/F043-FINAL-COMPLIANCE-AUDIT.md) | Historical F043 compliance audit (+ hardening addendum) |

### 3.D F003 / F005 — Account & Access (**F003 COMPLETE**; **F005 COMPLETE**)

*V2 Phase 1 Track A. Product policies closed (PD-004/005/006 **DECIDED**; PD-012 **RESOLVED_BY_PD-006**; PD-013 = docs task; PD-007 **DEFERRED**). **F003** closed as `F003_COMPLIANT_WITH_NON_BLOCKERS`. **F005** closed as `F005_COMPLETE_WITH_NON_BLOCKERS` (PD-006 revoke-others + remember_token rotation; manual session list/revoke preserved).*

**Authoritative:**

| Order | Document | Why |
|------:|----------|-----|
| 3.18 | [docs/v2/F003-USER-INVITE-SPEC.md](docs/v2/F003-USER-INVITE-SPEC.md) | F003 invite lifecycle, authZ, security, requirements, ACs (**COMPLETE**) |
| 3.19 | [docs/v2/F005-SESSION-MANAGEMENT-SPEC.md](docs/v2/F005-SESSION-MANAGEMENT-SPEC.md) | F005 session list/revoke + PD-006 credential-change semantics (**COMPLETE**) |
| 3.20 | [docs/v2/F003-F005-BOUNDARY.md](docs/v2/F003-F005-BOUNDARY.md) | Ownership vs V1 auth, F004, profiles, F060; out-of-scope |
| 3.21 | [docs/v2/F003-F005-POLICY-DECISIONS.md](docs/v2/F003-F005-POLICY-DECISIONS.md) | Final policy register (no open product decisions) |
| 3.22 | [docs/v2/F003-F005-IMPLEMENTATION-GAP-MATRIX.md](docs/v2/F003-F005-IMPLEMENTATION-GAP-MATRIX.md) | Gap matrix + backlog; F003 / F005 closed |

*F003 final compliance audit: `F003_COMPLIANT_WITH_NON_BLOCKERS` (chat audit 2026-08-09; non-blockers recorded in the gap matrix). No separate F003 audit markdown file required for closure. F005 delivery: `F005_COMPLETE_WITH_NON_BLOCKERS` (2026-08-09).*

### 3.E F127 — Portfolio Alerts / Monitoring (**COMPLETE**)

*V2 Phase 2 Monitoring & Alerts. Hardening delivered 2026-08-09: expire→evaluate daily order (PD-F127-07), holding_closed expiry fix, repeated Telegram digests preserved, help synced. Verdict: `F127_COMPLETE_WITH_NON_BLOCKERS`.*

**Authoritative:**

| Order | Document | Why |
|------:|----------|-----|
| 3.23 | [docs/v2/F127-PORTFOLIO-ALERTS-SPEC.md](docs/v2/F127-PORTFOLIO-ALERTS-SPEC.md) | F127 normative requirements and ACs (**COMPLETE**) |
| 3.24 | [docs/v2/F127-BOUNDARY.md](docs/v2/F127-BOUNDARY.md) | Ownership vs TOS / VIX / ops / screener / F042 / F043; shared Telegram |
| 3.25 | [docs/v2/F127-POLICY-DECISIONS.md](docs/v2/F127-POLICY-DECISIONS.md) | Policy register (PD-F127-01…21) — closed |
| 3.26 | [docs/v2/F127-IMPLEMENTATION-GAP-MATRIX.md](docs/v2/F127-IMPLEMENTATION-GAP-MATRIX.md) | Gap matrix; non-blockers listed |

### 3.F F019 — Bulk CSV Import (**COMPLETE**)

*V2 Phase 3 Portfolio History & Import — F019 delivered first; F014 downstream is also **COMPLETE** (see §3.G). Hardening delivered 2026-08-09: shared ledger+holdings+cash financial unit (PD-14), all-or-nothing `POST /api/transactions/bulk` with batch/row identity (PD-01/02/03/15/18), frontend bulk flow, tests. Verdict: `F019_COMPLETE_WITH_NON_BLOCKERS`.*

**Authoritative:**

| Order | Document | Why |
|------:|----------|-----|
| 3.27 | [docs/v2/F019-BULK-CSV-IMPORT-SPEC.md](docs/v2/F019-BULK-CSV-IMPORT-SPEC.md) | F019 normative requirements, CSV contract, ACs (**COMPLETE**) |
| 3.28 | [docs/v2/F019-BOUNDARY.md](docs/v2/F019-BOUNDARY.md) | Ownership vs ledger/cash/CRUD/F014/F020 |
| 3.29 | [docs/v2/F019-POLICY-DECISIONS.md](docs/v2/F019-POLICY-DECISIONS.md) | Policy register (PD-F019-01…22) — **closed** |
| 3.30 | [docs/v2/F019-IMPLEMENTATION-GAP-MATRIX.md](docs/v2/F019-IMPLEMENTATION-GAP-MATRIX.md) | Gaps closed; non-blockers listed |

### 3.G F014 — Historical Holdings Reconstruction (**COMPLETE**)

*V2 Phase 3 Portfolio History — after F019. Delivered 2026-08-09: `GET /api/portfolio/historical-holdings`, dedicated `/portfolio/historical-holdings` page, ledger replay with oversell warnings, adjusted-close valuation, incomplete-price signalling, help + tests. Verdict: `F014_COMPLETE_WITH_NON_BLOCKERS`.*

**Authoritative:**

| Order | Document | Why |
|------:|----------|-----|
| 3.31 | [docs/v2/F014-HISTORICAL-HOLDINGS-SPEC.md](docs/v2/F014-HISTORICAL-HOLDINGS-SPEC.md) | F014 requirements, ACs (**COMPLETE**) |
| 3.32 | [docs/v2/F014-BOUNDARY.md](docs/v2/F014-BOUNDARY.md) | Ownership vs ledger / F015 / F019 / F020 / F042 / F043 / F127 |
| 3.33 | [docs/v2/F014-POLICY-DECISIONS.md](docs/v2/F014-POLICY-DECISIONS.md) | Policy register (PD-F014-01…21) — **closed** |
| 3.34 | [docs/v2/F014-IMPLEMENTATION-GAP-MATRIX.md](docs/v2/F014-IMPLEMENTATION-GAP-MATRIX.md) | Gaps closed; non-blockers listed |

### 3.H F060 — Shared Screener Import (**COMPLETE**)

*V2 Collaboration. Hardening delivered 2026-08-09: same-user multi-profile sharing only; cross-user discover/read/import/registry/eligibility/Discovery/backtest-pin **denied**; shared responses limited to name + definition; import fork with `(1)`/`(2)` naming; help synced. Verdict: **`F060_COMPLETE_WITH_NON_BLOCKERS`** (residual PD-10 remap, PD-22 404-vs-403).*

**Authoritative:**

| Order | Document | Why |
|------:|----------|-----|
| 3.35 | [docs/v2/F060-SHARED-SCREENER-IMPORT-SPEC.md](docs/v2/F060-SHARED-SCREENER-IMPORT-SPEC.md) | DECIDED requirements + delivery ACs (**COMPLETE**) |
| 3.36 | [docs/v2/F060-BOUNDARY.md](docs/v2/F060-BOUNDARY.md) | V1 vs F060; dual API + eligibility boundary |
| 3.37 | [docs/v2/F060-POLICY-DECISIONS.md](docs/v2/F060-POLICY-DECISIONS.md) | Policy register — **closed**; delivery noted |
| 3.38 | [docs/v2/F060-IMPLEMENTATION-GAP-MATRIX.md](docs/v2/F060-IMPLEMENTATION-GAP-MATRIX.md) | Gaps closed; non-blockers listed |

### 3.I F137 — Recommendation Preview API (**COMPLETE**)

*V2 Phase 3 — last Phase 3 initiative after F060. Delivered 2026-08-10: shared `decideForSecurity` core; cycle-fresh persisted precedence; explicit owned `strategy_id`; canonical BUY/SELL/HOLD_POSITION/WATCH; execution + research sections; read-only preview; Watchlist aligned; `F137RecommendationPreviewTest`. Verdict: **`F137_COMPLETE_WITH_NON_BLOCKERS`**. F143/F144 also **CLOSED** (§3.J–§3.K). Do not reopen closed initiatives.*

**Authoritative:**

| Order | Document | Why |
|------:|----------|-----|
| 3.39 | [docs/v2/F137-RECOMMENDATION-PREVIEW-SPEC.md](docs/v2/F137-RECOMMENDATION-PREVIEW-SPEC.md) | CURRENT vs TARGET + delivered ACs |
| 3.40 | [docs/v2/F137-BOUNDARY.md](docs/v2/F137-BOUNDARY.md) | Shared decision core vs persist lifecycle |
| 3.41 | [docs/v2/F137-POLICY-DECISIONS.md](docs/v2/F137-POLICY-DECISIONS.md) | PD-F137-01…17 — **DECIDED** + delivered |
| 3.42 | [docs/v2/F137-IMPLEMENTATION-GAP-MATRIX.md](docs/v2/F137-IMPLEMENTATION-GAP-MATRIX.md) | Closed gaps; non-blockers listed |

### 3.J F143 — In-app Contextual Help (**COMPLETE**)

*V2 Knowledge & Guidance. Spec/reconciliation 2026-08-10: runtime help already shipped (44 topics, header → static `/docs`, generate-on-build). Pack formalizes ownership/CURRENT behaviour; **no mandatory implementation phase**. Verdict: **`F143_COMPLETE_WITH_NON_BLOCKERS`**. Do not reopen closed Phase 3 initiatives. Do not absorb F144.*

**Authoritative:**

| Order | Document | Why |
|------:|----------|-----|
| 3.43 | [docs/v2/F143-CONTEXTUAL-HELP-SPEC.md](docs/v2/F143-CONTEXTUAL-HELP-SPEC.md) | CURRENT help flow, coverage, ACs |
| 3.44 | [docs/v2/F143-BOUNDARY.md](docs/v2/F143-BOUNDARY.md) | Help layer vs feature domains / F144 |
| 3.45 | [docs/v2/F143-POLICY-DECISIONS.md](docs/v2/F143-POLICY-DECISIONS.md) | No blocking DECISION_REQUIRED |
| 3.46 | [docs/v2/F143-IMPLEMENTATION-GAP-MATRIX.md](docs/v2/F143-IMPLEMENTATION-GAP-MATRIX.md) | Non-blockers: tests, SPA prose drift, orphan Doc* |

### 3.K F144 — Knowledge Board (**COMPLETE**)

*V2 Knowledge & Guidance. Spec/reconciliation 2026-08-10: runtime Knowledge Board already shipped (notes/tags/images, profile isolation, UI/API/tests). Pack formalizes ownership/CURRENT behaviour; **no mandatory implementation phase**. Verdict: **`F144_COMPLETE_WITH_NON_BLOCKERS`**. Do not reopen closed initiatives. Do not absorb F143/F014/F019/F060/F137.*

**Authoritative:**

| Order | Document | Why |
|------:|----------|-----|
| 3.47 | [docs/v2/F144-KNOWLEDGE-BOARD-SPEC.md](docs/v2/F144-KNOWLEDGE-BOARD-SPEC.md) | CURRENT model, APIs, MUST/SHOULD, ACs |
| 3.48 | [docs/v2/F144-BOUNDARY.md](docs/v2/F144-BOUNDARY.md) | Standalone notes vs ledger/screeners/recs/help |
| 3.49 | [docs/v2/F144-POLICY-DECISIONS.md](docs/v2/F144-POLICY-DECISIONS.md) | No blocking DECISION_REQUIRED |
| 3.50 | [docs/v2/F144-IMPLEMENTATION-GAP-MATRIX.md](docs/v2/F144-IMPLEMENTATION-GAP-MATRIX.md) | Non-blockers: note AuthZ tests, image GC, API doc drift |

### 3.L V2 program closure (SD-035)

*All eleven SD-035 V2 initiatives reconciled. Program snapshot: [docs/v2/V2-FINAL-RECONCILIATION.md](docs/v2/V2-FINAL-RECONCILIATION.md) — **V2 CLOSED** (Option A). Remaining items are non-blocking polish only. Do not invent new SD-035 IDs.*

| Order | Document | Why |
|------:|----------|-----|
| 3.51 | [docs/v2/V2-FINAL-RECONCILIATION.md](docs/v2/V2-FINAL-RECONCILIATION.md) | Program-level status, dependencies, non-blockers, next phase |

### 3.M V2.1 Product Hardening (post–SD-035)

*Stabilization phase after V2 CLOSED. Scope = entire shipped product (V1 + V2). Read-only audit first — no new feature IDs.*

| Order | Document | Why |
|------:|----------|-----|
| 3.52 | [docs/v2.1/V2.1-PRODUCT-HARDENING-AUDIT.md](docs/v2.1/V2.1-PRODUCT-HARDENING-AUDIT.md) | Full-suite baseline, capability inventory, shadow features, P0–P2 backlog, execution order |
| 3.53 | [docs/v2.1/WS-A-TEST-BASELINE-CLEANUP.md](docs/v2.1/WS-A-TEST-BASELINE-CLEANUP.md) | WS-A: PHPUnit baseline stabilized (679/679 green) |
| 3.54 | [docs/v2.1/WS-B-FINANCIAL-AUTHZ-AUDIT.md](docs/v2.1/WS-B-FINANCIAL-AUTHZ-AUDIT.md) | WS-B: financial AuthZ audit + D1/D2/D5 fixes; soft reservation (D4) |
| 3.55 | [docs/v2.1/WS-C-SHADOW-FEATURE-INVENTORY.md](docs/v2.1/WS-C-SHADOW-FEATURE-INVENTORY.md) | WS-C: shadow feature inventory + retrospective pack plan (discovery only) |
| 3.56 | [docs/v2.1/CASH-MANAGEMENT-SPEC.md](docs/v2.1/CASH-MANAGEMENT-SPEC.md) | Cash Management retrospective CURRENT spec (first WS-C pack) |
| 3.57 | [docs/v2.1/CASH-MANAGEMENT-BOUNDARY.md](docs/v2.1/CASH-MANAGEMENT-BOUNDARY.md) | Ownership vs TransactionWriteService / F019 / F014 / reservations |
| 3.58 | [docs/v2.1/CASH-MANAGEMENT-POLICY-DECISIONS.md](docs/v2.1/CASH-MANAGEMENT-POLICY-DECISIONS.md) | Policy register (incl. soft reservation DECIDED) |
| 3.59 | [docs/v2.1/CASH-MANAGEMENT-IMPLEMENTATION-GAP-MATRIX.md](docs/v2.1/CASH-MANAGEMENT-IMPLEMENTATION-GAP-MATRIX.md) | Gap / test / doc matrix post–WS-B |
| 3.60 | [docs/v2.1/PORTFOLIO-SNAPSHOTS-SPEC.md](docs/v2.1/PORTFOLIO-SNAPSHOTS-SPEC.md) | F015 Portfolio Snapshots retrospective CURRENT spec (WS-C2) |
| 3.61 | [docs/v2.1/PORTFOLIO-SNAPSHOTS-BOUNDARY.md](docs/v2.1/PORTFOLIO-SNAPSHOTS-BOUNDARY.md) | Ownership vs F014 / ledger / Cash / Dashboard / F019 |
| 3.62 | [docs/v2.1/PORTFOLIO-SNAPSHOTS-POLICY-DECISIONS.md](docs/v2.1/PORTFOLIO-SNAPSHOTS-POLICY-DECISIONS.md) | Policy register (rebuildable cache SoT; silent-zero CURRENT) |
| 3.63 | [docs/v2.1/PORTFOLIO-SNAPSHOTS-IMPLEMENTATION-GAP-MATRIX.md](docs/v2.1/PORTFOLIO-SNAPSHOTS-IMPLEMENTATION-GAP-MATRIX.md) | Gap / test / doc matrix for F015 |
| 3.64 | [docs/v2.1/DASHBOARD-SPEC.md](docs/v2.1/DASHBOARD-SPEC.md) | Dashboard retrospective CURRENT spec (WS-C3) |
| 3.65 | [docs/v2.1/DASHBOARD-BOUNDARY.md](docs/v2.1/DASHBOARD-BOUNDARY.md) | Presentation vs Cash / F014 / F015 / F137 / Explorer / alerts |
| 3.66 | [docs/v2.1/DASHBOARD-POLICY-DECISIONS.md](docs/v2.1/DASHBOARD-POLICY-DECISIONS.md) | Policy register (live vs snapshot ownership; client cache) |
| 3.67 | [docs/v2.1/DASHBOARD-IMPLEMENTATION-GAP-MATRIX.md](docs/v2.1/DASHBOARD-IMPLEMENTATION-GAP-MATRIX.md) | Gap / test / doc matrix for Dashboard |
| 3.68 | [docs/v2.1/CALENDAR-SPEC.md](docs/v2.1/CALENDAR-SPEC.md) | User Calendar retrospective CURRENT spec (WS-C4) |
| 3.69 | [docs/v2.1/CALENDAR-BOUNDARY.md](docs/v2.1/CALENDAR-BOUNDARY.md) | Ownership vs TradingCalendar / Dashboard / ledger / F127 |
| 3.70 | [docs/v2.1/CALENDAR-POLICY-DECISIONS.md](docs/v2.1/CALENDAR-POLICY-DECISIONS.md) | Policy register (user events + admin trade holidays) |
| 3.71 | [docs/v2.1/CALENDAR-IMPLEMENTATION-GAP-MATRIX.md](docs/v2.1/CALENDAR-IMPLEMENTATION-GAP-MATRIX.md) | Gap / test / doc matrix for User Calendar |
| 3.72 | [docs/v2.1/EXPLORER-ANALYTICS-SPEC.md](docs/v2.1/EXPLORER-ANALYTICS-SPEC.md) | Explorer / Analytics retrospective CURRENT spec (WS-C5) |
| 3.73 | [docs/v2.1/EXPLORER-ANALYTICS-BOUNDARY.md](docs/v2.1/EXPLORER-ANALYTICS-BOUNDARY.md) | Ownership vs Dashboard / F015 / F137 / Screeners / DQ / sync |
| 3.74 | [docs/v2.1/EXPLORER-ANALYTICS-POLICY-DECISIONS.md](docs/v2.1/EXPLORER-ANALYTICS-POLICY-DECISIONS.md) | Policy register (cache-only Explorer; RS formula; SD-031 owners) |
| 3.75 | [docs/v2.1/EXPLORER-ANALYTICS-IMPLEMENTATION-GAP-MATRIX.md](docs/v2.1/EXPLORER-ANALYTICS-IMPLEMENTATION-GAP-MATRIX.md) | Gap / test / doc matrix for Explorer / Analytics |
| 3.76 | [docs/v2.1/V2.1-FINAL-RECONCILIATION.md](docs/v2.1/V2.1-FINAL-RECONCILIATION.md) | **V2.1 CLOSED** — hardening + priority packs; V3 candidates unprioritized |

### 3.N Lido Portfolio V3 (specification)

*Authoritative V3 product + architecture specification (**v0.28**). Implementation status (2026-08-26): **V3 STRICTLY COMPLETE**, including post-deploy product-surface closure (Discovery removed from the Market sidebar; strategy Create / Enable / Archive for multiple concurrent strategies). Living detail in [`implementation.md`](implementation.md). Genuine new post-V3 work is tracked in [`specs/LidoPortfolio-V4-Wishlist.md`](specs/LidoPortfolio-V4-Wishlist.md) (active V4 + V5-deferred FEAT IDs; V4-SPEC-001–007 are frozen PO decisions; SPEC-007 via FEAT-001; SPEC-001 merge, SPEC-003 CA restatement, SPEC-004 LOAN/RECALL/BRIDGE, and SPEC-005 explicit SELL attribution implemented 2026-08-28; SPEC-002 and SPEC-006 unimplemented). Supersedes V1 one-active-strategy (SD-029) and unfunded→WATCH for V3 work. Frozen OD/DEP decisions are in the document §33.*

| Order | Document | Why |
|------:|----------|-----|
| 3.77 | [specs/LidoPortfolio-V3-Specification.md](specs/LidoPortfolio-V3-Specification.md) | V3 SoT **v0.28**: multi-strategy, ownership, capital lending, recall/bridge, exits, ranking, charts |
| 3.77a | [specs/architecture/V3-WS4-Recall-Bridge-Implementation-Delta.md](specs/architecture/V3-WS4-Recall-Bridge-Implementation-Delta.md) | WS4 recall / capital-resolution / Recall Bridge Loan implementation delta (Phase 1–3B-2 + gap-closure **implemented**) |
| 3.78 | [specs/LidoPortfolio-V4-Wishlist.md](specs/LidoPortfolio-V4-Wishlist.md) | **V4 Wishlist / Deferred Work Register** — 22 active V4 features (14 COMPLETE including FEAT-001, FEAT-002, and FEAT-032), SPEC-001–007 **frozen** (FEAT-001 implements SPEC-007; SPEC-001 merge, SPEC-003 restatement, SPEC-004 LOAN/RECALL/BRIDGE, and SPEC-005 explicit SELL attribution implemented 2026-08-28), 14 V5-deferred FEAT IDs |

---

## 4. Implementation status & audit

*Evidence of what was built and verified. Read after governance.*

| Order | Document | Why |
|------:|----------|-----|
| 4.1 | [specs/IMPLEMENTATION_PROGRESS.md](specs/IMPLEMENTATION_PROGRESS.md) | Working log + assumptions A1–A13 |
| 4.2 | [specs/MVP_DEMO_CHECKLIST.md](specs/MVP_DEMO_CHECKLIST.md) | Human acceptance demo |
| 4.3 | [specs/architecture/audit/README.md](specs/architecture/audit/README.md) | Audit pack index |
| 4.4 | [specs/architecture/audit/MVP_VERDICT.md](specs/architecture/audit/MVP_VERDICT.md) | Final YES/NO + release posture |
| 4.5 | [specs/architecture/audit/SPECIFICATION_TRACEABILITY.md](specs/architecture/audit/SPECIFICATION_TRACEABILITY.md) | Spec → code matrix |
| 4.6 | [specs/architecture/audit/ARCHITECTURE_COMPLIANCE.md](specs/architecture/audit/ARCHITECTURE_COMPLIANCE.md) | Engine/layer compliance |
| 4.7 | [specs/architecture/audit/DATABASE_MAPPING.md](specs/architecture/audit/DATABASE_MAPPING.md) | Domain → tables |
| 4.8 | [specs/architecture/audit/API_INVENTORY.md](specs/architecture/audit/API_INVENTORY.md) | `/api/v1` endpoints |
| 4.9 | [specs/architecture/audit/UI_INVENTORY.md](specs/architecture/audit/UI_INVENTORY.md) | TOS pages |
| 4.10 | [specs/architecture/audit/MVP_TEST_SCRIPT.md](specs/architecture/audit/MVP_TEST_SCRIPT.md) | End-to-end manual test |
| 4.11 | [specs/architecture/audit/KNOWN_LIMITATIONS.md](specs/architecture/audit/KNOWN_LIMITATIONS.md) | Current limits |
| 4.12 | [specs/architecture/audit/TECHNICAL_DEBT.md](specs/architecture/audit/TECHNICAL_DEBT.md) | Debt register |
| 4.13 | [specs/architecture/audit/REPOSITORY_OVERVIEW.md](specs/architecture/audit/REPOSITORY_OVERVIEW.md) | Folder map |
| 4.14 | [specs/architecture/audit/PROJECT_STATISTICS.md](specs/architecture/audit/PROJECT_STATISTICS.md) | Counts / LOC |

### 4.A Feature coverage audits

| Order | Document | Why |
|------:|----------|-----|
| 4.15 | [docs/audits/2026-08-09-feature-coverage-final/](docs/audits/2026-08-09-feature-coverage-final/) | **Final V1 implementation baseline (2026-08-09)** — frozen scope SD-035; 119/119 strict coverage |
| 4.16 | [docs/audits/2026-08-09-feature-coverage/](docs/audits/2026-08-09-feature-coverage/) | Post-implementation baseline before scope freeze; V1-SCOPE-DECISION (SD-035) |

### 4.B Historical audits

| Order | Document | Why |
|------:|----------|-----|
| 4.17 | [docs/audits/2026-08-08-feature-coverage/](docs/audits/2026-08-08-feature-coverage/) | Pre-implementation V1 feature coverage and gap audit (2026-08-08); **historical baseline only** |

---

## 5. Living technical reference (implementation how-to)

*Use daily for coding and local setup. Complements — does not replace — §2–§4.*

| Order | Document | Why |
|------:|----------|-----|
| 5.1 | [implementation.md](implementation.md) | **Primary living technical reference** (agents: keep updated) |
| 5.2 | [debugging.md](debugging.md) | Production debug hooks / runbook |
| 5.3 | [app/API_DOCUMENTATION.md](app/API_DOCUMENTATION.md) | Legacy / broader API notes |
| 5.3a | [app/openapi/v1.json](app/openapi/v1.json) | Canonical OpenAPI 3.0.3 contract for `/api/v1` (V4-FEAT-025) |
| 5.4 | [portfolio-history-rebuild-report.md](portfolio-history-rebuild-report.md) | Historical rebuild notes |

---

## 6. Deployment & operations

Hub: [deploy/README.md](deploy/README.md) · Skill: [.cursor/skills/deploy-cpanel/SKILL.md](.cursor/skills/deploy-cpanel/SKILL.md)

| Order | Document | Why |
|------:|----------|-----|
| 6.1 | [deploy/DEPLOY.md](deploy/DEPLOY.md) | Main production deploy guide |
| 6.2 | [DEPLOYMENT_CPANEL.md](DEPLOYMENT_CPANEL.md) | cPanel-oriented notes |
| 6.3 | [DEPLOYMENT_VALIDATION_PLAN.md](DEPLOYMENT_VALIDATION_PLAN.md) | Validation plan |
| 6.4 | [deploy/LIDO-SERVER.md](deploy/LIDO-SERVER.md) | Server specifics |
| 6.5 | [deploy/GODADDY-PHP-UPGRADE.md](deploy/GODADDY-PHP-UPGRADE.md) | PHP upgrade |
| 6.6 | [deploy/RELEASE-2026-06-21.md](deploy/RELEASE-2026-06-21.md) | Historical release note |
| 6.7 | Fix guides | [FIX-403](deploy/FIX-403-FORBIDDEN.md) · [FIX-404](deploy/FIX-404-PORTFOLIO.md) · [FIX-MYSQL](deploy/FIX-MYSQL-INDEX-PRIVILEGES.md) · [FIX-OPEN-BASEDIR](deploy/FIX-OPEN-BASEDIR.md) · [FIX-BLANK-VITE](deploy/FIX-BLANK-VITE-PAGE.md) |

---

## 7. Agent / tooling skills (optional)

| Document | Why |
|----------|-----|
| [.cursor/skills/deploy-cpanel/SKILL.md](.cursor/skills/deploy-cpanel/SKILL.md) | Deploy upload table workflow |
| [.cursor/skills/commit-push-master/SKILL.md](.cursor/skills/commit-push-master/SKILL.md) | Commit/push to master |

---

## AI / human ingest recipes

### Recipe A — “Explain Trading OS from scratch” (~full)

1. README.md (skim Features)  
2. Architecture 01 → 06 (+ 07 pages; 08–11 indicator/artifact design as needed)  
3. System-Domain-Model + Engine specs (Data → Review) + Trading Artifact Framework if designing reuse/AI  
4. DOCUMENT_PRECEDENCE → SPECIFICATION_DECISIONS → MVP_SCOPE → VERSION_1_BASELINE  
5. IMPLEMENTATION_PROGRESS → MVP_VERDICT  
6. implementation.md (Trading OS section + runbook)

### Recipe B — “Ship or change V1.0 code this week”

1. DOCUMENT_PRECEDENCE + MVP_SCOPE  
2. implementation.md  
3. audit API_INVENTORY + UI_INVENTORY  
4. PRODUCT_BACKLOG (if choosing next work)

### Recipe C — “Accept / demo V1.0”

1. MVP_SCOPE  
2. MVP_DEMO_CHECKLIST  
3. MVP_TEST_SCRIPT  
4. MVP_VERDICT

### Recipe D — “Deploy production”

1. implementation.md (production notes)  
2. deploy/DEPLOY.md  
3. deploy-cpanel skill / prepare-deploy script  

---

## Folder diagram (specs only)

```text
specs/
├── README.md                      ← specs hub (links here + DOCS.md)
├── IMPLEMENTATION_PROGRESS.md
├── MVP_DEMO_CHECKLIST.md
└── architecture/                  ← domain-driven architecture hub
    ├── README.md                  ← architecture index + reading order
    ├── platform/                  ← vision → engine overview + system domain model
    ├── ui/                        ← pages/flow + sidebar (07, 15)
    ├── indicators/                ← indicator/artifact architecture (08–11, 13–14)
    ├── portfolio/                 ← dashboard, cash, discovery, analytics, holdings
    ├── data/                      ← schema + data engine
    ├── domains/                   ← contracts, pipeline/artifact specs, roadmap
    ├── live-trading/              ← live trading subsystem
    ├── integrations/              ← external integrations (placeholder)
    ├── governance/                ← V1.0 decisions, scope, backlog, baseline
    └── audit/                     ← freeze audit evidence
```

---

## Maintenance rules

1. **New Markdown that defines product/requirements/ops guidance** → add a link in this file (correct §) **in the same session**. Cursor rule: `.cursor/rules/Keep-DOCS-md-ingestion-tree-updated.mdc`.  
2. If the file lives under `specs/`, also link it from [specs/README.md](specs/README.md) (and governance/audit README when appropriate).  
3. **New accepted deviation** → `SPECIFICATION_DECISIONS.md`, not by rewriting architecture specs.  
4. **New feature after V1.0** → `PRODUCT_BACKLOG.md` then scope/baseline updates.  
5. **Code change** → update [implementation.md](implementation.md) in the same session.  
6. Keep **original architecture/engine specs** as historical intent (see governance).
