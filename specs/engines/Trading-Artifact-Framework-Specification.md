# Trading Artifact Framework Specification

| Field | Value |
|-------|-------|
| **Document** | Trading Artifact Framework Specification |
| **Version** | 1.0 |
| **Status** | Accepted design (SD-034) — **not implemented** |
| **Owner** | Architecture |
| **Depends On** | Core Concepts, Indicator Registry (SD-033), Screener Spec (SD-030), Strategy Configuration (SD-027…030), Guiding Principles |
| **Architecture overview** | [../architecture/11-Trading-Artifact-Framework.md](../architecture/11-Trading-Artifact-Framework.md) |

---

# 1. Purpose

Specify a **Trading Artifact Framework**: a shared model and set of platform capabilities for reusable, versioned, validated, importable/exportable, AI-friendly trading definitions.

**First-class artifact types for this design:**

1. **Indicators** (via Indicator Registry specialization — SD-033)
2. **Screeners** (eligibility definitions; `definition_json` preserved)
3. **Strategies** (investment philosophy definitions; `config_json` preserved)

**Absorbed proposal:** “Strategy Templates” are **not** a separate subsystem. A template is a Strategy artifact with `origin=factory|imported` used as a fork source.

**Implementation status:** Documentation / design only. No code changes are implied until a release implements SD-034 phases.

---

# 2. Design Principles

1. **One envelope, many definitions.** Shared metadata/lifecycle/versioning/I/O; type-specific definition payloads.
2. **Evolve in place.** Prefer wrapping existing Screener/Strategy rows and Indicator Registry over parallel duplicate stores.
3. **References, not copies.** Strategy → Screener; Screener → Indicator ids; Composite → dependencies (SD-030 / SD-033).
4. **Determinism and trust.** Artifacts must be validateable and explainable (Guiding Principles). AI may draft; humans/activation gates decide.
5. **No executable packages.** Import packs contain JSON definitions and metadata only — never PHP/JS plugins.
6. **SD-028 stands.** Indicator calculation logic remains release-shipped code.
7. **Portfolio binding ≠ artifact identity.** A portfolio *uses* a Strategy artifact version; the library may hold many definitions.

---

# 3. Trading Artifact Model (Q1)

## 3.1 Conceptual model

```text
TradingArtifact
├── identity          (type, id, slug, name, …)
├── metadata          (common + type extensions)
├── definition        (type-specific JSON / registry body)
├── version           (version identity + immutability rules)
├── lifecycle         (status, timestamps, actors)
├── provenance        (origin, factory_key, authors, license)
├── dependencies[]    (declared refs to other artifacts)
└── capabilities      (optional discovery hints)
```

## 3.2 Formal type discriminator

```text
artifact_type ∈ { indicator, screener, strategy }
```

Future reserved: `pattern_pack`, `alert_recipe`, `watchlist_recipe`, `evaluation_profile`.

## 3.3 Definition payloads (preserve cores)

| Type | Definition field | Canonical content |
|------|------------------|-------------------|
| `indicator` | Registry definition body | Metadata + params schema + deps + capabilities (calc in code) |
| `screener` | `definition` ≡ today’s `definition_json` | Condition tree (group / condition / operands / operators) |
| `strategy` | `definition` ≡ today’s `config_json` | Eligibility sources, scoring, thresholds, portfolio, capital, exit, market_gates, cash |

**Rule:** Renaming columns in persistence is optional and late; the **logical** artifact field is `definition`. Physical columns may remain `definition_json` / `config_json` during migration.

## 3.4 Logical identifiers

| Field | Purpose |
|-------|---------|
| `artifact_id` | Stable UUID (or existing DB PK mapped) within a deployment |
| `slug` | Human/AI-friendly unique key within `(artifact_type, scope)` |
| `factory_key` | Optional stable key for shipped factory artifacts (already used by Screeners) |
| `package_id` | Optional id used inside portable export packages (may differ from DB id) |

**Scope:** `system` (factory/registry) | `portfolio` (tenant-owned) | `user` (future personal library).

## 3.5 What is *not* an artifact

Runtime results and operational state are **not** artifacts:

- Screener runs / hits  
- Evaluation results / rankings  
- Recommendations / evidence snapshots  
- Cash ledger / transactions / holdings  
- Notifications  

These may *reference* artifact versions for explainability.

---

# 4. Common Metadata (Q2)

All artifacts SHALL expose the following metadata (names are logical).

## 4.1 Identity & taxonomy

| Field | Required | Notes |
|-------|----------|-------|
| `artifact_type` | yes | Discriminator |
| `artifact_id` | yes | Stable within deployment |
| `slug` | yes | Unique per type+scope |
| `name` | yes | Display name |
| `description` | recommended | Short prose |
| `tags[]` | optional | Free tags (`minervini`, `momentum`, …) |
| `category` | optional | Type-specific taxonomy (e.g. indicator category) |
| `scope` | yes | `system` \| `portfolio` \| `user` |

## 4.2 Lifecycle & audit

| Field | Required | Notes |
|-------|----------|-------|
| `status` | yes | See §5 |
| `created_at` / `updated_at` | yes | |
| `created_by` / `updated_by` | recommended | User id or `system` |
| `activated_at` | optional | When status became `active` |
| `deprecated_at` / `deprecated_reason` | optional | |

## 4.3 Version identity

| Field | Required | Notes |
|-------|----------|-------|
| `version` | yes | See §6 |
| `version_label` | optional | Display (`v3`, `2026.07.30`) |
| `definition_hash` | recommended | Hash of canonicalized definition JSON |
| `parent_version` | optional | Fork / prior version pointer |
| `is_immutable` | derived | True for published versions |

## 4.4 Provenance

| Field | Required | Notes |
|-------|----------|-------|
| `origin` | yes | `factory` \| `user` \| `imported` \| `ai_assisted` \| `fork` |
| `factory_key` | if factory | Stable seed key |
| `source_package` | if imported | Package id / checksum |
| `authors[]` | optional | |
| `license` | optional | For future sharing |
| `attribution` | optional | |

## 4.5 AI-oriented fields

| Field | Required | Notes |
|-------|----------|-------|
| `intent` | recommended | What the artifact is trying to achieve (1–3 sentences) |
| `summary` | recommended | Plain-language summary for catalogues / prompts |
| `assumptions[]` | optional | Market / data assumptions |
| `limitations[]` | optional | Known limits / non-goals |
| `keywords[]` | optional | Retrieval aids |
| `ai_notes` | optional | Generation hints; never trusted for execution |

These fields are **documentation**. They do not change runtime behaviour.

## 4.6 Type-specific metadata extensions

Allowed as `metadata.extensions.<type>` without polluting the common set.

Examples:

- Indicator: `indicator_type`, `capabilities.screenable`, `consumers[]` (per SD-033)
- Screener: `universe_hint`, `estimated_selectivity`, `schedule_supported`
- Strategy: `style` (`momentum`, `mean_reversion`, …), `holding_horizon`, `risk_profile`

---

# 5. Artifact Lifecycle (Q4)

## 5.1 States

```text
        ┌──────────┐
        │  draft   │ ← create, import, AI propose, fork
        └────┬─────┘
             │ validate + activate
             ▼
        ┌──────────┐
        │  active  │ ← eligible for runtime binding / runs
        └────┬─────┘
             │ supersede / soft-retire
             ▼
        ┌────────────┐
        │ deprecated │ ← still resolvable for history; warn on new binds
        └─────┬──────┘
              │ harden retire
              ▼
        ┌──────────┐
        │ archived │ ← read-only; not offered in pickers
        └──────────┘
```

Optional transient: `imported` (untrusted package landed; treated as `draft` until validated).

## 5.2 Transition rules

| From | To | Gate |
|------|----|------|
| — | `draft` | Create / import / fork / AI draft |
| `draft` | `active` | **Must pass validation** (§8); dependencies resolvable |
| `active` | `deprecated` | Admin/user; may require replacement pointer |
| `deprecated` | `archived` | No active portfolio bindings (Strategy) / no scheduled runs (Screener) |
| `archived` | `draft` | Explicit restore/fork only (new version preferred) |

## 5.3 Factory artifacts

- Seeded with `origin=factory`, stable `factory_key`.
- May be `active` by default.
- **Fork** creates `origin=fork` draft owned by portfolio/user; factory original remains intact.
- Editing factory in place is a product decision: V1 Screeners/Strategies already allow edit of factory rows in some cases — Framework recommends **fork-on-edit** for shared/system scope in a later phase, while allowing in-place edit for portfolio-scoped copies.

## 5.4 Portfolio binding (Strategy)

Lifecycle of the **artifact** is separate from **which version a portfolio uses**:

```text
StrategyArtifact (library) ──version──► PortfolioStrategyBinding ──► runtime Save/Run
```

V1 UX (“one editable strategy”) maps to: single binding + in-place update of the bound version’s draft/active definition. Framework design allows multiple library entries later without breaking that UX.

---

# 6. Versioning (Q5)

## 6.1 Goals

- Reproducible explainability (“recommendation used Strategy v12 + Screener v3”)
- Safe import/export and sharing
- AI diffs and forks
- Avoid forcing users through SemVer ceremony for every Save

## 6.2 Version scheme

| Context | Scheme | Notes |
|---------|--------|-------|
| Local draft edits | Integer `revision` within draft | Mutable; hash updates |
| Published / activated | Monotonic integer `version` **or** SemVer when sharing | Immutable body |
| Indicators | `definition_version` tied to app release (SD-033) | Code + metadata ship together |
| Portable packages | Explicit `version` + `definition_hash` | Consumers pin by hash when strict |

**Recommendation:** Use **integer versions** internally; expose SemVer only for published share packs if needed.

## 6.3 Immutability rules

1. Once a version is `active` and referenced by a Recommendation / ScreenerRun / export pin, its **definition body is immutable**.
2. Edits create a **new version** (or a draft child), except during an allowed “single-binding in-place Save” compatibility mode (current Strategy behaviour).
3. Compatibility mode (Strategy V1 Save-in-place): allowed until Phase 3 binding model lands; evidence should still store `definition_hash` when available.

## 6.4 Compatibility with today’s Strategy model

Today: one active strategy; Save overwrites `config_json` (SD-029 amended).

**Framework evolution path:**

1. Keep Save UX.  
2. Persist `definition_hash` + optional silent version bump on each Save (audit).  
3. Introduce library + explicit “Publish version” / “Fork from template”.  
4. Recommendations already store `strategy_version_id` — align that FK with artifact versions.

## 6.5 Screener versions

Today: single `definition_json` on `portfolio_screeners`.

**Evolution:** either

- **A (preferred):** `portfolio_screener_versions` (id, screener_id, version, definition_json, metadata, status), or  
- **B:** envelope columns on the same row + history table for prior definitions.

Runs and Strategy eligibility pins SHOULD reference `(screener_id, version)` when versioning exists; until then, pin by `screener_id` + `definition_hash` snapshot on run.

---

# 7. Registry Architecture (Q3)

## 7.1 Layers

```text
┌──────────────────────────────────────────────────────────┐
│              ArtifactRegistry (umbrella API)             │
│  list / get / search / validate / export / import / deps │
└───────────────┬──────────────────┬───────────────────────┘
                │                  │
    ┌───────────▼──────┐  ┌────────▼─────────┐  ┌──────────▼─────────┐
    │ IndicatorRegistry│  │ ScreenerRegistry │  │ StrategyRegistry   │
    │ (SD-033)         │  │ (artifact layer) │  │ (artifact layer)   │
    └───────────┬──────┘  └────────┬─────────┘  └──────────┬─────────┘
                │                  │                       │
         code seed / façades   portfolio_screeners    portfolio_tos_strategies
                               + versions (future)    + versions / library
```

## 7.2 Responsibilities

| Component | Owns | Does not own |
|-----------|------|--------------|
| **ArtifactRegistry** | Cross-type catalogue, package I/O, dependency graph, validation orchestration, AI discovery façade | Indicator math, Screener evaluation, Strategy scoring |
| **IndicatorRegistry** | Indicator metadata SoT | OHLCV calc, Strategy weights |
| **ScreenerRegistry** | Screener artifact metadata + definition access | Ranking / recommendations |
| **StrategyRegistry** | Strategy artifact library + bindings | Cash ledger, order placement |

## 7.3 Discovery API (logical)

```text
GET  /artifacts?type=&status=&tag=&q=
GET  /artifacts/{type}/{id}
GET  /artifacts/{type}/{id}/versions
GET  /artifacts/{type}/{id}/dependencies
POST /artifacts/{type}/validate
POST /artifacts/export
POST /artifacts/import
```

Physical routes may nest under existing `/api/screeners`, `/api/v1/strategy`, and future Indicator Admin APIs during migration. Umbrella routes are the **target** contract.

## 7.4 Indicator specialization

Indicator Registry remains as specified in SD-033. Framework adds:

- Common provenance / AI metadata fields on indicator entries (alignment phase)
- Inclusion of indicators in cross-artifact dependency graphs and export packs (as **references**, not recalculated definitions)

Indicators in an export pack are listed as:

```json
{ "artifact_type": "indicator", "slug": "rsi", "definition_version": "1.0", "resolution": "runtime_registry" }
```

They are **not** shipped as executable code inside the pack.

---

# 8. Validation (Q7)

## 8.1 Validation layers

| Layer | Purpose |
|-------|---------|
| **Schema** | JSON Schema / structural shape of envelope + definition |
| **Referential** | Dependency targets exist and are compatible versions |
| **Semantic** | Type business rules (weights sum, tree depth, operators, capabilities) |
| **Policy** | Origin/trust (imported/AI must not auto-activate), scope rules |
| **Runtime readiness** | Required indicators implemented (`status=active` in Indicator Registry), data prerequisites documented |

## 8.2 Per-type semantic highlights

### Indicator

- Types/categories/capabilities consistent (SD-033)
- Composites declare `depends_on`; DAG acyclic
- `screenable` / `strategy_scorable` only if calc path exists or `status=planned` (planned cannot be activated for runtime use)

### Screener

- `definition_json` root is group or condition (existing V1 model)
- Operand indicators must be Registry entries with `screenable=true` (after Registry migration)
- Operators ∈ {gt,gte,lt,lte,eq}
- Depth / condition count limits (existing)
- No Strategy scoring fields inside Screener definition

### Strategy

- `eligibility_sources` reference Screener artifacts (ids/slugs), **no** embedded condition trees (SD-030)
- Enabled scoring weights normalize to 100 (existing rule)
- Thresholds / exit rules structurally valid
- `market_gates` only declare conditions on Market Analysis outputs (SD-032)
- Dependency Screeners resolvable at activate time

## 8.3 Activation gate

```text
AI or human produces draft
        ↓
ArtifactValidator.validate(type, envelope)
        ↓
errors? → remain draft, return issues
        ↓
optional human review
        ↓
activate → status=active
```

**Rule:** `origin=ai_assisted|imported` SHALL NOT skip validation. Auto-activate is forbidden for those origins unless an explicit admin policy says otherwise (default: forbidden).

## 8.4 Validation result shape (logical)

```json
{
  "ok": false,
  "errors": [{ "code": "WEIGHTS_NOT_100", "path": "definition.scoring_model", "message": "…" }],
  "warnings": [{ "code": "SCREENER_DEPRECATED", "path": "definition.eligibility_sources[0]", "message": "…" }],
  "resolved_dependencies": [{ "type": "screener", "slug": "minervini_trend_template", "version": 1 }]
}
```

---

# 9. Dependencies (Q8)

## 9.1 Dependency kinds

| Kind | Example | Strength |
|------|---------|----------|
| `uses` | Strategy uses Screener | Hard — activate blocked if missing |
| `uses_indicator` | Screener condition operand | Hard — validate / run may fail |
| `composed_of` | Composite indicator deps | Hard for composites |
| `suggests` | Strategy suggests companion Screener | Soft — warning only |
| `compatible_with` | Pack metadata | Soft |

## 9.2 Graph rules

```text
Strategy ──uses──► Screener ──uses_indicator──► Indicator
                         │
                         └── uses_indicator ──► Indicator
Indicator(Composite) ──composed_of──► Indicator+
```

- Graph MUST be a **DAG** (no cycles).  
- Version pins: prefer `slug + version` or `slug + definition_hash`.  
- Floating pins (`slug` → latest active) allowed for draft editing; **published packs and recommendation evidence SHOULD freeze versions**.

## 9.3 Resolution

```text
resolve(artifact) →
  nodes[], edges[], missing[], deprecated[], planned_indicators[]
```

Export includes either:

- **Bundle mode:** embed dependent Screener definitions (+ indicator refs)  
- **Reference mode:** list deps only; importer must already have them (or resolve factory_keys)

Default for Strategy share packs: **Bundle Screeners, reference Indicators**.

## 9.4 Ownership reminder

Dependencies declare **coupling**, not ownership transfer. Screener still owns eligibility evaluation; Strategy still owns scoring philosophy.

---

# 10. Import / Export (Q6)

## 10.1 Portable package schema (logical)

```json
{
  "schema_version": "1.0",
  "package_id": "uuid",
  "exported_at": "ISO-8601",
  "exporter": { "app": "StoX", "app_version": "…", "user": "optional" },
  "artifacts": [
    {
      "artifact_type": "strategy",
      "slug": "minervini_momentum",
      "name": "Minervini Strategy",
      "version": 3,
      "metadata": { "intent": "…", "summary": "…", "origin": "exported", "tags": ["minervini"] },
      "definition": { "...": "config_json body" },
      "dependencies": [
        { "kind": "uses", "artifact_type": "screener", "slug": "minervini_trend_template", "version": 1 }
      ],
      "definition_hash": "sha256:…"
    },
    {
      "artifact_type": "screener",
      "slug": "minervini_trend_template",
      "version": 1,
      "metadata": { "factory_key": "minervini_trend_template", "intent": "…" },
      "definition": { "...": "definition_json body unchanged format" },
      "dependencies": [
        { "kind": "uses_indicator", "artifact_type": "indicator", "slug": "sma", "resolution": "runtime_registry" }
      ],
      "definition_hash": "sha256:…"
    }
  ],
  "indicator_refs": [
    { "slug": "sma", "min_definition_version": "1.0" }
  ],
  "checksum": "sha256:…"
}
```

## 10.2 Rules

1. **Screener `definition` MUST remain the existing condition-tree JSON** (no parallel DSL in packs).
2. **Strategy `definition` MUST remain the existing `config_json` shape** (eligibility by reference).
3. Packs MUST NOT contain executable code.
4. Import creates `draft` artifacts with `origin=imported` (or `ai_assisted` if flagged).
5. Id remapping: importer allocates new `artifact_id`s; preserve `slug` when free, else suffix.
6. Factory collision: if `factory_key` exists, offer Skip / Fork / Replace-draft policies (default: Fork).

## 10.3 Partial export

Allowed: single Screener, single Strategy (+ bundled deps), Indicator catalogue slice (metadata only).

## 10.4 Future sharing

Package schema is the foundation for later community sharing. Not in scope: marketplace, signing beyond checksum, multi-tenant remotes. Reserve `license` + `attribution` now.

---

# 11. Future Extensibility (Q9)

## 11.1 Adding a new artifact type

1. Define `artifact_type` string and definition JSON Schema.  
2. Implement `ArtifactTypeHandler`: validate, serialize, dependency extract, activate hooks.  
3. Register handler with ArtifactRegistry.  
4. Add Admin/UI catalogue projection.  
5. Document in Core Concepts + this spec.

No dynamic code loading from packages.

## 11.2 Extension points (allowed)

- Metadata tags / categories  
- Soft dependencies  
- New capability flags  
- New AI documentation fields  
- New package `schema_version` (backward compatible readers)

## 11.3 Extension points (forbidden without new SD)

- User-uploaded PHP/JS calculators  
- Arbitrary expression languages replacing Screener tree or Indicator code  
- Strategy embedding Screener condition copies (violates SD-030)  
- Silent mutation of immutable published versions  

## 11.4 Candidate future types

| Type | Definition sketch | Depends on |
|------|-------------------|------------|
| `pattern_pack` | Enabled pattern ids + thresholds | Discovery detectors |
| `alert_recipe` | Alert condition references | Alerts module |
| `watchlist_recipe` | Rules to populate watchlists | Watchlist + Screeners |
| `evaluation_profile` | Factor set documentation | Evaluation Engine |

---

# 12. AI Compatibility (Q10)

## 12.1 Goals

Enable AI assistants to:

1. **Discover** available artifacts and indicators via catalogues  
2. **Propose** Screener / Strategy drafts as JSON conforming to schemas  
3. **Explain** artifacts using `intent` / `summary` / dependency trees  
4. **Validate** before activation  
5. **Package** exportable bundles for review  

## 12.2 AI contract

| Input to AI | Source |
|-------------|--------|
| Indicator catalogue | Indicator Registry (capabilities, params, summaries) |
| Screener schema | JSON Schema of `definition_json` + examples |
| Strategy schema | JSON Schema of `config_json` + section docs |
| Existing artifacts | ArtifactRegistry search (summaries, not necessarily full secrets) |

| Output from AI | Handling |
|----------------|----------|
| Draft envelope JSON | Store as `draft`, `origin=ai_assisted` |
| Natural language intent | Map into metadata fields |
| Partial patches | Apply to draft; re-validate |

## 12.3 Safety

- AI output is **untrusted** until validation passes.  
- No direct write to `active` from AI.  
- No execution of model-produced code.  
- Prefer constrained decoding / schema-validated generation when available.  
- Log `ai_notes` and model provenance optionally for audit (not for execution).

## 12.4 Prompt-friendly surfaces

Each registry SHOULD expose a compact **catalogue card**:

```text
slug, name, type, status, summary, intent, tags, version, dependency_slugs[]
```

Full definitions are fetched only when editing or exporting.

## 12.5 Explicit AI non-goals (near term)

- Autonomous live trading from AI-generated strategies  
- Free-form English as a runtime definition language  
- Replacing deterministic validators with LLM judgment  

---

# 13. Screener Evolution (Detail)

## 13.1 As-built (preserve)

- Table `portfolio_screeners` with `definition_json`  
- Condition tree: group (AND/OR) | condition (left op right)  
- Factory example: Minervini Trend Template (`factory_key`)  
- Runs/hits persisted; Strategy consumes by reference  

## 13.2 Target Screener Artifact

```text
ScreenerArtifact
├── common metadata (§4)
├── definition: <existing definition_json>     ← UNCHANGED FORMAT
├── versions (Phase 2+)
└── dependencies: indicator refs extracted from operands
```

## 13.3 Changes allowed

| Change | Allowed? |
|--------|----------|
| Keep condition tree JSON | **Required** |
| Add metadata columns / side table | Yes |
| Add version history | Yes |
| Export/import via package schema | Yes |
| Registry listing / Admin catalogue | Yes |
| Replace tree with new DSL | **No** (would need new SD) |
| Move eligibility into Strategy | **No** (SD-030) |

## 13.4 Operand resolution

After Indicator Registry migration, operand ids resolve through Registry (`screenable=true`). Until then, continue `ScreenerCatalog` façade behaviour.

## 13.5 Extraction of dependencies

A dependency extractor walks `definition_json` and emits `uses_indicator` edges for every indicator operand. Stored or computed on demand for export/validate.

---

# 14. Strategy Evolution (Detail)

## 14.1 As-built (preserve)

- `portfolio_tos_strategies` + `portfolio_tos_strategy_versions.config_json`  
- Sections: eligibility, scoring, thresholds, portfolio, capital, exit, market_gates, cash  
- Eligibility via Screener ids (junction `portfolio_tos_strategy_screeners`)  
- One editable strategy per portfolio; Save in place  

## 14.2 Target Strategy Artifact

```text
StrategyArtifact
├── common metadata (§4)
│     intent, summary, style, risk_profile, tags, …
├── definition: <existing config_json>          ← UNCHANGED CORE SHAPE
├── dependencies: uses → Screener artifacts
├── versions
└── bindings: Portfolio → active version
```

## 14.3 Metadata (Strategy-specific)

In addition to common fields:

| Field | Purpose |
|-------|---------|
| `style` | Taxonomy for catalogues / AI |
| `holding_horizon` | e.g. swing / position |
| `risk_profile` | conservative / balanced / aggressive |
| `template_of` | If forked from factory/import slug |

## 14.4 JSON representation

Logical export uses the package schema (§10). Strategy entry `definition` mirrors `config_json`, with eligibility as references:

```json
{
  "eligibility_sources": [
    { "screener_slug": "minervini_trend_template", "version": 1, "enabled": true, "priority": 1 }
  ],
  "scoring_model": { "...": "…" },
  "thresholds": { "...": "…" },
  "portfolio_rules": { "...": "…" },
  "capital_allocation": { "...": "…" },
  "exit_strategy": { "...": "…" },
  "market_gates": { "...": "…" },
  "cash_rules": { "...": "…" }
}
```

During migration, `screener_id` remains valid internally; packs prefer **slug + version** for portability.

## 14.5 Registry & “templates”

| Concept | Framework meaning |
|---------|-------------------|
| Strategy Template | Factory or published Strategy artifact intended for fork |
| Strategy Library | StrategyRegistry list (system + portfolio scoped) |
| Active Strategy | Portfolio binding to one artifact version |
| Duplicate / Fork | Create draft child with `origin=fork` |

## 14.6 Compatibility with V1 UX

Phase 3 recommendation:

1. Keep `/strategy` Save behaviour for the bound artifact.  
2. Add optional “Save as new version” / “Fork from template” later.  
3. Do not reintroduce protected factory immutability unless product asks; prefer fork model for sharing.

---

# 15. Cross-Cutting Flows

## 15.1 Create from AI

```text
User prompt → AI draft JSON → validate → draft artifact → human edit → activate → bind
```

## 15.2 Share Strategy

```text
Export Strategy (bundle Screeners, ref Indicators) → file/JSON
→ Import elsewhere → draft → validate (indicator gaps?) → activate → bind
```

## 15.3 Explain Recommendation (future alignment)

Evidence stores artifact version ids + definition hashes for Strategy and eligibility Screeners (and indicator definition versions per SD-033 Phase 4–5).

---

# 16. Persistence Guidance (Non-binding until implementation)

Prefer **evolve existing tables** + additive columns / version tables:

| Need | Guidance |
|------|----------|
| Screener metadata | Columns or `portfolio_screener_artifacts` 1:1 with screener |
| Screener versions | New `portfolio_screener_versions` |
| Strategy library | Extend strategies table or add library table + binding |
| Common search | Optional `portfolio_trading_artifacts` projection index (type, slug, status, tags) |

Exact migrations are **out of scope** for this design doc.

---

# 17. Non-Goals

- Implementing any code in the SD-034 acceptance itself  
- Plugin/indicator marketplace with executable payloads  
- Replacing Indicator Registry with a generic blob store  
- Redesigning Screener condition language  
- Multi-strategy concurrent portfolios (may come later; binding model allows it)  
- Autonomous AI trading  

---

# 18. Phased Delivery (Spec-level)

| Phase | Deliverable | Behaviour change? |
|------:|-------------|-------------------|
| 0 | This spec + architecture 11 + SD-034 | No |
| 1 | Shared JSON Schemas + docs alignment (Indicator AI fields plan) | No |
| 2 | Screener artifact metadata + export/import | Additive APIs |
| 3 | Strategy library + binding (Save UX preserved) | Additive |
| 4 | Umbrella ArtifactRegistry + dependency resolver | Additive |
| 5 | AI catalogue + draft-from-schema validation UX | Additive |
| 6 | Sharing/distribution hardening | Later |

Parallel: Indicator Registry PB-055+ (SD-033) remains on its own plan ([10-Indicator-Registry-Implementation-Plan.md](../architecture/10-Indicator-Registry-Implementation-Plan.md)).

---

# 19. Related Documents

- Architecture: [11-Trading-Artifact-Framework.md](../architecture/11-Trading-Artifact-Framework.md)  
- Indicator Registry: [Indicator-Registry-Specification.md](./Indicator-Registry-Specification.md)  
- Screener: [Screener-Specification.md](./Screener-Specification.md)  
- Strategy Configuration: [Strategy-Configuration-Specification.md](./Strategy-Configuration-Specification.md)  
- Governance: [SD-034](../governance/SPECIFICATION_DECISIONS.md)  

---

# 20. Summary Recommendations

1. Adopt a **typed Trading Artifact envelope** with preserved Screener/Strategy definition cores.  
2. Put **Indicator / Screener / Strategy registries** under one **ArtifactRegistry** umbrella.  
3. Use **draft → active → deprecated → archived** with strict validate-before-activate.  
4. Version immutably when published; keep V1 Save-in-place as a compatibility binding mode.  
5. Standardize **portable JSON packages** (bundle Screeners, reference Indicators).  
6. Declare **DAG dependencies**; never copy Screener trees into Strategies.  
7. Extend via **type handlers**, not executable plugins.  
8. Make AI a **draft producer** over JSON Schema + catalogues, never an authority over activation.  
9. Treat “Strategy Template” as **factory/imported Strategy artifacts**, not a separate framework.  
10. Implement later in phases; **this document is design-only**.
