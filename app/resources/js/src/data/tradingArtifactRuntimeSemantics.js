/**
 * Runtime semantics for Trading Artifacts (Recommendation / Screener / Import).
 * Code-verified against StrategyConfigurationService, RecommendationGenerationPipeline,
 * StrategyEligibilityService, ScreenerEvaluationService, ArtifactValidationService.
 */

export const RUNTIME_SEMANTICS_OVERVIEW =
    'This section documents **runtime behaviour** of the Recommendation Engine and related services. '
    + 'Schema fields alone are not enough - AI authors need evaluation order, gating, and edge cases.\n\n'
    + '---\n\n'
    + '## 1. Strategy scoring semantics\n\n'
    + '**Where:** `StrategyConfigurationService::score()` during Recommendation generation '
    + '(Evaluation emits 0-100 factor facts; Strategy applies weights).\n\n'
    + '### Formula\n\n'
    + 'For each **enabled** scoring row with `weight > 0`:\n\n'
    + '1. Add `weight` to `totalWeight` (even if the factor later gates to zero).\n'
    + '2. Read the Evaluation fact for `key` (canonicalised aliases apply).\n'
    + '3. Compute `contribution`:\n'
    + '   - Missing / non-numeric fact -> `contribution = 0`, `gated = true`\n'
    + '   - `value < minimum` (when minimum set) -> `contribution = 0`, `gated = true`\n'
    + '   - `value > maximum` (when maximum set) -> `contribution = 0`, `gated = true`\n'
    + '   - Else: `normalized = clamp(value, 0, 100) / 100`\n'
    + '     - Special case **`risk_score`** with a maximum set: `normalized = 1 - value/100` (higher risk lowers contribution)\n'
    + '     - `contribution = round(normalized * weight, 4)`\n'
    + '4. `overall_score = round((earned / totalWeight) * 100, 4)`, then clamp to `[0, 100]`\n\n'
    + 'When enabled weights sum to **100** and nothing is gated:\n\n'
    + '`overall ≈ Σ(weight × factor_score) / 100`\n\n'
    + 'which is the same as a weight-normalised average:\n\n'
    + '`overall = Σ(weight × factor_score) / Σ(weight)`\n\n'
    + '### Rounding / precision\n\n'
    + '- Per-factor `contribution` and `overall_score`: **4 decimal places** (`round(..., 4)`).\n'
    + '- Display UIs may show fewer decimals; stored recommendation math uses the 4 d.p. overall.\n\n'
    + '### `enabled`, `minimum`, `maximum`\n\n'
    + '| Field | Behaviour |\n'
    + '|-------|-----------|\n'
    + '| `enabled: false` | Factor ignored - not in `totalWeight`, no contribution |\n'
    + '| `minimum: 70` | Soft gate: if fact `< 70`, contribution = **0** (weight still in denominator) |\n'
    + '| `maximum: 90` | Soft gate: if fact `> 90`, contribution = **0** (same dilution) |\n'
    + '| Both set | Pass band is **`minimum <= value <= maximum`** (inclusive). Outside -> gated zero |\n'
    + '\n'
    + '**Important:** Failing min/max does **not** reject the stock from Recommendations. '
    + 'It zeros that factor and **dilutes** overall because weight remains in `totalWeight`. '
    + 'There is no "hard fail whole candidate" on a single gated factor.\n\n'
    + '---\n\n'
    + '## 2. Eligibility and decision pipeline\n\n'
    + 'Daily / recommendation cycle (simplified):\n\n'
    + '```text\n'
    + 'Data readiness\n'
    + '    |\n'
    + '    v\n'
    + 'Discovery (patterns + recent screener hits / membership fallback)\n'
    + '    |\n'
    + '    v\n'
    + 'Evaluation (factor facts 0-100 - no Strategy weights yet)\n'
    + '    |\n'
    + '    v\n'
    + 'RecommendationGenerationPipeline\n'
    + '    |-- resolve eligibility_sources (UNION of enabled Screeners)\n'
    + '    |-- StrategyConfigurationService.score (scoring_model)\n'
    + '    |-- ExitStrategyEvaluator (owned holdings only)\n'
    + '    |-- buildMarketOpinion + decidePortfolioAction (thresholds)\n'
    + '    |-- market_gates demotion (OPEN/INCREASE only)\n'
    + '    |-- rankDrafts\n'
    + '    |-- allocateCapital (portfolio_rules + cash)\n'
    + '    |-- persistDrafts\n'
    + '```\n\n'
    + '| Strategy section | When it applies |\n'
    + '|------------------|-----------------|\n'
    + '| `eligibility_sources` | Filters which names may receive **new entry** actions; holdings always reviewed |\n'
    + '| `scoring_model` | Weighted overall score from Evaluation facts |\n'
    + '| `thresholds` | Opinion (bull/bear/neutral) + OPEN/WATCH/HOLD/INCREASE/REDUCE/EXIT |\n'
    + '| `exit_strategy` | Can force EXIT on **held** names |\n'
    + '| `market_gates` | After scoring: demote OPEN/INCREASE when blocked; size multipliers |\n'
    + '| `portfolio_rules` | Position % caps, cash reserve/deploy, max new positions; can demote unfunded buys to WATCH |\n'
    + '| `capital_allocation` | Score bands for allocation % |\n'
    + '\n'
    + 'Discovery itself is **not** limited to the active Strategy eligibility list '
    + '(it merges recent screener hits broadly). Strategy eligibility is enforced in Recommendation.\n\n'
    + '---\n\n'
    + '## 3. Multiple eligibility screeners\n\n'
    + '- Mode: **UNION** (`screener_union`). A stock is eligible if it appears in **any** enabled source\'s latest completed run (within ~72 hours).\n'
    + '- **Not** intersection.\n'
    + '- `priority`: sort order only - does **not** change union semantics.\n'
    + '- Duplicate hits across screeners are **de-duplicated** by security id.\n'
    + '- Empty `eligibility_sources`: unrestricted (no screener filter for entries).\n'
    + '- Sources configured but no recent completed runs: pipeline may treat as unrestricted pending runs (no artificial empty set).\n'
    + '- **Holdings** are always considered for HOLD/REDUCE/EXIT even if not currently eligible for new entries.\n\n'
    + '---\n\n'
    + '## 4. Thresholds - runtime behaviour\n\n'
    + 'Factory defaults (Momentum): `open_position=85`, `increase_position=90`, `watch=60`, `reduce_position=40`, `exit_position=20`.\n\n'
    + '### Example: overall score = 82 (not held)\n\n'
    + '- Opinion: **NEUTRAL** (not >= open 85, not <= exit 20), strength often **MODERATE** if >= watch 60.\n'
    + '- Action: **WATCH** (OPEN requires score >= open or strong-bull path).\n\n'
    + '### Example: overall score = 82 (held)\n\n'
    + '- Action: typically **HOLD** (not EXIT/REDUCE/INCREASE under default bands).\n\n'
    + '### Evaluation order / conflicts\n\n'
    + 'Thresholds are **not** exclusive score bands with ties. Code uses sequential rules:\n\n'
    + '1. Market opinion: if `score >= open` -> bullish; else if `score <= exit` -> bearish; else neutral.\n'
    + '2. Portfolio action (not held): OPEN only if bullish/strong path or `score >= open`; else WATCH.\n'
    + '3. Portfolio action (held): EXIT if bearish/`score <= exit`; else REDUCE checks; else INCREASE if bullish and `score >= increase`; else HOLD.\n\n'
    + 'Comparisons are **inclusive** on the side used (`>=` open/watch/increase, `<=` exit/reduce).\n\n'
    + '**Open overrides Watch** when open condition is met. Exit/reduce paths take precedence over hold/increase for holdings when those conditions fire.\n\n'
    + '`minimum_overall_score` is stored on Strategy config / UI but is **not** currently applied as a hard gate in the recommendation pipeline (use `open_position` / scoring mins instead).\n\n'
    + '---\n\n'
    + '## 5. Exit strategy - execution semantics\n\n'
    + '- Evaluated on **owned holdings** (and a screener-exit-only pass for holdings missing from the eval set).\n'
    + '- Runs each Recommendation cycle for held names.\n'
    + '- `mode: "any"` (default): if **any** enabled rule matches, exit triggers (all matches are recorded).\n'
    + '- `mode: "all"`: **every enabled rule** must match to trigger.\n'
    + '- Rules can use Strategy/Evaluation scores (`score_exit`, `rs_weakening`, `trend_weakening`), price/SMA/ATR facts, unrealized PnL, and **`screener_exit`** (registry screener hit lists by local screener id - not an embedded condition tree).\n'
    + '- Triggered exit overrides other actions to **EXIT**.\n\n'
    + '---\n\n'
    + '## 6. Market gates\n\n'
    + 'When `market_gates.enabled` is true and sentiment/phase/risk checks fail entry:\n\n'
    + '- Scoring **still runs** (gates do not skip Evaluation/Strategy score).\n'
    + '- **OPEN / INCREASE** demoted to WATCH (not held) or HOLD (held).\n'
    + '- **EXIT / REDUCE / HOLD** still allowed - sells and holds are not blocked by entry gates.\n'
    + '- Gates may also shrink position size multipliers when partially allowing entry.\n\n'
    + '---\n\n'
    + '## 7. Portfolio rules\n\n'
    + '- Target size uses something like `min(max_position_size_pct, band_or_default_pct)` - **max always caps** default/band.\n'
    + '- `min_cash_reserve_pct` / `max_cash_deployment_pct` reduce available cash before allocation.\n'
    + '- If cash cannot fund an OPEN/INCREASE, the draft is typically demoted to **WATCH** (`ALLOCATION_UNFUNDED`) - i.e. rules can **suppress BUY actions**, not only leave a zero-qty BUY.\n'
    + '- `max_new_positions_per_cycle` limits how many new opens survive ranking/allocation.\n\n'
    + '---\n\n'
    + '## 8. Indicator parameter defaults (Screeners)\n\n'
    + '**Catalogue / UI defaults** (Indicator Registry tables) are what the editor shows when you add a condition '
    + '(e.g. EMA catalogue default period **50**).\n\n'
    + '**Runtime** (`TechnicalIndicatorService`): if a param key is **omitted** from JSON, the service uses its own fallbacks - for many series including **`ema`/`sma` that fallback is `period ?? 20`**, not necessarily the catalogue UI default.\n\n'
    + 'Therefore:\n\n'
    + '```json\n'
    + '{ "indicator": "ema" }\n'
    + '```\n\n'
    + 'is **not guaranteed** identical to\n\n'
    + '```json\n'
    + '{ "indicator": "ema", "params": { "period": 50 } }\n'
    + '```\n\n'
    + '**AI best practice:** always set params explicitly for production artifacts.\n\n'
    + '---\n\n'
    + '## 9. Parameter validation\n\n'
    + 'On Screener Validate / Import / save (`ScreenerDefinitionValidator`):\n\n'
    + '| Input | Result |\n'
    + '|-------|--------|\n'
    + '| Param present, non-numeric | **Reject** |\n'
    + '| Param present, outside catalogue min/max (e.g. period 0, period 500, mult 9) | **Reject** |\n'
    + '| Param **omitted** | Not range-checked; runtime fallback applies |\n'
    + '\n'
    + 'No silent clamp to catalogue max on Validate. Do not rely on runtime `max(1, period)` as a substitute for correct JSON.\n\n'
    + '---\n\n'
    + '## 10. Missing data\n\n'
    + '| Situation | Screener | Strategy score |\n'
    + '|-----------|----------|----------------|\n'
    + '| Insufficient OHLCV bars | Stock **skipped** (`insufficient_data`) - not a match |\n'
    + '| `needs_volume` but no volume | Stock **skipped** (`insufficient_volume`) |\n'
    + '| Indicator returns null on a condition | That condition is **false** (AND fails / OR may still pass) |\n'
    + '| Corporate action / DQ hold | Separate Data Quality guards may exclude names from pipelines |\n'
    + '| Missing Evaluation fact for a scoring key | Factor contribution **0**, gated; weight still dilutes overall |\n'
    + '\n'
    + '---\n\n'
    + '## 11. Numeric precision and `eq`\n\n'
    + '- Screener `eq`: float compare with abs epsilon **1e-4**, else relative **1e-6** (`floatsEqual`). Prefer `gte`/`lte` for thresholds.\n'
    + '- Strategy overall: 4 decimal places as above.\n'
    + '- Cash/allocation amounts often rounded to 2-4 decimals in plan builders.\n\n'
    + '---\n\n'
    + '## 12. Import normalisation\n\n'
    + '### Strategy\n\n'
    + '- Aliases: `scoring_model` <-> `indicators`; eligibility `factory_key` <-> `screener_factory_key`.\n'
    + '- Scoring keys normalised to catalogue; unsupported keys dropped; missing catalogue rows filled from defaults; enabled weights redistributed toward 100 when needed by `normalizeConfig`.\n'
    + '- Known sections merged (`thresholds`, `portfolio_rules`, `market_gates`, `exit_strategy`, ...).\n'
    + '- Unknown **top-level** definition keys are generally **not** preserved (config rebuilt from known sections).\n'
    + '- Extra **nested** keys inside merged objects may survive `array_merge`.\n'
    + '- Slug/name collisions get suffix / `(import)` rename.\n'
    + '- Always Import as **draft**; activation is Select.\n\n'
    + '### Screener\n\n'
    + '- Validate tree; create with unique slug; definition stored as provided (after validation).\n'
    + '- Prefer exporting a working screener before large edits.\n\n'
    + '---\n\n'
    + '## 13. Version compatibility\n\n'
    + '| Field | Behaviour |\n'
    + '|-------|-----------|\n'
    + '| `schema_version` | Required. Major must be <= app major (`1.0` today). Empty or newer major -> `SCHEMA_VERSION_UNSUPPORTED` |\n'
    + '| `minimum_engine_version` | Exported for documentation / future use; **not currently enforced** on Validate/Import |\n'
    + '\n'
    + 'Ship `schema_version: "1.0"` on all envelopes.\n\n'
    + '---\n\n'
    + '## 14. Normative authoring rules\n\n'
    + 'Authoring MUST / SHOULD rules live in the **AI Authoring Contract**. Do not treat this Runtime section as a second constitution.\n\n'
    + '---\n\n'
    + '## 15. Complete end-to-end walkthrough\n\n'
    + 'See **Complete Examples** in the AI Authoring Guide (canonical Minervini-style lifecycle). '
    + 'Worked JSON lives in Screener/Strategy Registry examples and the Trading Cookbook.\n';

/** Canonical lifecycle for AI Guide “Complete Examples”. */
export const COMPLETE_E2E_WALKTHROUGH_MARKDOWN = [
    '# Complete Examples',
    '',
    '## Canonical end-to-end lifecycle (Minervini-style)',
    '',
    '1. Choose philosophy — trend template eligibility + RS/trend/momentum ranking.',
    '2. Select indicators — Screenable: `close`, `sma`, `sma_spread_pct`, `low_52w`, `high_52w`. Scorable: `relative_strength`, `trend_score`, `momentum_score`, `volume_score`, `breakout_score`.',
    '3. Build Screener — Screener Registry Minervini example or factory `minervini_trend_template`. Set all `params.period` explicitly.',
    '4. Validate Screener until `ok`.',
    '5. Import Screener; note final `slug`.',
    '6. Create Strategy envelope `artifact_type: strategy` with unique slug/name.',
    '7. Reference Screener via `eligibility_sources[].screener_slug` / `screener_factory_key`.',
    '8. Configure scoring — enabled weights = 100; optional `minimum` gates and Composite `parameters`.',
    '9. Configure thresholds — e.g. open 85 / watch 60 / exit 20 (or Export Momentum defaults).',
    '10. Validate Strategy until `ok`.',
    '11. Import Strategy (draft).',
    '12. Select Strategy (sole active Strategy).',
    '13. Engine execution — Discovery → Evaluation → eligibility UNION → weighted score → exits/gates/thresholds → capital → persist.',
    '14. Final output — OPEN/INCREASE/REDUCE/EXIT and WATCH/HOLD on Recommendations, subject to cash and gates.',
    '',
    '## Where full JSON lives',
    '',
    '- Screener Registry — complete Screener envelopes (operators, operands, Minervini/Darvas/…).',
    '- Strategy Registry — complete Strategy envelopes (scoring + optional sections).',
    '- Trading Cookbook — philosophy + paired Screener/Strategy recipes.',
    '',
    'AI MUST satisfy the AI Authoring Contract before emitting any example-based JSON.',
    '',
].join('\n');

export const RUNTIME_SEMANTICS_TOPIC = {
    id: 'trading-artifact-runtime',
    keyword: 'trading-artifact-runtime',
    aliases: ['runtime-semantics', 'recommendation-scoring', 'artifact-runtime'],
    title: 'Trading Artifact Runtime Semantics',
    routeLabel: '/docs/trading-artifact-runtime.html',
    match: () => false,
    summary:
        'How StoX combines Strategy scores, eligibility UNION, thresholds, exits, market gates, portfolio rules, param defaults, missing data, and Import normalisation - for AI and humans.',
    overview: RUNTIME_SEMANTICS_OVERVIEW,
    controls: [
        {
            name: 'Use with AI guide download',
            description:
                'This topic is included in /docs/stox-trading-artifacts-ai-guide.md (Download AI authoring guide on Screener/Strategy Registry).',
        },
    ],
    concepts: [
        {
            name: 'Soft min/max gates',
            description:
                'Failing scoring minimum/maximum zeros that factor contribution but keeps its weight in the denominator - dilutes overall; does not reject the stock.',
        },
        {
            name: 'Eligibility UNION',
            description:
                'Multiple eligibility_sources OR together; priority is sort order only; holdings always reviewed.',
        },
        {
            name: 'Explicit Screener params',
            description:
                'Omitted params use TechnicalIndicatorService fallbacks (often period 20), which may differ from catalogue UI defaults (e.g. EMA 50).',
        },
    ],
    related: [
        'ai-authoring-contract',
        'authoring-trading-artifacts',
        'strategy-registry',
        'screener-registry',
        'indicator-registry',
        'trading-cookbook',
        'recommendations',
    ],
};

/** Short pointers appended to registry topics */
export const SCREENER_RUNTIME_POINTER =
    '## Runtime notes (params, missing data, eq)\n\n'
    + 'See **Trading Artifact Runtime Semantics** (`trading-artifact-runtime`) for full behaviour. Short form:\n\n'
    + '- **Always set `params` explicitly** - omitted keys use TechnicalIndicatorService fallbacks (often `period: 20`), which may differ from Indicator Registry UI defaults (e.g. EMA catalogue default 50).\n'
    + '- Out-of-range params on Validate/Import -> **reject** (not clamp).\n'
    + '- Insufficient bars / missing volume (when required) -> stock **skipped**; null indicator on a leaf -> condition **false**.\n'
    + '- `eq` uses float epsilon (~1e-4 abs / 1e-6 relative).\n\n';

export const STRATEGY_RUNTIME_POINTER =
    '## Runtime notes (scoring, eligibility, thresholds)\n\n'
    + 'See **Trading Artifact Runtime Semantics** (`trading-artifact-runtime`) for the full Recommendation pipeline. Short form:\n\n'
    + '- Overall ≈ weighted average of enabled factor scores; gated min/max -> contribution 0 but **weight still dilutes**.\n'
    + '- Multiple `eligibility_sources` = **UNION**; `priority` is order only.\n'
    + '- Thresholds use sequential if/else (not exclusive bands); score 82 with defaults is typically **WATCH** (not held) / **HOLD** (held).\n'
    + '- `market_gates` demote OPEN/INCREASE only; exits still run.\n'
    + '- Portfolio cash rules can demote unfunded buys to **WATCH**.\n\n';

export const INDICATOR_RUNTIME_POINTER =
    '## Runtime notes (defaults vs catalogue)\n\n'
    + 'Catalogue **default** values are editor/documentation defaults. '
    + 'If Screener JSON omits a param, `TechnicalIndicatorService` applies its own fallback '
    + '(commonly `period ?? 20` for SMA/EMA and many others) - **not always** the catalogue default. '
    + 'See **Trading Artifact Runtime Semantics**. AI authors should always emit explicit `params`.\n\n';
