# Indicator Registry — Implementation Plan

| Field | Value |
|-------|-------|
| **Document** | Indicator Registry Implementation Plan |
| **Version** | 1.0 |
| **Status** | Epics 1–3 done; Epic 4 Liquidity/Tradability V1 calculators done (Strategy wiring deferred); Epic 5–7 partial |
| **Date** | 2026-07-30 |
| **Governing decision** | [SD-033](../governance/SPECIFICATION_DECISIONS.md) |
| **Specs** | [../domains/Indicator-Registry-Specification.md](../domains/Indicator-Registry-Specification.md) · [./09-Indicator-Registry.md](./09-Indicator-Registry.md) |
| **As-built** | [./08-Indicator-Architecture-Analysis.md](./08-Indicator-Architecture-Analysis.md) |
| **Backlog** | PB-054, PB-055, PB-056, PB-057 · TD-19 |

---

## 1. Purpose

Break SD-033 into **incremental Epics → Stories → Tasks** so the application stays functional after every Story. No big-bang rewrite. No production code in this plan.

---

## 2. Guiding constraints

1. **Preserve calculators:** `TechnicalIndicatorService`, `EvaluationEngine`, `StrategyConfigurationService::score` stay owners of calculation / scoring.
2. **SD-028:** No plugins, no user formula engine, release-shipped definitions only.
3. **Ship-safe increments:** Each Story ends with green tests and unchanged user-visible behaviour unless the Story explicitly changes behaviour.
4. **Separate tracks:** Epic 6 (Strategy parameter wiring / TD-19) must not block Epics 1–3.
5. **Repo workflow note:** This repository normally lands work on `master` via sequential commits. Treat each Story (or noted PR-split) as an **independently reviewable increment** — same granularity whether landed as a commit series or a PR if process changes.

---

## 3. Phase map (Epic → backlog)

| Epic | Name | Primary backlog | Suggested release |
|-----:|------|-----------------|-------------------|
| 1 | Indicator Registry Foundation | PB-055 (partial) | 1.1 |
| 2 | Registry Migration (façades) | PB-055 (partial) | 1.1 |
| 3 | Indicator Registry Admin UI | PB-055 (partial) | 1.1 |
| 4 | New Indicators (Liquidity / Tradability) | PB-057 | 1.2 |
| 5 | Consumer Integration | PB-055 / PB-056 | 1.1 → 1.2 |
| 6 | Strategy Parameter Wiring | PB-054 / TD-19 | 1.1 (parallel-safe) |
| 7 | Testing / Documentation | All | Continuous + close-out |

**Recommended global order:** Epic 1 → Epic 2 → Epic 3 → Epic 5 (discovery APIs) → Epic 6 (can start after Epic 1 in parallel) → Epic 5 (versions) → Epic 4 → Epic 7 close-out.

```text
Epic1 Foundation ──► Epic2 Migration ──► Epic3 Admin UI ──┐
       │                                                   ├──► Epic5 Consumers
       └──────────────► Epic6 Param Wiring (parallel) ────┘
                                                              │
                                                              ▼
                                                         Epic4 New Indicators
                                                              │
                                                              ▼
                                                         Epic7 Docs/Tests polish
```

---

## 4. Epic 1 — Indicator Registry Foundation

**Goal:** Introduce an in-code Registry module seeded from existing catalogues, with **no behaviour change** for Screener / Strategy / Evaluation.

**Exit criteria:** Registry can list all current screenable + strategy-scorable + known metric entries; unit tests assert parity with today’s catalogues; production paths still call `ScreenerCatalog` / `SupportedIndicators` unchanged.

---

### Story 1.1 — Registry domain model & empty service shell

| Field | Content |
|-------|---------|
| **Objective** | Add typed Registry structures without wiring consumers. |
| **Description** | Create `IndicatorRegistry` (or equivalent) with value objects / arrays for metadata fields from the spec (id, type, category, version, depends_on, parameters, capabilities, consumers, status, etc.). Service methods: `all()`, `get($id)`, `filter(...)`. No HTTP yet. Seed returns empty or fixture-only in tests. |
| **Files likely to change** | New: `app/app/Services/Indicators/IndicatorRegistry.php` (and small DTO/helpers if used); optional `app/app/Services/Indicators/IndicatorDefinition.php`; `app/tests/Unit/Indicators/*`; service provider binding if needed. |
| **Dependencies** | None (first Story). |
| **Risks** | Over-engineering DTOs (SD-013 prefers pragmatism) — keep array shapes + thin helpers. |
| **Backward compatibility** | No callers yet → zero product impact. |
| **Test strategy** | Unit: construct definition; filter by type/capability; reject unknown ids. |
| **Complexity** | **S** |
| **Suggested order** | **1st** in Epic 1. |
| **Tasks** | 1) Add namespace + class skeleton. 2) Document field shape in PHPDoc matching spec §6. 3) Unit tests for empty registry. 4) Bind in container if used elsewhere later. |
| **PR split** | Single small PR/commit. |

---

### Story 1.2 — Seed Primaries from ScreenerCatalog (read-only mirror)

| Field | Content |
|-------|---------|
| **Objective** | Populate Registry Primaries to match current `ScreenerCatalog::indicators()`. |
| **Description** | Seeder/builder maps each ScreenerCatalogue row → Registry Primary (`screenable=true`, category inferred, units/precision defaults, `status=active`). Keep `ScreenerCatalog` as source of truth **or** dual-define once with a parity test — prefer **single seed function that reads ScreenerCatalog** so no duplication yet. |
| **Files likely to change** | `IndicatorRegistry` seed; maybe `ScreenerCatalog` (read-only helpers); `tests/Unit/Indicators/RegistryScreenerParityTest.php`. |
| **Dependencies** | Story 1.1. |
| **Risks** | Category inference guesses wrong labels — document mapping table in seed comments. |
| **Backward compatibility** | No production callers of Registry yet. |
| **Test strategy** | Assert Registry screenable IDs == `ScreenerCatalog::indicatorIds()`; param keys match. |
| **Complexity** | **M** |
| **Suggested order** | **2nd**. |
| **Tasks** | 1) Mapping table category/units defaults. 2) Seed from catalogue. 3) Parity tests. 4) Mark `needs_volume` in capabilities. |
| **PR split** | Own PR/commit after 1.1. |

---

### Story 1.3 — Seed Composites from SupportedIndicators

| Field | Content |
|-------|---------|
| **Objective** | Register Strategy composites with deps + formula_explanation stubs. |
| **Description** | Map `SupportedIndicators::definitions()` → type `composite`, `strategy_scorable=true`, `evaluation_fact=true`. Add declared `depends_on` per as-built analysis. Add `formula_explanation` prose (docs only). Stubs (`market_regime`, `sector_strength`) get `status=stub`, empty deps. |
| **Files likely to change** | Registry seed; possibly thin additions on `SupportedIndicators` (comments only); `tests/Unit/Indicators/RegistryStrategyParityTest.php`. |
| **Dependencies** | Story 1.1; ideally 1.2 (deps reference Primary ids). |
| **Risks** | Breakout dependency on `discovery_pattern_count` — register as Metric or document logical id. |
| **Backward compatibility** | No runtime use yet. |
| **Test strategy** | Keys == `SupportedIndicators::keys()`; aliases preserved; every composite has `depends_on` key (array, maybe empty). |
| **Complexity** | **M** |
| **Suggested order** | **3rd**. |
| **Tasks** | 1) Dep map from analysis §4. 2) Formula explanation strings. 3) Parity tests. 4) Consumer tags (`strategy`, `evaluation`, `recommendation`). |
| **PR split** | Own PR/commit; can merge with 1.2 if small. |

---

### Story 1.4 — Seed Metrics from Stock Analytics (discoverable only)

| Field | Content |
|-------|---------|
| **Objective** | Register descriptive Metrics without making them screenable. |
| **Description** | Add Registry entries for existing Stock Analytics fields (e.g. distance 52w, HV, beta, trend_strength, sma_50/200 as metrics or note overlap with Primaries). `screenable=false`, `status=active`, consumers `stock_details`, `portfolio_analytics`, `dashboard` as appropriate. |
| **Files likely to change** | Registry seed; comments in `StockAnalyticsService`; unit tests. |
| **Dependencies** | Story 1.1. |
| **Risks** | Confusing duplicate `sma_50` Primary vs Metric — prefer referencing Primary ids where identical; Metrics only for descriptive-only fields. |
| **Backward compatibility** | Analytics APIs unchanged. |
| **Test strategy** | Metrics filter returns expected ids; none marked screenable unless intentional. |
| **Complexity** | **S** |
| **Suggested order** | **4th** (can parallel with 1.3). |
| **Tasks** | 1) Inventory Stock Analytics payload keys. 2) Seed Metrics. 3) Tests. |
| **PR split** | Independent PR from 1.3. |

---

### Story 1.5 — Planned indicators as `status=planned` (no calculators)

| Field | Content |
|-------|---------|
| **Objective** | Register Liquidity/Tradability Primaries + Composites as planned metadata. |
| **Description** | Add planned IDs from spec §12 with deps trees; **no** TI/`EvaluationEngine` formula code. Admin/API later will show them as Planned. |
| **Files likely to change** | Registry seed; unit tests asserting planned set. |
| **Dependencies** | Stories 1.1–1.3. |
| **Risks** | Product confusion that Planned means available in Screener — UI must show status (Epic 3). |
| **Backward compatibility** | No calc → no score changes. |
| **Test strategy** | Planned ids present; `liquidity_score` depends_on contains three primaries; not in screenable active set. |
| **Complexity** | **S** |
| **Suggested order** | **5th** (end of Epic 1). |
| **Tasks** | 1) Seed planned primaries. 2) Seed liquidity/tradability composites. 3) Tests. |
| **PR split** | Small standalone PR. |

**Epic 1 done when:** Registry parity tests green; app behaviour identical to pre-Epic-1.

**Epic 1 status (2026-07-30):** **Complete** — `App\Services\Indicators\*` + `IndicatorRegistryFactory` + `tests/Unit/Indicators/IndicatorRegistryTest.php`. No production consumer wiring.

---

## 5. Epic 2 — Registry Migration

**Goal:** Make `ScreenerCatalog` and `SupportedIndicators` **project from** the Registry without changing API payloads (byte-stable or field-compatible).

**Exit criteria:** Meta/catalogue endpoints return equivalent data; existing feature tests pass; Registry is authoritative for those façades.

---

### Story 2.1 — ScreenerCatalog façade over Registry (feature flag or soft switch)

| Field | Content |
|-------|---------|
| **Objective** | `ScreenerCatalog::indicators()` / `meta()` built from Registry screenable actives. |
| **Description** | Refactor catalogue methods to map Registry → existing response shape (`id`, `label`, `params`, `min_bars`, `needs_volume`). Keep operators/scopes constants on ScreenerCatalog. Optionally behind config `indicators.registry_backed_screener=true` default **true** after parity proven in tests. |
| **Files likely to change** | `ScreenerCatalog.php`; Registry; `config/trading_os.php` or `portfolio.php`; `ScreenerTest`, `TechnicalIndicatorServiceTest` (unchanged expectations); meta controller tests. |
| **Dependencies** | Epic 1 complete. |
| **Risks** | Subtle param default/min/max drift → Screener editor UX breaks. Mitigate with snapshot/parity tests vs golden JSON. |
| **Backward compatibility** | **Critical:** response shape of `/api/screeners/meta` must remain compatible with React editor. |
| **Test strategy** | Feature: meta endpoint snapshot; Screener create/run smoke; unit parity old vs new builder. |
| **Complexity** | **L** |
| **Suggested order** | **1st** in Epic 2. |
| **Tasks** | 1) Adapter Registry→catalogue row. 2) Wire `indicators()`. 3) Golden meta test. 4) Manual smoke Screener editor. 5) Remove duplicate hardcoded list only after green. |
| **PR split** | PR-A: adapter + tests with dual-path. PR-B: delete hardcoded list. |

---

### Story 2.2 — SupportedIndicators façade over Registry

| Field | Content |
|-------|---------|
| **Objective** | Strategy catalogue definitions sourced from Registry composites. |
| **Description** | `SupportedIndicators::definitions()` / `byCategory()` / `keys()` read Registry (`strategy_scorable` + active/stub). Preserve aliases API. FactoryMomentumStrategy continues to work. |
| **Files likely to change** | `SupportedIndicators.php`; Registry; `StrategyConfigurationServiceTest`; Strategy catalogue API tests. |
| **Dependencies** | Epic 1; preferably after or parallel with 2.1. |
| **Risks** | Weight defaults / parameter schema drift breaks Strategy normalize/save. |
| **Backward compatibility** | Strategy UI and `normalizeConfig` must accept same keys. |
| **Test strategy** | Unit: keys/aliases; Feature: GET catalogue; Strategy save still validates. |
| **Complexity** | **M** |
| **Suggested order** | **2nd**. |
| **Tasks** | 1) Adapter. 2) Preserve constants for BC. 3) Tests. 4) Remove duplicated definition bodies. |
| **PR split** | One PR; or dual-path then delete like 2.1. |

---

### Story 2.3 — Cycle/dependency validation helper

| Field | Content |
|-------|---------|
| **Objective** | Fail CI if Registry composites have cycles or missing deps. |
| **Description** | Static validator run in unit tests (and optionally artisan command later). |
| **Files likely to change** | `IndicatorRegistryValidator.php`; tests. |
| **Dependencies** | Epic 1 seeds. |
| **Risks** | False positives on logical deps (`discovery_pattern_count`) — allow declared non-TI ids or register Metric. |
| **Backward compatibility** | Dev-time only. |
| **Test strategy** | Unit: valid graph passes; injected cycle fails. |
| **Complexity** | **S** |
| **Suggested order** | **3rd** (can ship with 2.1 or 2.2). |
| **Tasks** | 1) DFS cycle detect. 2) Missing dep detect. 3) Tests. |
| **PR split** | Attach to 2.2 PR or solo micro-PR. |

**Epic 2 done when:** Hardcoded duplicate metadata removed or reduced to thin façades; all existing Screener/Strategy tests green.

**Epic 2 status (2026-07-30):** **Complete** — catalogues project from Registry; seeds own metadata; façade parity tests green.

---

## 6. Epic 3 — Indicator Registry Admin UI

**Goal:** Read-only Admin page listing Registry entries + detail with dependency tree and formula explanation.

**Exit criteria:** Admin can open list/detail; non-admins forbidden; no edit-of-formula UI.

---

### Story 3.1 — Read APIs `GET /api/v1/indicators` and `/{id}`

| Field | Content |
|-------|---------|
| **Objective** | Expose Registry over authenticated admin-capable API. |
| **Description** | List with filters (type, category, status, screenable, consumer). Detail includes deps tree structure + formula_explanation. Envelope consistent with TOS APIs. |
| **Files likely to change** | New `IndicatorController` or under Settings; `routes/api.php`; Form requests; Feature tests. |
| **Dependencies** | Epic 1 (Epic 2 preferred). |
| **Risks** | Authz — must be admin-only if other settings are. |
| **Backward compatibility** | Additive endpoints only. |
| **Test strategy** | Feature: 401/403/200; filters; detail deps for `momentum_score`. |
| **Complexity** | **M** |
| **Suggested order** | **1st** in Epic 3. |
| **Tasks** | 1) Routes. 2) Controller. 3) Serialization. 4) Tests. |
| **PR split** | API-only PR before UI. |

---

### Story 3.2 — Admin list page

| Field | Content |
|-------|---------|
| **Objective** | UI list under Admin/Settings. |
| **Description** | Route e.g. `/settings/indicators`. Table columns per spec §13.2. Filters. Link to detail. |
| **Files likely to change** | New page component; router; nav (admin); `appDocumentation.js` topic; CSS as needed. |
| **Dependencies** | Story 3.1. |
| **Risks** | Nav clutter — mirror Data Quality admin pattern. |
| **Backward compatibility** | New page only. |
| **Test strategy** | Manual admin smoke; optional Vitest later (PB-032). PHP feature for API already covers data. |
| **Complexity** | **M** |
| **Suggested order** | **2nd**. |
| **Tasks** | 1) Page scaffold. 2) Fetch + table. 3) Filters. 4) Nav + docs sync. |
| **PR split** | UI PR after API. |

---

### Story 3.3 — Admin detail page (deps tree + formula)

| Field | Content |
|-------|---------|
| **Objective** | Detail view for one indicator. |
| **Description** | Show full metadata, parameters, consumers, version, status, indented dependency tree, formula explanation markdown/plain. |
| **Files likely to change** | Detail component/route; docs. |
| **Dependencies** | Stories 3.1–3.2. |
| **Risks** | Large formula text formatting — keep plain/`pre` first. |
| **Backward compatibility** | Additive. |
| **Test strategy** | Manual: open Liquidity Score planned tree; open Momentum Score. |
| **Complexity** | **M** |
| **Suggested order** | **3rd**. |
| **Tasks** | 1) Detail layout. 2) Tree renderer. 3) Formula block. 4) Docs. |
| **PR split** | Can merge with 3.2 if small; prefer separate for review. |

**Epic 3 done when:** Admin can browse all seeded indicators including Planned/Stub.

---

## 7. Epic 4 — New Indicators (Liquidity / Tradability)

**Goal:** Implement calculators + Evaluation composites for planned Liquidity/Tradability set. **Do not start until Epic 1 seeds exist;** prefer after Epic 2–3 so Admin shows status flip `planned`→`active`.

**Exit criteria:** Primaries compute on demand; composites emit Evaluation facts when enabled; Strategy can optionally score them (weights default 0/disabled until product enables).

**Data risk:** Gap/circuit inputs may need new data sources — Story 4.0 spikes this.

---

### Story 4.0 — Data availability spike (no product release required)

| Field | Content |
|-------|---------|
| **Objective** | Confirm whether gap/circuit/turnover can be computed from existing OHLCV alone. |
| **Description** | Spike doc: Average Daily Volume/Turnover from OHLCV; Relative Turnover needs peer/benchmark definition; gaps from open vs prior close; circuits may need exchange feeds or heuristics. Produce go/no-go per Primary. |
| **Files likely to change** | Spike note under `specs/` or `implementation.md` section only. |
| **Dependencies** | None technically; after Epic 1 preferred. |
| **Risks** | Circuit Frequency may be blocked → stub Primary longer. |
| **Backward compatibility** | N/A. |
| **Test strategy** | N/A (research). |
| **Complexity** | **S**–**M** |
| **Suggested order** | **Before** 4.1–4.n coding. |
| **Tasks** | 1) Inventory data. 2) Write spike findings. 3) Adjust Story 4.x scope. |
| **PR split** | Docs-only PR. |

---

### Story 4.1 — Primary: Average Daily Volume (+ align with volume_sma)

| Field | Content |
|-------|---------|
| **Objective** | Implement `average_volume` (or alias existing `volume_sma`) in TI + Registry active. |
| **Description** | Prefer extending/aliasing `volume_sma` if semantics match; else new id with TI `match` arm + ScreenerCatalog projection. |
| **Files likely to change** | `TechnicalIndicatorService.php`; Registry seed status; parity tests; Screener tests. |
| **Dependencies** | Epic 2; Story 4.0. |
| **Risks** | Duplicate semantics with `volume_sma`. |
| **Backward compatibility** | Additive indicator id; existing screens unchanged. |
| **Test strategy** | Unit TI series/last; Screener condition using new id. |
| **Complexity** | **S** |
| **Suggested order** | **1st** implementable Primary. |
| **PR split** | Solo PR. |

---

### Story 4.2 — Primary: Average Daily Turnover

| Field | Content |
|-------|---------|
| **Objective** | Implement `average_turnover` from price×volume average. |
| **Description** | TI or dedicated service method; params lookback; screenable optional. |
| **Files likely to change** | TI; Registry; tests. |
| **Dependencies** | 4.0, Epic 2. |
| **Risks** | Adjusted vs raw close; null volumes. |
| **Backward compatibility** | Additive. |
| **Test strategy** | Unit with fixture bars. |
| **Complexity** | **M** |
| **Suggested order** | After 4.1. |
| **PR split** | Solo PR. |

---

### Story 4.3 — Primary: Relative Turnover

| Field | Content |
|-------|---------|
| **Objective** | Implement `relative_turnover` vs defined baseline. |
| **Description** | Baseline = benchmark or universe median (decide in 4.0). May need batch context in Screener runs. |
| **Files likely to change** | New helper service; ScreenerEvaluationService entity bars?; Registry; tests. |
| **Dependencies** | 4.2, 4.0 decision. |
| **Risks** | Screener per-stock eval may lack universe stats — start stock-vs-benchmark only. |
| **Backward compatibility** | Additive. |
| **Test strategy** | Unit with stock+benchmark bars. |
| **Complexity** | **L** |
| **Suggested order** | After 4.2. |
| **PR split** | Benchmark-only first PR; universe relative later. |

---

### Story 4.4 — Primaries: Gap Frequency + Gap Fill Ratio

| Field | Content |
|-------|---------|
| **Objective** | Implement gap metrics from OHLCV. |
| **Description** | Define gap threshold; count frequency; fill ratio within N sessions. |
| **Files likely to change** | TI or `GapMetricsService`; Registry; tests. |
| **Dependencies** | 4.0. |
| **Risks** | Definition debates — lock in formula_explanation before coding. |
| **Backward compatibility** | Additive. |
| **Test strategy** | Synthetic gap series fixtures. |
| **Complexity** | **M** |
| **Suggested order** | Parallel with turnover stories after 4.0. |
| **PR split** | One PR for both gap metrics. |

---

### Story 4.5 — Primaries: Circuit Frequency + Circuit Risk

| Field | Content |
|-------|---------|
| **Objective** | Implement or explicitly keep stub if data missing. |
| **Description** | Per 4.0: implement heuristic **or** leave `planned`/`stub` with Admin honesty. |
| **Files likely to change** | Service if implementable; Registry status; docs. |
| **Dependencies** | 4.0. |
| **Risks** | False circuits from bad data. |
| **Backward compatibility** | Additive / no-op. |
| **Test strategy** | If stub: assert status; if real: fixtures. |
| **Complexity** | **L** (or **S** if stub decision). |
| **Suggested order** | After gap stories. |
| **PR split** | Separate from gaps. |

---

### Story 4.6 — Composite: Liquidity Score in EvaluationEngine

| Field | Content |
|-------|---------|
| **Objective** | Emit `liquidity_score` fact; Registry `active`; Strategy optional. |
| **Description** | Mapping bands in code; add to evidence `indicator_scores`; update SupportedIndicators/Registry; Factory defaults **disabled** weight 0 until product enables. |
| **Files likely to change** | `EvaluationEngine.php`; Registry; `FactoryMomentumStrategy.php`; Strategy tests; Evaluation tests. |
| **Dependencies** | 4.1–4.3 active. |
| **Risks** | Changes mean Evaluation score if equal-weight ranking includes new key — **exclude from equal-weight mean until enabled** or keep out of catalogueKeys until product-ready. |
| **Backward compatibility** | Do not change overall Evaluation ranking unexpectedly; do not alter Strategy overall unless weight enabled. |
| **Test strategy** | Unit Evaluation with fixtures; Strategy score unchanged when disabled. |
| **Complexity** | **M** |
| **Suggested order** | After liquidity primaries. |
| **PR split** | Solo PR. |

---

### Story 4.7 — Composite: Tradability Score in EvaluationEngine

| Field | Content |
|-------|---------|
| **Objective** | Emit `tradability_score`; same enablement rules as 4.6. |
| **Description** | Depends on gap/circuit primaries available; degrade gracefully if circuit stub. |
| **Files likely to change** | `EvaluationEngine.php`; Registry; tests. |
| **Dependencies** | 4.4–4.5. |
| **Risks** | Partial deps → define null/neutral behaviour. |
| **Backward compatibility** | Same as 4.6. |
| **Test strategy** | Unit + Strategy disabled weight. |
| **Complexity** | **M** |
| **Suggested order** | After gap/circuit. |
| **PR split** | Solo PR. |

**Epic 4 done when:** Planned indicators either `active` with tests or explicitly remain `stub`/`planned` with documented blockers from 4.0.

---

## 8. Epic 5 — Consumer Integration

**Goal:** Consumers discover indicators via Registry APIs/façades; optionally persist definition versions on evidence.

---

### Story 5.1 — Unified meta endpoint `GET /api/v1/indicators/meta`

| Field | Content |
|-------|---------|
| **Objective** | Compact consumer meta (screenable + operators optional). |
| **Description** | Additive endpoint; Screener editor **may** keep old meta initially. |
| **Files likely to change** | Controller; routes; tests; optional frontend later. |
| **Dependencies** | Epic 1–2. |
| **Risks** | Dual meta endpoints confuse — document which to use. |
| **Backward compatibility** | Additive. |
| **Test strategy** | Feature tests. |
| **Complexity** | **S** |
| **Suggested order** | Early in Epic 5 (after Epic 2). |
| **PR split** | Solo. |

---

### Story 5.2 — Point Screener editor meta fetch at Registry-backed meta (optional cutover)

| Field | Content |
|-------|---------|
| **Objective** | Frontend uses Registry-backed source without UX change. |
| **Description** | If `/screeners/meta` already Registry-backed (Epic 2), this Story is **verify-only**. Else switch JS to `/v1/indicators/meta` filtered screenable. |
| **Files likely to change** | `ScreenerEditorPage.jsx` / API helpers; docs. |
| **Dependencies** | 2.1 and/or 5.1. |
| **Risks** | Field rename breaks editor. |
| **Backward compatibility** | Must keep editor workable. |
| **Test strategy** | Manual Screener create/edit/run. |
| **Complexity** | **S** |
| **Suggested order** | After 5.1. |
| **PR split** | Solo. |

---

### Story 5.3 — Strategy catalogue already Registry-backed — consumer docs + optional Admin links

| Field | Content |
|-------|---------|
| **Objective** | Strategy page links to Admin indicator detail for explainability. |
| **Description** | Soft UX: “View in Registry” for each scoring row. |
| **Files likely to change** | `StrategyPage.jsx`; docs. |
| **Dependencies** | Epic 3. |
| **Risks** | None significant. |
| **Backward compatibility** | Additive UI. |
| **Test strategy** | Manual. |
| **Complexity** | **S** |
| **Suggested order** | After Epic 3. |
| **PR split** | Solo micro-PR. |

---

### Story 5.4 — Persist `indicator_definition_versions` on Evaluation evidence

| Field | Content |
|-------|---------|
| **Objective** | Snap Registry versions into EvaluationResult evidence. |
| **Description** | When evaluating, attach map `id → version` for facts used. Recommendation evidence may copy through. No backtest replay yet. |
| **Files likely to change** | `EvaluationEngine.php`; maybe Recommendation pipeline evidence merge; tests; migration **not** required if JSON evidence. |
| **Dependencies** | Epic 1 versions populated; Epic 2 preferred. |
| **Risks** | Evidence payload size — only include used ids. |
| **Backward compatibility** | Additive JSON fields; old readers ignore. |
| **Test strategy** | Unit: evidence contains versions; Feature: evaluation run. |
| **Complexity** | **M** |
| **Suggested order** | After Epic 2 (PB-056). |
| **PR split** | Solo PR (PB-056 slice). |

---

### Story 5.5 — Dashboard / Analytics consumer annotations (read-only)

| Field | Content |
|-------|---------|
| **Objective** | Mark Registry consumers accurately; optional debug only. |
| **Description** | No Dashboard rewrite. Update Registry `consumers` arrays; optionally expose ids in analytics payload `registry_refs` behind flag — default off. |
| **Files likely to change** | Registry seed; maybe analytics serializers. |
| **Dependencies** | Epic 1. |
| **Risks** | Payload noise if enabled by default. |
| **Backward compatibility** | Default unchanged payloads. |
| **Test strategy** | Unit seed consumers; feature if flag on. |
| **Complexity** | **S** |
| **Suggested order** | Anytime after Epic 1. |
| **PR split** | Micro-PR. |

**Epic 5 done when:** Discovery is Registry-centric; versions recorded on new evaluations.

---

## 9. Epic 6 — Strategy Parameter Wiring (TD-19 / PB-054)

**Goal:** Evaluation uses active Strategy indicator parameters with fallback to `trading_os.evaluation`.

**Constraint:** Independent of Registry Admin UI. Can start after Epic 1 (needs param schema), safest after Epic 2.

---

### Story 6.1 — Resolve param bag from active Strategy (with fallback)

| Field | Content |
|-------|---------|
| **Objective** | Central helper returns effective rsi/sma/atr/vol/RS params. |
| **Description** | `EvaluationParameterResolver` reads Strategy config indicators[].parameters; falls back to `TradingOsConfig::evaluation()`. |
| **Files likely to change** | New resolver class; `TradingOsConfig` if needed; unit tests. |
| **Dependencies** | None hard; Strategy config shape stable. |
| **Risks** | Missing strategy → must fallback cleanly. |
| **Backward compatibility** | Default values equal today’s trading_os → **no score change** when params unset/default. |
| **Test strategy** | Unit: fallback; override; partial override. |
| **Complexity** | **S** |
| **Suggested order** | **1st** in Epic 6. |
| **PR split** | Solo. |

---

### Story 6.2 — Wire EvaluationEngine to resolver

| Field | Content |
|-------|---------|
| **Objective** | EvaluationCandidate uses Strategy params for TI + RS lookback/benchmark. |
| **Description** | Replace local `$config['rsi_period']` reads with resolver output. Pass profile’s active strategy into `run()`. |
| **Files likely to change** | `EvaluationEngine.php`; Evaluation tests; possibly Discovery→Evaluation callers. |
| **Dependencies** | 6.1. |
| **Risks** | RS lookback_days vs months API mismatch — map carefully. Benchmark string must resolve via IndexCatalog. |
| **Backward compatibility** | Defaults preserve current scores; changing Strategy params **intentionally** changes facts (document in changelog). |
| **Test strategy** | Feature: two strategies different rsi_period → different momentum facts; default strategy matches baseline fixtures. |
| **Complexity** | **L** |
| **Suggested order** | **2nd**. |
| **Tasks** | 1) Inject resolver. 2) RS lookback mapping. 3) Benchmark resolution. 4) Tests + fixture baseline. 5) implementation.md note. |
| **PR split** | PR-A: TI periods only. PR-B: RS lookback/benchmark. |

---

### Story 6.3 — UI honesty + docs (parameters now effective)

| Field | Content |
|-------|---------|
| **Objective** | Help text states params affect Evaluation; remove “ignored” warnings. |
| **Description** | Update `appDocumentation.js`, Strategy guide, KNOWN_LIMITATIONS (close TD-19 note), implementation.md. |
| **Files likely to change** | Docs JS; markdown specs/limitations; implementation.md. |
| **Dependencies** | 6.2. |
| **Risks** | Over-promising if some params still unused — list exact wired params. |
| **Backward compatibility** | Docs only. |
| **Test strategy** | Manual docs review. |
| **Complexity** | **S** |
| **Suggested order** | **3rd**. |
| **PR split** | Docs PR with 6.2 or immediately after. |

**Epic 6 done when:** TD-19 closed; PB-054 done; baseline tests prove default parity.

---

## 10. Epic 7 — Testing / Documentation

**Goal:** Harden coverage and keep docs/help/spec indexes accurate across all Epics (continuous + final close-out).

---

### Story 7.1 — Cross-cutting regression suite for Registry parity

| Field | Content |
|-------|---------|
| **Objective** | One PHPUnit group `indicators` covering parity, validation, meta, evaluation versions. |
| **Description** | Aggregate tests from Epics 1–2–5; add smoke for Admin API. |
| **Files likely to change** | tests; phpunit.xml group if used. |
| **Dependencies** | Epics 1–3 minimally. |
| **Risks** | Slow tests — keep unit-heavy. |
| **Backward compatibility** | N/A. |
| **Test strategy** | CI filter `--group indicators`. |
| **Complexity** | **M** |
| **Suggested order** | Continuous; formalize after Epic 3. |
| **PR split** | Can land incrementally with each Epic. |

---

### Story 7.2 — In-app documentation + DOCS.md close-out

| Field | Content |
|-------|---------|
| **Objective** | Contextual help for Admin Registry; Strategy/Screener topics mention Registry. |
| **Description** | `appDocumentation.js` topics; route links; ensure DOCS.md already indexes plan/specs. |
| **Files likely to change** | `appDocumentation.js`; `documentationLinks.js`; `implementation.md`; possibly `DOCS.md`. |
| **Dependencies** | Epic 3 for Admin help. |
| **Risks** | Stale help — follow Keep-contextual-help rule. |
| **Backward compatibility** | Docs only. |
| **Test strategy** | Manual `/documentation` keyword check. |
| **Complexity** | **S** |
| **Suggested order** | With Epic 3 + final polish. |
| **PR split** | Per-feature docs with feature PRs; final sweep PR. |

---

### Story 7.3 — Manual QA script (Screener / Strategy / Evaluation / Admin)

| Field | Content |
|-------|---------|
| **Objective** | Written QA checklist for release. |
| **Description** | Extend or add under `specs/` a short manual script: run screener, save strategy, run evaluation, open Admin Registry, verify Planned vs Active. |
| **Files likely to change** | New `specs/architecture/domains/Indicator-Registry-QA-Checklist.md` or section in MVP demo. |
| **Dependencies** | Epics 2–3. |
| **Risks** | None. |
| **Backward compatibility** | N/A. |
| **Test strategy** | Human execution. |
| **Complexity** | **S** |
| **Suggested order** | Before declaring PB-055 done. |
| **PR split** | Docs PR. |

---

### Story 7.4 — Backlog / debt hygiene

| Field | Content |
|-------|---------|
| **Objective** | Mark PB-055/056/057/054 progress; close TD-19 when Epic 6 done. |
| **Description** | Update PRODUCT_BACKLOG, TECHNICAL_DEBT, IMPLEMENTATION_PROGRESS, KNOWN_LIMITATIONS. |
| **Files likely to change** | Governance/audit markdown. |
| **Dependencies** | Respective Epics. |
| **Risks** | None. |
| **Backward compatibility** | N/A. |
| **Test strategy** | N/A. |
| **Complexity** | **S** |
| **Suggested order** | End of each Epic. |
| **PR split** | Attach to Epic close-out commits. |

---

## 11. Independently reviewable PR / increment map

Prefer **many small increments** over epic-sized dumps:

| Increment | Contains | Approx size |
|-----------|----------|-------------|
| IR-01 | Story 1.1 | XS |
| IR-02 | Story 1.2 | S |
| IR-03 | Story 1.3 | S |
| IR-04 | Story 1.4 + 1.5 | S |
| IR-05a | Story 2.1 dual-path + tests | M |
| IR-05b | Story 2.1 delete hardcoded list | S |
| IR-06a/b | Story 2.2 same pattern | M |
| IR-07 | Story 2.3 validator | XS |
| IR-08 | Story 3.1 API | S |
| IR-09 | Story 3.2 list UI | M |
| IR-10 | Story 3.3 detail UI | M |
| IR-11 | Story 5.1 + 5.2 | S |
| IR-12 | Story 5.4 versions | S |
| IR-13a/b | Story 6.1 → 6.2 TI → RS | M–L |
| IR-14 | Story 6.3 docs | XS |
| IR-15+ | Epic 4 stories each as own PR | S–L each |
| IR-doc | Stories 7.2–7.4 sweeps | S |

**Do not combine** Epic 4 calculators with Epic 2 façade deletion in one review.

**Do not combine** Epic 6 behaviour change with Epic 3 UI (different risk profiles).

---

## 12. Definition of Done (every Story)

1. App boots; critical paths (login, Screener meta, Strategy load, Evaluation if touched) smoke OK.  
2. Relevant PHPUnit green.  
3. No intentional behaviour change unless Story says so — and then covered by tests.  
4. `implementation.md` updated for meaningful behaviour/API/UI.  
5. Contextual help updated if user-facing (Epic 3, 6.3, 4 enablement).  
6. Registry validator green if seeds changed.  
7. Story leaves **no** half-wired UI that errors when opened (feature-flag if incomplete).

---

## 13. Explicit non-goals (entire plan)

- Plugin / dynamic PHP class loading for indicators  
- User formula editor  
- Rewriting Market Analysis Engine into Evaluation  
- Merging Alerts `HoldingFieldRegistry` wholesale into Indicator Registry  
- Big-bang deletion of `TechnicalIndicatorService` match dispatch  

---

## 14. Suggested calendar sequencing (indicative)

| Week focus | Work |
|------------|------|
| W1 | Epic 1 (all stories) |
| W2 | Epic 2 (2.1–2.3) + start Epic 6.1 |
| W3 | Epic 3 + Epic 5.1/5.2 + Epic 6.2 |
| W4 | Epic 5.4 + Epic 6.3 + Epic 7 suite |
| Later 1.2 | Epic 4.0 spike → 4.x calculators |

---

## 15. Related tracking

| ID | Maps to |
|----|---------|
| PB-055 | Epics 1–3 + Epic 5 discovery |
| PB-056 | Story 5.4 (+ richer deps already in Epic 1.3) |
| PB-057 | Epic 4 |
| PB-054 / TD-19 | Epic 6 |

---

## 16. Change control

When implementation starts, update this plan only for scope cuts discovered in Story 4.0 or façade parity issues — do not expand into plugin frameworks without a new SD.
