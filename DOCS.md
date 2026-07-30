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
| **Authority / conflicts** | [specs/governance/DOCUMENT_PRECEDENCE.md](specs/governance/DOCUMENT_PRECEDENCE.md) | — |

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

Subtree hub: **[specs/README.md](specs/README.md)**

### 2.A Architecture (read in numeric order)

| Order | Document |
|------:|----------|
| 2.1 | [specs/architecture/01-Vision.md](specs/architecture/01-Vision.md) |
| 2.2 | [specs/architecture/02-Guiding-Principles.md](specs/architecture/02-Guiding-Principles.md) |
| 2.3 | [specs/architecture/03-Core-Concepts.md](specs/architecture/03-Core-Concepts.md) |
| 2.4 | [specs/architecture/04-System-Architecture.md](specs/architecture/04-System-Architecture.md) |
| 2.5 | [specs/architecture/05-Daily-Decision-Pipeline.md](specs/architecture/05-Daily-Decision-Pipeline.md) |
| 2.6 | [specs/architecture/06-Engine-Overview.md](specs/architecture/06-Engine-Overview.md) |
| 2.6a | [specs/architecture/07-Trading-OS-Pages-and-Flow.md](specs/architecture/07-Trading-OS-Pages-and-Flow.md) | Which UI page shows what; recommendation path through pages |
| 2.6b | [specs/architecture/08-Indicator-Architecture-Analysis.md](specs/architecture/08-Indicator-Architecture-Analysis.md) | **As-built** indicator architecture (dual catalogues) |
| 2.6c | [specs/architecture/09-Indicator-Registry.md](specs/architecture/09-Indicator-Registry.md) | **Target** Indicator Registry architecture (SD-033) — **implemented foundation + Admin UI** |
| 2.6d | [specs/architecture/10-Indicator-Registry-Implementation-Plan.md](specs/architecture/10-Indicator-Registry-Implementation-Plan.md) | Epics / Stories / Tasks implementation plan |
| 2.6e | [specs/architecture/11-Trading-Artifact-Framework.md](specs/architecture/11-Trading-Artifact-Framework.md) | **Target** Trading Artifact Framework (SD-034) — Indicators, Screeners, Strategies |
| 2.6f | [specs/architecture/13-Indicator-Lifecycle.md](specs/architecture/13-Indicator-Lifecycle.md) | Indicator lifecycle, Liquidity/Tradability V1, capability gates |
| 2.6g | [specs/architecture/14-Indicator-Registry-Diagrams.md](specs/architecture/14-Indicator-Registry-Diagrams.md) | Class / component / registry / dependency / lifecycle diagrams |
| 2.6h | [specs/architecture/15-Sidebar-Navigation-Architecture.md](specs/architecture/15-Sidebar-Navigation-Architecture.md) | Sidebar / navigation registry, config, extensibility how-to |

### 2.B Domain & contracts

| Order | Document |
|------:|----------|
| 2.7 | [specs/engines/System-Domain-Model.md](specs/engines/System-Domain-Model.md) |
| 2.8 | [specs/engines/Database-Schema-Specification.md](specs/engines/Database-Schema-Specification.md) |
| 2.9 | [specs/engines/REST-API-Specification.md](specs/engines/REST-API-Specification.md) |
| 2.10 | [specs/engines/Application-Architecture-Specification.md](specs/engines/Application-Architecture-Specification.md) |

### 2.C Engine specifications (pipeline order)

| Order | Document | Notes |
|------:|----------|-------|
| 2.11 | [specs/engines/Data-Engine-Specification.md](specs/engines/Data-Engine-Specification.md) | |
| 2.12 | *(no Discovery Engine Spec file)* | Discovery intent is in architecture; implementation wraps PatternScan + Screener |
| 2.13 | [specs/engines/Evaluation-Engine-Specification.md](specs/engines/Evaluation-Engine-Specification.md) | |
| 2.14 | [specs/engines/Recommendation-Engine-Specification.md](specs/engines/Recommendation-Engine-Specification.md) | |
| 2.14a | [specs/engines/Cash-Management-Specification.md](specs/engines/Cash-Management-Specification.md) | SD-026 cash ledger + reserved cash |
| 2.14b | [specs/engines/Strategy-Configuration-Specification.md](specs/engines/Strategy-Configuration-Specification.md) | SD-027/030 strategy scoring + Screener eligibility |
| 2.14c | [specs/engines/Screener-Specification.md](specs/engines/Screener-Specification.md) | Sole eligibility engine; Strategy consumes Screeners |
| 2.14d | [specs/engines/Analytics-Architecture-Specification.md](specs/engines/Analytics-Architecture-Specification.md) | SD-031 analytics ownership |
| 2.14e | [specs/engines/Market-Analysis-Engine-Specification.md](specs/engines/Market-Analysis-Engine-Specification.md) | SD-032 market analytics / sentiment / phase |
| 2.14f | [specs/engines/Dashboard-Specification.md](specs/engines/Dashboard-Specification.md) | Dashboard page purpose |
| 2.14g | [specs/engines/Watchlist-Specification.md](specs/engines/Watchlist-Specification.md) | Watchlist research workspace |
| 2.14h | [specs/engines/Portfolio-Specification.md](specs/engines/Portfolio-Specification.md) | Holdings / Portfolio workspace |
| 2.14i | [specs/engines/Portfolio-Analytics-Specification.md](specs/engines/Portfolio-Analytics-Specification.md) | Portfolio-wide analytics + market context |
| 2.14j | [specs/engines/Strategy-Specification.md](specs/engines/Strategy-Specification.md) | Strategy overview + market gates |
| 2.14k | [specs/engines/Discovery-Specification.md](specs/engines/Discovery-Specification.md) | Discovery page purpose |
| 2.14l | [specs/engines/Indicator-Registry-Specification.md](specs/engines/Indicator-Registry-Specification.md) | SD-033 unified Indicator Registry (specialization of SD-034) |
| 2.14m | [specs/engines/Indicator-Registry-API.md](specs/engines/Indicator-Registry-API.md) | Admin `GET /api/v1/indicators*` API |
| 2.14n | [specs/engines/Trading-Artifact-Framework-Specification.md](specs/engines/Trading-Artifact-Framework-Specification.md) | SD-034 Trading Artifact Framework (**registry infrastructure landed**) |
| 2.14o | [specs/engines/Trading-Artifact-JSON-Specification.md](specs/engines/Trading-Artifact-JSON-Specification.md) | Declarative JSON formats for Indicator / Screener / Strategy |
| 2.14p | [specs/engines/Trading-Artifact-Registry-Migration.md](specs/engines/Trading-Artifact-Registry-Migration.md) | Registry infrastructure migration / BC guide |
| 2.14q | [specs/engines/Screener-Registry-Migration.md](specs/engines/Screener-Registry-Migration.md) | Screener Registry (metadata, versions, import/export UI) migrate notes |
| 2.14r | [specs/engines/Strategy-Registry-Migration.md](specs/engines/Strategy-Registry-Migration.md) | Strategy Registry (selection, portable JSON, Minervini migrate) |
| 2.14s | [specs/engines/StoX-Trading-Artifacts-AI-Guide.md](specs/engines/StoX-Trading-Artifacts-AI-Guide.md) | **AI download pack** — Introduction + **AI Authoring Contract (normative)** + Hard Rules + Workflow + Registries + Cookbook + Examples + Runtime appendix (`/docs/stox-trading-artifacts-ai-guide.md`) |
| 2.14q | [specs/engines/artifacts/README.md](specs/engines/artifacts/README.md) | Worked JSON examples (Momentum, Swing, Liquidity, Breakout, …) |
| 2.15 | [specs/engines/Notification-Engine-Specification.md](specs/engines/Notification-Engine-Specification.md) | |
| 2.16 | [specs/engines/Execution-Engine-Specification.md](specs/engines/Execution-Engine-Specification.md) | |
| 2.17 | [specs/engines/Review-Engine-Specification.md](specs/engines/Review-Engine-Specification.md) | |

### 2.D Original implementation plan (intent timeline)

| Order | Document |
|------:|----------|
| 2.18 | [specs/engines/Implementation-Roadmap.md](specs/engines/Implementation-Roadmap.md) | Includes append-only **Version 1.0 Baseline** pointer to governance |

---

## 3. Governance & Version 1.0 scope

*Bridge between intent (§2) and what V1.0 actually ships. Read after architecture.*

Hub: [specs/governance/README.md](specs/governance/README.md)

| Order | Document | Why |
|------:|----------|-----|
| 3.1 | [specs/governance/DOCUMENT_PRECEDENCE.md](specs/governance/DOCUMENT_PRECEDENCE.md) | How to resolve conflicts |
| 3.2 | [specs/governance/SPECIFICATION_DECISIONS.md](specs/governance/SPECIFICATION_DECISIONS.md) | Accepted deviations (SD-xxx) |
| 3.3 | [specs/governance/MVP_SCOPE.md](specs/governance/MVP_SCOPE.md) | What is in / out of V1.0 |
| 3.4 | [specs/governance/VERSION_1_BASELINE.md](specs/governance/VERSION_1_BASELINE.md) | Frozen baseline |
| 3.5 | [specs/governance/PRODUCT_BACKLOG.md](specs/governance/PRODUCT_BACKLOG.md) | Deferred work / roadmap |

---

## 4. Implementation status & audit

*Evidence of what was built and verified. Read after governance.*

| Order | Document | Why |
|------:|----------|-----|
| 4.1 | [specs/IMPLEMENTATION_PROGRESS.md](specs/IMPLEMENTATION_PROGRESS.md) | Working log + assumptions A1–A13 |
| 4.2 | [specs/MVP_DEMO_CHECKLIST.md](specs/MVP_DEMO_CHECKLIST.md) | Human acceptance demo |
| 4.3 | [specs/audit/README.md](specs/audit/README.md) | Audit pack index |
| 4.4 | [specs/audit/MVP_VERDICT.md](specs/audit/MVP_VERDICT.md) | Final YES/NO + release posture |
| 4.5 | [specs/audit/SPECIFICATION_TRACEABILITY.md](specs/audit/SPECIFICATION_TRACEABILITY.md) | Spec → code matrix |
| 4.6 | [specs/audit/ARCHITECTURE_COMPLIANCE.md](specs/audit/ARCHITECTURE_COMPLIANCE.md) | Engine/layer compliance |
| 4.7 | [specs/audit/DATABASE_MAPPING.md](specs/audit/DATABASE_MAPPING.md) | Domain → tables |
| 4.8 | [specs/audit/API_INVENTORY.md](specs/audit/API_INVENTORY.md) | `/api/v1` endpoints |
| 4.9 | [specs/audit/UI_INVENTORY.md](specs/audit/UI_INVENTORY.md) | TOS pages |
| 4.10 | [specs/audit/MVP_TEST_SCRIPT.md](specs/audit/MVP_TEST_SCRIPT.md) | End-to-end manual test |
| 4.11 | [specs/audit/KNOWN_LIMITATIONS.md](specs/audit/KNOWN_LIMITATIONS.md) | Current limits |
| 4.12 | [specs/audit/TECHNICAL_DEBT.md](specs/audit/TECHNICAL_DEBT.md) | Debt register |
| 4.13 | [specs/audit/REPOSITORY_OVERVIEW.md](specs/audit/REPOSITORY_OVERVIEW.md) | Folder map |
| 4.14 | [specs/audit/PROJECT_STATISTICS.md](specs/audit/PROJECT_STATISTICS.md) | Counts / LOC |

---

## 5. Living technical reference (implementation how-to)

*Use daily for coding and local setup. Complements — does not replace — §2–§4.*

| Order | Document | Why |
|------:|----------|-----|
| 5.1 | [implementation.md](implementation.md) | **Primary living technical reference** (agents: keep updated) |
| 5.2 | [debugging.md](debugging.md) | Production debug hooks / runbook |
| 5.3 | [app/API_DOCUMENTATION.md](app/API_DOCUMENTATION.md) | Legacy / broader API notes |
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
├── README.md                 ← specs hub (links here + DOCS.md)
├── IMPLEMENTATION_PROGRESS.md
├── MVP_DEMO_CHECKLIST.md
├── architecture/             ← intent + indicator as-built (08) + Registry (09–10) + Artifact Framework (11)
├── engines/                  ← engine + contract specs + roadmap
├── governance/               ← V1.0 decisions, scope, backlog, baseline
└── audit/                    ← freeze audit evidence
```

---

## Maintenance rules

1. **New Markdown that defines product/requirements/ops guidance** → add a link in this file (correct §) **in the same session**. Cursor rule: `.cursor/rules/Keep-DOCS-md-ingestion-tree-updated.mdc`.  
2. If the file lives under `specs/`, also link it from [specs/README.md](specs/README.md) (and governance/audit README when appropriate).  
3. **New accepted deviation** → `SPECIFICATION_DECISIONS.md`, not by rewriting architecture specs.  
4. **New feature after V1.0** → `PRODUCT_BACKLOG.md` then scope/baseline updates.  
5. **Code change** → update [implementation.md](implementation.md) in the same session.  
6. Keep **original architecture/engine specs** as historical intent (see governance).
