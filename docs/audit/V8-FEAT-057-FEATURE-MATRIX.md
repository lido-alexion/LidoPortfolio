# FEAT-057 frozen feature matrix

This is the acceptance crosswalk for the machine-readable registry in
`app/config/ml_feature_registry.php`. The registry remains the source of
feature keys, versions, horizons, lookbacks and PIT classifications; this
document records the frozen category coverage and the evidence boundary.

| Frozen category | Registry evidence | Horizon applicability | PIT / source rule | Automated evidence | Runtime evidence | Status |
|---|---|---|---|---|---|---|
| Technical | 50-feature registry, technical/price group | Explicit `1m`, `3m`, `6m` profile resolution | as-of daily prices only | `MlFeatureRegistryAdminTest`, dataset builder tests | bounded campaign profile resolution | PASS locally |
| Fundamental | Fundamental metrics, margins and ratios | Explicit per-feature profile resolution | availability date <= sample timestamp | registry, PIT builder and preprocessing tests | campaign path exercised; provider population pending | PASS locally / runtime partial |
| Market/regime | Benchmark trend, volatility and regime context | Explicit per-feature profile resolution | PIT market context and dated inputs | `MlMarketContextPitTest` and builder tests | bounded campaign path | PASS locally / runtime partial |
| Breadth | Dated breadth/context features | Explicit per-feature profile resolution | dated membership/context only | registry and snapshot coverage tests | campaign coverage evidence pending | PASS locally / runtime partial |
| Sector-relative | Sector-relative strength/context | Explicit per-feature profile resolution | dated sector/universe snapshots; no current fallback | sector PIT tests and registry tests | bounded campaign coverage pending | PASS locally / runtime partial |
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
The bounded training campaign proves profile resolution and preprocessing
execution, but does not yet provide authoritative-provider population or a
complete 50-feature × 3-horizon runtime coverage report. Therefore FEAT-057
remains `IN PROGRESS`.
