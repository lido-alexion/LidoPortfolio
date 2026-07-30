# Trading Artifact JSON Specification

| Field | Value |
|-------|-------|
| **Document** | Trading Artifact JSON Specification |
| **Version** | 1.0 |
| **Status** | Design + examples; **Registry infrastructure implemented** — see [Trading-Artifact-Registry-Migration.md](./Trading-Artifact-Registry-Migration.md) |
| **Date** | 2026-07-30 |
| **Owner** | Architecture |
| **Depends On** | [Trading-Artifact-Framework-Specification.md](./Trading-Artifact-Framework-Specification.md) (SD-034), [Indicator-Registry-Specification.md](./Indicator-Registry-Specification.md) (SD-033), [Screener-Specification.md](./Screener-Specification.md) (SD-030), [Strategy-Configuration-Specification.md](./Strategy-Configuration-Specification.md) |
| **Architecture** | [../architecture/11-Trading-Artifact-Framework.md](../architecture/11-Trading-Artifact-Framework.md) |
| **Examples** | [artifacts/examples/](./artifacts/examples/) |

---

# 0. Purpose & non-goals

Define **declarative JSON formats** for every first-class Trading Artifact:

1. **Indicator**
2. **Screener**
3. **Strategy**

Formats are optimized for:

| Goal | Design consequence |
|------|-------------------|
| Human readability | Stable keys, short prose fields, no cryptic nesting beyond need |
| AI generation | Strict discriminators, enums, catalogues by **stable id**, examples as few-shot fodder |
| AI editing | JSON Patch–friendly paths; never bury identity inside opaque blobs |
| Import / export | Portable envelope + package; remap ids; pin versions |
| Long-term BC | `schema_version`, additive fields, forbidden removals without major bump |

**This document is design only.** No application code, migrations, or runtime parsers are implied.

## Non-negotiables

1. **Declarative only** — no executable code, no scripts, no plugins.
2. **No formulas** — no expression language, no formula strings that engines evaluate. Indicator math remains release-shipped code (SD-028). Prose `summary` / `intent` may describe behaviour; they are documentation, not calc.
3. **References, not copies** — Screeners/Strategies reference Indicators by **stable Registry id**. Strategies reference Screeners by **slug** (or portable id), never embed condition trees (SD-030).
4. **Preserve cores** — Screener condition tree and Strategy config sections evolve *inside* `definition`; they are not replaced by a parallel DSL.

---

# 1. Version triad

Three version fields appear at different layers. They MUST NOT be conflated.

| Field | Where | Meaning |
|-------|-------|---------|
| **`schema_version`** | Package root and (optionally) single-artifact documents | Version of **this JSON schema / envelope contract**. Independent of product releases. Current: `"1.0"`. |
| **`minimum_engine_version`** | Package root and/or artifact metadata | Lowest StoX / Trading OS **application** semver that can *safely interpret and run* this artifact (known operators, indicator ids, config sections). Example: `"1.1.0"`. |
| **`artifact_version`** | Each artifact | Monotonic integer (preferred) or semver string for the **definition body** of that artifact. Immutable once published/activated and referenced. |

### Rules

1. Readers MUST reject packages whose `schema_version` major is unsupported.
2. Readers MUST warn (and MAY refuse activate) if `minimum_engine_version` > installed engine.
3. `artifact_version` increments only when `definition` (canonicalized) changes.
4. Optional `definition_hash` (`sha256:` + hex of canonical JSON) pins exact body for explainability.
5. Indicator metadata also carries `definition_version` (Registry / release string, e.g. `"1.0.0"`) — that is the **Indicator Registry** version of the indicator id, distinct from package `artifact_version` when an Indicator is exported as an artifact document.

```text
schema_version          →  "how do I parse this JSON?"
minimum_engine_version  →  "can this app run it?"
artifact_version        →  "which revision of this Strategy/Screener/Indicator doc?"
definition_version      →  "which Registry release of indicator id X?" (indicators only)
```

---

# 2. Common envelope

Every artifact document (standalone or inside a package) shares this shape:

```json
{
  "schema_version": "1.0",
  "artifact_type": "indicator | screener | strategy",
  "artifact_id": "uuid-or-null-on-create",
  "slug": "stable_snake_case_key",
  "name": "Human Display Name",
  "artifact_version": 1,
  "definition_hash": "sha256:…",
  "minimum_engine_version": "1.1.0",
  "metadata": { },
  "definition": { },
  "dependencies": [ ],
  "validation": { }
}
```

| Field | Required | Notes |
|-------|----------|-------|
| `schema_version` | yes | Envelope contract |
| `artifact_type` | yes | Discriminator |
| `artifact_id` | no on create / yes after persist | Deployment-local UUID; remapped on import |
| `slug` | yes | Unique within `(artifact_type, scope)` |
| `name` | yes | Display |
| `artifact_version` | yes | Integer ≥ 1 preferred |
| `definition_hash` | recommended on export | Canonical hash of `definition` |
| `minimum_engine_version` | recommended | Semver string |
| `metadata` | yes | Common + type extensions (§2.1) |
| `definition` | yes | Type-specific; no code |
| `dependencies` | yes (may be `[]`) | Declared refs (§2.2) |
| `validation` | optional | Embedded rules / hints for AI & validators (§2.3) |

### Canonicalization (for hashes)

1. UTF-8 JSON, object keys sorted lexicographically at every level.
2. No insignificant whitespace in the hashed form.
3. Exclude `definition_hash`, `artifact_id` (deployment), and volatile timestamps from the hashed material — hash **`definition` only** unless a product policy says otherwise. Package checksum hashes the whole package minus `checksum`.

---

## 2.1 Common `metadata`

```json
{
  "scope": "system",
  "status": "active",
  "origin": "factory",
  "factory_key": "optional_stable_key",
  "description": "Short prose.",
  "intent": "What this artifact is meant to achieve.",
  "summary": "One-paragraph overview for humans and AI.",
  "tags": ["momentum", "minervini"],
  "category": "optional_type_specific",
  "locale": "en",
  "authors": ["StoX Factory"],
  "license": "proprietary",
  "attribution": null,
  "created_at": "2026-07-30T00:00:00Z",
  "updated_at": "2026-07-30T00:00:00Z",
  "ai": {
    "editable": true,
    "generation_hints": ["Prefer referencing registry ids from the indicator catalogue."],
    "forbidden_changes": ["Do not embed executable code.", "Do not invent indicator ids."]
  }
}
```

| Field | Required | Enum / notes |
|-------|----------|--------------|
| `scope` | yes | `system` \| `portfolio` \| `user` |
| `status` | yes | `draft` \| `active` \| `deprecated` \| `archived` |
| `origin` | yes | `factory` \| `user` \| `imported` \| `ai_assisted` \| `fork` \| `exported` |
| `factory_key` | if factory | Stable across deployments |
| `description` | recommended | ≤ ~280 chars |
| `intent` / `summary` | recommended | AI/human docs |
| `tags` | optional | Lowercase tokens |
| `ai.editable` | optional | Default true for draft |
| `ai.forbidden_changes` | optional | Policy strings for editors |

Type-specific metadata extensions are additive under the same object (never a second metadata root).

---

## 2.2 Common `dependencies[]`

```json
{
  "kind": "uses_indicator",
  "artifact_type": "indicator",
  "ref": "rsi",
  "ref_scheme": "registry_id",
  "min_definition_version": "1.0.0",
  "resolution": "runtime_registry",
  "required": true
}
```

| Field | Required | Notes |
|-------|----------|-------|
| `kind` | yes | `uses_indicator` \| `uses_screener` \| `uses_strategy` \| `extends` \| `replaces` \| `soft_uses` |
| `artifact_type` | yes | Target type |
| `ref` | yes | Stable id or slug |
| `ref_scheme` | yes | `registry_id` (indicators) \| `slug` \| `factory_key` \| `artifact_id` |
| `min_definition_version` / `min_artifact_version` | optional | Compatibility floor |
| `resolution` | recommended | `runtime_registry` \| `bundle` \| `factory` |
| `required` | yes | If false, soft dependency |

**Indicators in packs:** default `resolution=runtime_registry` — pack does **not** ship calculators.

---

## 2.3 Embedded `validation` object

Declarative rules the validator / AI must honour. Not executable.

```json
{
  "rules": [
    {
      "code": "WEIGHTS_SUM_100",
      "path": "definition.scoring_model",
      "severity": "error",
      "message": "Enabled scoring weights must sum to 100."
    }
  ],
  "requires_capabilities": ["strategy_scorable"],
  "max_condition_depth": 6,
  "max_conditions": 40
}
```

Severity: `error` \| `warning` \| `info`.  
`path` uses JSON Pointer or dotted paths consistently within a package (`$.definition…` recommended for AI).

---

# 3. Portable package document

```json
{
  "schema_version": "1.0",
  "package_id": "00000000-0000-4000-8000-000000000001",
  "package_format": "stox.trading_artifacts",
  "exported_at": "2026-07-30T12:00:00Z",
  "minimum_engine_version": "1.1.0",
  "origin": {
    "app": "StoX",
    "app_version": "1.1.0",
    "exporter": "optional"
  },
  "artifacts": [],
  "indicator_refs": [],
  "extension": {},
  "checksum": "sha256:…"
}
```

| Field | Purpose |
|-------|---------|
| `package_format` | Constant discriminator for file type sniffing |
| `artifacts` | Full Screener/Strategy (and optional Indicator metadata) documents |
| `indicator_refs` | Compact list of Registry ids required at runtime |
| `extension` | Reserved bag for **additive** future keys under a namespaced map (see §8) |

### Bundle modes

| Mode | Behaviour |
|------|-----------|
| **Bundle screeners** | Strategy pack embeds Screener artifacts |
| **Reference indicators** | Only `indicator_refs` + dependency rows; no calc code |
| **Reference mode** | Strategy lists Screener deps; importer must resolve factory_key/slug |

Default share pack: **bundle Screeners, reference Indicators**.

---

# 4. Indicator artifact JSON

Indicators are primarily **Registry metadata**. Portable JSON describes discovery metadata and parameters — **never** calculation code or formulas.

## 4.1 `definition` shape

```json
{
  "registry_id": "trend_score",
  "indicator_kind": "composite",
  "registry_category": "trend",
  "definition_version": "1.0.0",
  "units": "score_0_100",
  "precision": 2,
  "parameters": [
    {
      "id": "period",
      "label": "Period",
      "type": "integer",
      "default": 14,
      "min": 2,
      "max": 400,
      "step": 1
    }
  ],
  "depends_on": ["sma", "close"],
  "capabilities": {
    "screenable": false,
    "strategy_scorable": true,
    "evaluation_fact": true,
    "chartable": false,
    "needs_volume": false
  },
  "consumers": ["strategy", "evaluation", "admin_registry"],
  "aliases": [],
  "status": "active",
  "documentation": {
    "summary": "Maps price versus an SMA stack into a 0–100 trend quality score.",
    "notes": [
      "Calculation is implemented in application release code.",
      "Do not invent alternate registry_id values."
    ]
  }
}
```

| Field | Required | Notes |
|-------|----------|-------|
| `registry_id` | yes | **Stable id** — same as Indicator Registry / TI / Strategy keys |
| `indicator_kind` | yes | `primary` \| `composite` \| `metric` |
| `registry_category` | yes | Registry category id |
| `definition_version` | yes | Registry metadata version string |
| `parameters` | yes (may be `[]`) | Declarative schema only |
| `depends_on` | yes for composites | List of **registry_id** strings |
| `capabilities` | yes | Booleans; unknown keys ignored under BC rules |
| `consumers` | recommended | Registry consumer ids |
| `documentation` | recommended | Prose only — **no formulas** |

`slug` on the envelope SHOULD equal `registry_id` for Indicators.

## 4.2 Indicator validation rules (declarative)

| Code | Rule |
|------|------|
| `INDICATOR_ID_REQUIRED` | `definition.registry_id` non-empty snake_case |
| `INDICATOR_KIND_ENUM` | kind ∈ primary\|composite\|metric |
| `COMPOSITE_DEPS_REQUIRED` | composites MUST have `depends_on` (may be empty only if `status=stub`) |
| `DEPS_REGISTRY_IDS` | every `depends_on` entry is a registry_id string |
| `NO_CODE_FIELDS` | reject keys like `code`, `script`, `formula`, `expression`, `wasm` |
| `PLANNED_NOT_RUNTIME` | `status=planned` cannot be required by an active Screener/Strategy |
| `CAPABILITY_CONSISTENCY` | `screenable=true` implies primary (or documented exception) and engine support |

---

# 5. Screener artifact JSON

## 5.1 `definition` shape (preserves V1 condition tree)

```json
{
  "root": {
    "type": "group",
    "op": "AND",
    "children": []
  }
}
```

### Node types

**Group**

```json
{
  "type": "group",
  "op": "AND",
  "children": [ ]
}
```

`op` ∈ `AND` \| `OR`.

**Condition**

```json
{
  "type": "condition",
  "left": { "indicator": "close", "params": {} },
  "operator": "gt",
  "weight_factor": 1.0,
  "right": { "indicator": "sma", "params": { "period": 50 } }
}
```

**Operand — indicator ref**

```json
{ "indicator": "average_turnover", "params": { "period": 20 } }
```

`indicator` MUST be a **stable Registry id** with `screenable=true` at activate time.

**Operand — constant**

```json
{ "type": "constant", "value": 0 }
```

**Operators:** `gt` \| `gte` \| `lt` \| `lte` \| `eq`.

Semantics (documentation): compare `left` to `(weight_factor × right)` when right is numeric/indicator; weight_factor default `1.0`.

## 5.2 Screener metadata extensions

```json
{
  "factory_key": "high_liquidity",
  "universe": "all_active_equities",
  "schedule_hint": "daily_after_close"
}
```

## 5.3 Screener validation rules

| Code | Rule |
|------|------|
| `SCREENER_ROOT_REQUIRED` | `definition.root` present |
| `SCREENER_NODE_TYPE` | each node `type` ∈ group\|condition |
| `SCREENER_OP_ENUM` | group.op ∈ AND\|OR |
| `SCREENER_OPERATOR_ENUM` | condition.operator ∈ gt\|gte\|lt\|lte\|eq |
| `SCREENER_OPERAND_SHAPE` | left/right are indicator-ref or constant |
| `SCREENER_INDICATOR_REGISTRY` | `indicator` ids exist and are screenable |
| `SCREENER_PARAM_SCHEMA` | params keys ⊆ Registry parameter ids for that indicator |
| `SCREENER_DEPTH_LIMIT` | depth ≤ `validation.max_condition_depth` (default 6) |
| `SCREENER_COUNT_LIMIT` | conditions ≤ `validation.max_conditions` (default 40) |
| `SCREENER_NO_STRATEGY_FIELDS` | reject scoring/threshold keys inside definition |
| `SCREENER_NO_CODE` | no script/formula fields |

Dependencies MUST list every distinct `indicator` id as `uses_indicator`.

---

# 6. Strategy artifact JSON

## 6.1 `definition` shape (portable `config_json`)

Eligibility uses **references only**:

```json
{
  "eligibility_sources": [
    {
      "screener_slug": "minervini_trend_template",
      "screener_factory_key": "minervini_trend_template",
      "min_artifact_version": 1,
      "enabled": true,
      "priority": 1
    }
  ],
  "scoring_model": [],
  "indicators": [],
  "thresholds": {},
  "portfolio_rules": {},
  "capital_allocation": {},
  "cash_rules": {},
  "exit_strategy": {},
  "market_gates": {},
  "recommendation_behaviour": {},
  "risk": {}
}
```

Notes:

- `scoring_model` and `indicators` are aliases of the same list during migration (BC). Portable packs SHOULD write **`scoring_model`** as canonical and MAY duplicate `indicators` for older readers.
- Each scoring row references a **strategy-scorable** Registry composite via `key` (= registry_id).

### Scoring row

```json
{
  "key": "trend_score",
  "enabled": true,
  "weight": 20,
  "minimum": 70,
  "maximum": null,
  "parameters": {}
}
```

Display fields (`display_name`, `category`, `description`, `supports_maximum`) are **optional** in packs — prefer resolving from Registry at import to avoid stale copies. If present, they are hints only; Registry wins on conflict after import.

### Thresholds (illustrative required keys)

`minimum_overall_score`, `open_position`, `increase_position`, `reduce_position`, `exit_position`, `watch`, …

Unknown threshold keys: **ignore with warning** (additive BC).

## 6.2 Strategy metadata extensions

```json
{
  "factory_key": "momentum_factory",
  "style": "momentum",
  "holding_horizon": "swing_to_position",
  "risk_profile": "balanced",
  "template_of": null
}
```

## 6.3 Strategy validation rules

| Code | Rule |
|------|------|
| `STRATEGY_ELIGIBILITY_REFS` | every source has slug and/or factory_key |
| `STRATEGY_NO_EMBEDDED_SCREENER` | no condition tree under eligibility |
| `STRATEGY_WEIGHTS_SUM_100` | sum of weights where `enabled=true` == 100 (± epsilon) |
| `STRATEGY_KEYS_REGISTRY` | each scoring `key` is strategy_scorable in Registry |
| `STRATEGY_THRESHOLDS_NUMERIC` | known threshold values are numbers |
| `STRATEGY_EXIT_SHAPE` | if present, `exit_strategy.rules` is an array of declarative rule objects (no code) |
| `STRATEGY_MARKET_GATES` | gates reference Market Analysis outputs only |
| `STRATEGY_DEPS_RESOLVED` | referenced Screeners resolvable at activate |
| `STRATEGY_NO_CODE` | no script/formula fields |

---

# 7. Worked examples

Full files live under [`artifacts/examples/`](./artifacts/examples/). Summaries below.

| File | Type | Illustrates |
|------|------|-------------|
| `indicator-liquidity-score.json` | Indicator | Composite metadata, deps, no formula |
| `indicator-trend-score.json` | Indicator | Strategy-scorable composite |
| `screener-high-liquidity.json` | Screener | Liquidity primaries |
| `screener-breakout.json` | Screener | Price/volume breakout conditions |
| `strategy-momentum.json` | Strategy | Factory Momentum + Minervini eligibility refs |
| `strategy-swing.json` | Strategy | Swing-oriented weights / thresholds |
| `package-momentum-bundle.json` | Package | Bundle screener + strategy, indicator_refs |

---

# 8. Future extension rules

## 8.1 Additive (allowed without major `schema_version` bump)

1. New **optional** fields on metadata, definition, dependencies, validation.
2. New values in **open** string fields (`tags`, documentation notes).
3. New **capability** / **consumer** flags (unknown flags ignored).
4. New keys under package `extension` namespaced as `extension.<vendor_or_app>.*`.
5. New artifact types require a **minor or major** schema bump *and* a new SD — but unknown `artifact_type` in a package MUST cause that entry to be skipped with error, not a hard crash of the whole import if policy says `strict=false`.

## 8.2 Breaking (require `schema_version` major bump)

1. Renaming / removing required fields.
2. Changing meaning of enums (`operator`, `artifact_type`, `status`).
3. Changing condition-tree evaluation semantics.
4. Allowing embedded Screener trees inside Strategy.
5. Allowing executable or formula fields.

## 8.3 Compatibility reader algorithm

```text
if package.schema_version.major > supported.major → REJECT
if package.schema_version.major < supported.major → use legacy reader adapter if available else REJECT
ignore unknown optional fields
unknown required field after minor bump → REJECT that artifact
if minimum_engine_version > installed → WARN / block activate
```

## 8.4 AI editing contract

1. AI MUST emit JSON conforming to `schema_version` declared in its output.
2. AI MUST only use `indicator` ids from the supplied Registry catalogue snapshot.
3. AI MUST NOT invent operators or node types.
4. AI MUST attach `dependencies` consistent with definition walks.
5. AI MUST set `metadata.origin` to `ai_assisted` for new drafts.
6. AI MUST NOT set `status` to `active` (activation is a human/policy gate).

## 8.5 Reserved future artifact types

`pattern_pack`, `alert_recipe`, `watchlist_recipe`, `evaluation_profile` — same envelope; distinct `definition` schemas in a future revision of this document.

---

# 9. Mapping to as-built persistence (informative)

| JSON | Today |
|------|-------|
| Screener `definition` | `portfolio_screeners.definition_json` |
| Strategy `definition` | Strategy version `config_json` |
| Indicator `definition` | Indicator Registry / seed metadata (not a DB row pack today) |
| `eligibility_sources[].screener_slug` | Junction + screener id; packs prefer slug |
| `scoring_model[].key` | SupportedIndicators / Registry composite id |

---

# 10. Acceptance criteria (design)

1. Envelope fields for all three types are specified.
2. Version triad (`schema_version`, `minimum_engine_version`, `artifact_version`) is defined.
3. Indicator JSON contains no code and no formulas.
4. Screener JSON is the existing condition tree with Registry ids.
5. Strategy JSON references Screeners; scoring keys are Registry ids.
6. Validation rule tables exist per type.
7. Extension / BC rules are explicit.
8. Worked examples cover Momentum, Swing, High Liquidity, Breakout, Liquidity Score, Trend Score.

---

# 11. Document control

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-30 | Initial JSON specification (design only) |
