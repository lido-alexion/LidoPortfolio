# 11 — Trading Artifact Framework (Target Architecture)

| Field | Value |
|-------|-------|
| **Document** | 11 — Trading Artifact Framework |
| **Version** | 1.0 |
| **Status** | Accepted design (SD-034) — **infrastructure implemented**; remaining phases are **V5 (V4-FEAT-008)** |
| **Owner** | Architecture |
| **Depends On** | 03 Core Concepts, 04 System Architecture, 06 Engine Overview, [09 Indicator Registry](./09-Indicator-Registry.md), Screener Spec, Strategy Configuration Spec |
| **Engine spec** | [../domains/Trading-Artifact-Framework-Specification.md](../domains/Trading-Artifact-Framework-Specification.md) |
| **JSON formats** | [../domains/Trading-Artifact-JSON-Specification.md](../domains/Trading-Artifact-JSON-Specification.md) (canonical format; runtime envelope I/O shipped) |

---

# 1. Purpose

Define a **reusable Trading Artifact Framework** that elevates Indicators, Screeners, and Strategies from isolated product features into a common, AI-friendly artifact model.

This supersedes the narrower “Strategy Template” idea: templates are one use of **Strategy artifacts**, not a separate product line.

**This document is architecture intent.** Phases 1–2 style infrastructure (envelope, package I/O, Indicator/Screener/Strategy registries, Create/Enable/Archive, AI authoring/runtime docs) **shipped**. Remaining SD-034 work is **V5 V4-FEAT-008** (immutable published versions vs Save-in-place, sharing/distribution, extra AI draft UX, dependency dashboards, rollback, bundle UI, fork workflows) — **not** an active V4 implementation target. Do not treat shipped registries as unimplemented.

---

# 2. Problem Statement

Today the application already has three related but unevenly modeled capabilities:

| Capability | As-built shape | Gap |
|------------|----------------|-----|
| **Indicators** | Dual catalogues → Indicator Registry (SD-033) | Strong metadata path; still type-specific |
| **Screeners** | DB rows + `definition_json` condition tree | Reusable in practice; weak shared metadata / versioning / portability |
| **Strategies** | One editable config per portfolio + `config_json` | Powerful runtime config; not yet a first-class reusable/shareable definition |

We need one framework so that:

- Artifacts share **metadata, lifecycle, versioning, validation, import/export, registry, and dependency** patterns
- AI assistants can **discover, propose, validate, and package** artifacts safely
- Future sharing (export packs, community libraries) does not require redesign

**2026-08-28 status:** Envelope, package I/O, Indicator/Screener/Strategy registries, Create/Enable/Archive, and AI authoring/runtime docs are **shipped**. The table above is the pre-implementation gap. Remaining work is **V5 V4-FEAT-008**, not active V4.

---

# 3. Design Stance

```text
EVOLVE — do not rewrite
───────────────────────
Indicator Registry (SD-033)  →  specializes Artifact Framework for Indicators
Screener definition_json     →  remains the Screener definition core
Strategy config_json         →  remains the Strategy definition core
Strategy Template proposal   →  absorbed as Strategy artifact + factory/import patterns
```

**Non-negotiables:**

1. Preserve SD-028 — no plugin formula runtime; indicators ship in releases.
2. Preserve SD-030 — Strategies **reference** Screeners; never copy condition trees.
3. Preserve Screener condition model — keep `definition_json` as the eligibility definition format.
4. Preserve calculation ownership — Registry/Framework describe; engines calculate.
5. Prefer façades and envelopes over greenfield tables that duplicate logic.

---

# 4. Target Architecture

```text
                    ┌─────────────────────────────────────┐
                    │     Trading Artifact Framework      │
                    │  common metadata · lifecycle ·      │
                    │  versioning · validate · I/O · deps │
                    └──────────────────┬──────────────────┘
                                       │
          ┌────────────────────────────┼────────────────────────────┐
          ▼                            ▼                            ▼
 ┌─────────────────┐        ┌─────────────────┐        ┌─────────────────┐
 │ Indicator       │        │ Screener        │        │ Strategy        │
 │ Artifact        │        │ Artifact        │        │ Artifact        │
 │ Registry        │        │ Registry        │        │ Registry        │
 │ (SD-033)        │        │ (evolve)        │        │ (evolve)        │
 └────────┬────────┘        └────────┬────────┘        └────────┬────────┘
          │                          │                          │
          │ metadata                 │ definition_json          │ config_json
          ▼                          ▼                          ▼
   Calculators                 ScreenerRunService         StrategyConfiguration
   (TI / Evaluation / …)       (eligibility)              + Recommendation
```

**Umbrella vs specializations**

| Layer | Owns |
|-------|------|
| **Trading Artifact Framework** | Shared envelope, lifecycle states, version rules, import/export schema, dependency graph API, validation orchestration, AI-facing catalogue |
| **Indicator Artifact Registry** | Indicator metadata SoT (already designed as SD-033) |
| **Screener Artifact Registry** | Screener metadata + versions wrapping existing screeners |
| **Strategy Artifact Registry** | Strategy definition library + binding to portfolio active config |

---

# 5. Artifact Family (MVP of the Framework)

| Artifact type | Definition payload | Mutability | Ships how |
|---------------|-------------------|------------|-----------|
| `indicator` | Registry metadata (+ code-backed calc) | Release-shipped; Admin views metadata | Application release |
| `screener` | `definition_json` condition tree | User/factory editable | DB (+ factory seed) |
| `strategy` | `config_json` philosophy sections | User/factory editable | DB (+ factory seed) |

**Future types (reserved, not designed in depth):** `pattern_pack`, `alert_recipe`, `watchlist_recipe`, `evaluation_profile`.

---

# 6. Answers at a Glance (Design Questions)

Full detail lives in the [engine specification](../domains/Trading-Artifact-Framework-Specification.md). Summary:

| # | Question | Recommendation |
|---|----------|----------------|
| 1 | Trading Artifact model | Typed envelope + type-specific `definition`; common identity and provenance |
| 2 | Common metadata | Identity, taxonomy, lifecycle, provenance, AI fields, capability hints |
| 3 | Registry architecture | Umbrella Artifact Registry + type registries; Indicator Registry is the indicator specialization |
| 4 | Lifecycle | `draft → active → deprecated → archived` (+ optional `imported`) |
| 5 | Versioning | Immutable published versions; draft mutable; indicators release-tied |
| 6 | Import/Export | Portable JSON package (`schema_version` + artifact + deps + checksum) |
| 7 | Validation | Per-type schema + dependency + semantic rules; AI output must pass same gates |
| 8 | Dependencies | Declared DAG (Strategy→Screener→Indicator; Indicator→Indicator) |
| 9 | Extensibility | New types via handler registration; still no arbitrary code plugins |
| 10 | AI compatibility | JSON Schema + intent prose + catalogues + validate-before-activate |

---

# 7. Screener Evolution (High Level)

```text
portfolio_screeners (today)
  id, name, definition_json, factory_key, …
        │
        ▼ evolve
Screener Artifact
  metadata envelope
  + definition = existing definition_json   ← unchanged core format
  + versions / export / registry listing
```

Avoid redesigning the condition tree. See spec §12.

---

# 8. Strategy Evolution (High Level)

```text
“One editable strategy per portfolio” (V1 runtime binding)
        │
        ▼ evolve
Strategy Artifact Library (reusable definitions)
  + Portfolio Binding (which artifact version is active)
  + config_json remains the definition core
  + depends_on → Screener artifacts (by stable id / slug + version pin)
```

“Strategy Template” = factory or exported Strategy artifact used as a starting point (fork), not a separate subsystem. See spec §13.

---

# 9. Relationship to Indicator Registry (SD-033)

Indicator Registry **is** the Indicator specialization of this framework.

- Do **not** replace SD-033 docs; treat them as the Indicator type handbook.
- Shared concerns (AI fields, portable package envelope, cross-type dependency API) live in this framework and are adopted by Indicator Registry in a later alignment phase.
- Indicator non-goals (no plugins, no user formulas) remain binding.

---

# 10. Roadmap Summary

| Phase | Outcome | Status (2026-08-28) |
|------:|---------|---------------------|
| 0 | Specs + SD-034 (this document set) | Done |
| 1 | Shared envelope schema + documentation alignment (no behaviour change) | **Shipped** (envelope / validation) |
| 2 | Screener artifact metadata / export-import (keep `definition_json`) | **Shipped** (Screener Registry + package I/O) |
| 3 | Strategy artifact library + portfolio binding (preserve active Save UX initially) | **Partial:** Strategy Registry + Create/Enable/Archive shipped; remaining dual library/binding UX and immutable published versions are **V5 V4-FEAT-008** |
| 4 | Cross-artifact dependency resolver + validation orchestration | **Partial:** umbrella `ArtifactRegistry` + validate/import/export shipped; remaining dependency dashboards / rollback are **V5 V4-FEAT-008** |
| 5 | AI catalogue APIs + validate-before-activate for generated drafts | **Partial:** AI authoring/runtime docs + prompt builder shipped; extra draft-from-schema catalogue UX is **V5 V4-FEAT-008** |
| 6 | Optional sharing / pack distribution (out of V1.x) | **V5 V4-FEAT-008** |

Indicator Registry implementation (PB-055+) proceeds **in parallel** as the Indicator track; Framework phases must not block Registry Epics 1–3. Remaining TAF work is **not** an active V4 target.

---

# 11. Related Documents

- Full specification: [../domains/Trading-Artifact-Framework-Specification.md](../domains/Trading-Artifact-Framework-Specification.md)
- Indicator specialization: [./09-Indicator-Registry.md](./09-Indicator-Registry.md)
- Screener: [../domains/Screener-Specification.md](../domains/Screener-Specification.md)
- Strategy: [../domains/Strategy-Configuration-Specification.md](../domains/Strategy-Configuration-Specification.md)
- Governance: [SD-034](../governance/SPECIFICATION_DECISIONS.md)
