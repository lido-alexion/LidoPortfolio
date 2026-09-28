# FEAT-057 frozen feature matrix

This is the acceptance crosswalk for the machine-readable registry in
`app/config/ml_feature_registry.php`. The registry remains the source of
feature keys, versions, horizons, lookbacks and PIT classifications; this
document records the frozen category coverage and the evidence boundary.

| Frozen category | Registry evidence | Horizon applicability | PIT / source rule | Automated evidence | Runtime evidence | Status |
|---|---|---|---|---|---|---|
| Technical | 50-feature registry, technical/price group | Explicit `1m`, `3m`, `6m` profile resolution | as-of daily prices only | `MlFeatureRegistryAdminTest`, dataset builder tests | bounded campaign profile resolution | PASS locally |
| Fundamental | Fundamental metrics, margins and ratios | Explicit per-feature profile resolution | availability date <= sample timestamp | registry, PIT builder and preprocessing tests | bounded 1m/3m/6m campaign persisted per-feature partition coverage; authoritative provider population pending | PASS locally / production population pending |
| Market/regime | Benchmark trend, volatility and regime context | Explicit per-feature profile resolution | PIT market context and dated inputs | `MlMarketContextPitTest` and builder tests | bounded campaign persisted per-feature partition coverage | PASS locally |
| Breadth | Dated breadth/context features | Explicit per-feature profile resolution | dated membership/context only | registry and snapshot coverage tests | bounded campaign persisted per-feature partition coverage; authoritative snapshot population pending | PASS locally / production population pending |
| Sector-relative | Sector-relative strength/context | Explicit per-feature profile resolution | dated sector/universe snapshots; no current fallback | sector PIT tests and registry tests | bounded campaign persisted per-feature partition coverage; authoritative snapshot population pending | PASS locally / production population pending |
| Deterministic pattern | `consolidation_width_20d_pct`, `range_position_20d`, `candle_body_to_range_1d` | Explicit per-feature profile resolution | daily price-only, as-of | `MlFeatureRegistryAdminTest` pattern coverage test | campaign profile resolution | PASS locally |

## Per-feature proof contract

For every registered key, acceptance evidence must be able to read the
following fields from the registry/profile and dataset diagnostics:

| Field | Required evidence |
|---|---|
| key / description | stable machine key and user-facing definition |
| category / version | frozen category and feature version |
| source / lookback | declared source domain and maximum lookback |
| 1m / 3m / 6m | explicit eligibility, never inferred from the key name |
| PIT safety | classification and dataset-builder enforcement |
| missing-value policy | training-only preprocessing behavior |
| implementation / tests | registry key resolves to real feature code and tests |
| coverage | eligible, available, missing and dropped counts per horizon |

The current registry and focused tests prove the first seven fields locally.
The bounded training campaign now provides the complete 50-feature ×
1m/3m/6m per-partition coverage report, profile resolution and preprocessing
execution. Authoritative production-provider population, deployed active-model
pairing where applicable and investor-facing browser acceptance remain
external evidence; the implementation status is therefore `REVIEW`.
