# Document Precedence

**Document:** Governance — Document Precedence  
**Version:** 1.0  
**Status:** Active  
**Effective:** 2026-07-25  

---

## Purpose

Define the order of authority among project documents so conflicts are resolved consistently without rewriting historical specifications.

**Navigation (not authority):** For a complete file tree and recommended reading order, see [../../DOCS.md](../../DOCS.md) and [../README.md](../README.md).

---

## Hierarchy

```text
Vision
  (specs/architecture/01-Vision.md)
        ↓
Architecture Specifications
  (02 Guiding Principles → 03 Core Concepts → 04 System Architecture
   → 05 Daily Decision Pipeline → 06 Engine Overview)
        ↓
Engine Specifications
  (Data, Evaluation, Recommendation, Notification, Execution, Review,
   Application Architecture, REST API, Database Schema, System Domain Model,
   Screener, Strategy Configuration, Analytics, Market Analysis,
   Indicator Registry (SD-033), Trading Artifact Framework (SD-034),
   Implementation Roadmap)
        ↓
Governance Documents
  (SPECIFICATION_DECISIONS, MVP_SCOPE, VERSION_1_BASELINE,
   PRODUCT_BACKLOG, this precedence guide)
        ↓
Implementation
  (application code, migrations, config, UI)
        ↓
Backlog
  (PRODUCT_BACKLOG items not yet accepted into a baseline)
```

Supporting operational docs (not in the intent chain, but binding for ops):

- `implementation.md` — living technical notes for the repo  
- `specs/audit/*` — freeze audit evidence (read-only historical record)  
- `specs/MVP_DEMO_CHECKLIST.md` — V1.0 acceptance demo  
- `deploy/*` — production deploy procedures  

---

## What each layer means

| Layer | Role |
|-------|------|
| **Vision / Architecture / Engine specs** | Architectural **intent** and long-term design reference. Define what “good” looks like for the Trading Operating System over time. |
| **Governance** | **Accepted implementation decisions**, Version 1.0 **scope**, **baseline freeze**, and **roadmap** of deferred work. Bridge between intent and code. |
| **Implementation** | What currently runs. Must satisfy Vision principles and Governance-accepted scope. |
| **Backlog** | Work not yet in the active baseline. Does not override Governance or specs until accepted into a release baseline. |

---

## Conflict resolution

1. **Intent vs V1.0 code**  
   If implementation differs from engine/architecture specs, check [`SPECIFICATION_DECISIONS.md`](./SPECIFICATION_DECISIONS.md).  
   - If an **Accepted** or **Deferred** SD exists → treat as governed; do not “fix” code to match the old SHALL without a new decision.  
   - If no SD exists → either align implementation to specs **or** add an SD with explicit Status before shipping.

2. **Scope disputes (“is X in V1.0?”)**  
   [`MVP_SCOPE.md`](./MVP_SCOPE.md) wins over informal chat and over aspirational SHALLs that were deferred.

3. **Baseline disputes (“what did we freeze?”)**  
   [`VERSION_1_BASELINE.md`](./VERSION_1_BASELINE.md) plus the referenced audit pack.

4. **Priority of future work**  
   [`PRODUCT_BACKLOG.md`](./PRODUCT_BACKLOG.md). Architecture specs may describe Future Scope; backlog assigns release targets.

5. **Principles always bind**  
   Guiding Principles (trust, explainability, determinism, human control) are not waived by convenience. An SD that would violate them requires explicit Architecture-level reconsideration (new ADR/SD with Rejected alternatives documented).

6. **Audit vs Governance**  
   Audit describes **as-of** findings. Governance is the **ongoing** authority for decisions and scope. If they disagree after a change, update Governance (and optionally add a new audit), do not rewrite old audit files in place without versioning.

---

## Explicit rules

1. **Original specifications define architectural intent.**  
   They MUST NOT be modified to match implementation decisions (except factual error / broken link fixes, or append-only notes such as the Version 1.0 Baseline section on the Implementation Roadmap).

2. **Governance documents define accepted implementation decisions and V1.0 scope.**  
   They are the official bridge between specs and code.

3. **Implementation must follow both:**  
   - Honour Vision / principles / engine boundaries as intent.  
   - Honour Governance for Accepted deviations and Included/Excluded scope.

4. **Future work updates Governance (and backlog), not historical specs.**  
   When behaviour changes again, add/update SD-xxx and baseline notes; leave v0.1/v1.0 Draft specs intact as history of intent.

5. **Rejected decisions** stay in the SD register with Status = Rejected so the same debate is not repeated without new evidence.

---

## Quick reference

| Question | Consult |
|----------|---------|
| Why don’t we use JWT? | SD-001 in SPECIFICATION_DECISIONS |
| Is Strategy in V1.0? | MVP_SCOPE (Excluded) + SD-007 |
| What ships in V1.0? | MVP_SCOPE |
| What is frozen? | VERSION_1_BASELINE |
| What do we build next? | PRODUCT_BACKLOG |
| Did V1.0 pass audit? | `specs/audit/MVP_VERDICT.md` |
| How should engines relate long-term? | Architecture + Engine specs (intent) |
