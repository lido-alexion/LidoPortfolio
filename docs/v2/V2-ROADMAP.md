# V2 Roadmap (Planning)

**Date:** 2026-08-09  
**Status:** Planning only — no calendar dates, no story points, no implementation tasks  
**V1:** Frozen at 119 capabilities (SD-035) — [final audit](../audits/2026-08-09-feature-coverage-final/)

---

## Roadmap principles

1. **Do not reopen V1 scope** — V2 formalizes deferred capabilities only.  
2. **Most V2 items are already shipped** — V2 work is primarily hardening, specification, and test coverage.  
3. **Respect dependencies** — especially F042 → F043 and F019 → F014.  
4. **Avoid duplicate infrastructure** — alerts, notifications, auth, and data-quality paths already exist.  
5. **Postpone low-leverage fully-shipped features** — F143, F144 until product stabilizes.

---

## Phase 1 — Foundational (highest value / enables other V2)

**Parallel tracks:**

### Track A: Account & Access Management

| Feature | Work type |
|---------|-----------|
| **F003** User invite flow | Formalize V2 spec; harden token/expiry; align admin UX |
| **F005** Session management | Complete Settings UI; extend `AuthSessionTest` coverage |

**Rationale:** Multi-user deployment posture (`implementation.md` invite-only registration). Shares Sanctum + admin infrastructure with V1 F004 password reset.

**Prerequisites:** None beyond frozen V1 auth.

**Unlocks:** F060 collaboration semantics; cleaner multi-user ops.

**Risks:** Security regressions on invite/session tokens.

---

### Track B: Market Data Quality (anchor)

| Feature | Work type |
|---------|-----------|
| **F042** Data quality detection/resolution | Formalize V2 spec for Data Quality Center; add PHPUnit coverage; ops runbooks |

**Rationale:** Highest strategic leverage; complements V1 F020; prerequisite for safe F043.

**Prerequisites:** V1 OHLCV sync, V1 F020 corporate actions.

**Unlocks:** F043 price repair; improved confidence in pipeline inputs.

**Risks:** Incorrect auto-resolution corrupts OHLCV.

---

## Phase 2 — Operations & monitoring (enabled by Phase 1)

| Feature | Work type |
|---------|-----------|
| **F043** Corporate action price repair | Formalize after F042; harden cpanel scripts; expand tests |
| **F127** Portfolio alerts (non-TOS) | Spec alignment; harden existing alert framework; clarify vs TOS Telegram |

**Rationale:** F043 depends on F042 issue model. F127 is fully implemented — V2 is governance + hardening, not greenfield.

**Prerequisites:** Phase 1 Track B (F042) for F043; daily price sync for F127.

**Unlocks:** Production ops confidence; holding-level monitoring distinct from TOS recs.

**Risks:** F043 price mutation errors; F127 alert noise / user confusion with Telegram TOS notifications.

---

## Phase 3 — Portfolio data & platform APIs

| Order | Feature | Work type |
|------:|---------|-----------|
| 1 | **F019** Bulk CSV import | Import validation hardening; PHP feature tests; error reporting |
| 2 | **F014** Historical holdings reconstruction | Dedicated UI on existing service; as-of analytics UX |
| 3 | **F060** Shared screener import | Formalize V2 after F003; cross-portfolio sharing rules |
| 4 | **F137** Recommendation preview API | API contract stability; feature tests; document consumers |

**Rationale:** F019 validation patterns reduce risk before F014 analytics UI. F060 benefits from account initiative. F137 is platform convenience after core V2 stable.

**Prerequisites:** Phase 1 Track A (recommended for F060); frozen V1 pipeline (for F137).

**Unlocks:** Bulk onboarding; portfolio history analytics; screener collaboration; research preview API.

**Risks:** Ledger corruption (F019); scope mapping errors (F060).

---

## Phase 4 — Knowledge & guidance (deliberately late)

| Feature | Work type |
|---------|-----------|
| **F143** In-app contextual help | Content governance process; reduce drift vs UI changes |
| **F144** Knowledge Board | Formal V2 scope only if Knowledge product is strategic priority |

**Rationale:** Both are **fully implemented**. Formal V2 adds ongoing maintenance obligation. Defer until V1/V2 core churn slows.

**Prerequisites:** Stable page set and navigation.

**Unlocks:** Better onboarding documentation; research capture (already available informally).

**Risks:** Documentation drift; editor/content maintenance cost.

---

## Phase summary

| Phase | Features | Initiative(s) | Primary outcome |
|-------|----------|---------------|-----------------|
| **1** | F003, F005, F042 | Account & Access; Market Data Quality | Multi-user readiness + data trust |
| **2** | F043, F127 | Market Data Quality (cont.); Monitoring | Safe ops repair + holding alerts |
| **3** | F019, F014, F060, F137 | Portfolio History; Collaboration; Platform API | Data onboarding + research tools |
| **4** | F143, F144 | Knowledge & Guidance | UX polish (optional / late) |

---

## Recommended first V2 initiative

**Start with Phase 1 Track B: Market Data Quality (F042)** if production hardening is the top priority.

**Start with Phase 1 Track A: Account & Access (F003 + F005)** if multi-user deployment is the top priority.

**Do not start F043, F014, F060, F143, or F144 as the first V2 initiative.**

---

## Explicitly not in this roadmap

| Item | Reason |
|------|--------|
| V1 capability changes | V1 frozen |
| Email/webhook notifications | V1_OUT_OF_SCOPE (SD-009 Telegram-only) |
| F040 hard data publish gates | V1_OUT_OF_SCOPE (PB-001) |
| New capabilities beyond the 11 | Out of scope for this analysis |
| Implementation tasks / coding prompts | Planning phase only |

---

*See also: [V2-PRIORITIZATION.md](./V2-PRIORITIZATION.md), [V2-DEPENDENCIES.md](./V2-DEPENDENCIES.md)*
