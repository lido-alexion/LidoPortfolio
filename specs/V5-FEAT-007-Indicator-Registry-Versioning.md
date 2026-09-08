# V5 FEAT-007 — Indicator Registry Deeper Versioning / Remaining Cutover

**Status:** COMPLETE / VERIFIED
**Date:** 2026-09-07
**Completed:** 2026-09-08

## Problem
V3 shipped Indicator registry foundations, but V5 needs the registry to become the authoritative, version-aware catalogue and evidence source used by Strategies, Screeners and later artifact tooling without silently changing existing deployed behaviour.

## Frozen behaviour
- Indicators are **system-owned definitions** with stable Indicator identity and explicit version.
- Indicator definitions use per-Indicator SemVer.
- Lifecycle is `Planned/Stub → Active → Deprecated → Retired`.
- Existing Strategy/Screener configurations do **not** auto-migrate merely because a newer Indicator version becomes current.
- The current Indicator definition is used for future configuration/authoring choices unless an artifact is already pinned to an older exact dependency version.
- V5 does not expose arbitrary Investor pinning/selection of historical Indicator versions outside artifact dependency semantics.
- Strategy-configured parameters remain authoritative when explicitly supplied. Registry defaults are fallback/default authoring values, not a mechanism for retroactively changing existing Strategy behaviour.
- Runtime/evaluation evidence records the exact Indicator identity/version, effective parameters, result value/state, as-of information and relevant component evidence.
- Explicit result states must distinguish successful numeric/result evidence from unavailable/incomplete/error states; no invented values.
- The Registry is the authoritative catalogue for Screener/Strategy dependency references and exact Indicator IDs/parameter schemas.
- Dependencies between Indicators/artifacts must be represented sufficiently to support dependency/impact analysis.
- Deprecation/retirement does not rewrite historical evidence or silently mutate active bound artifacts.
- Admin may inspect the Registry and dependency/impact information; V5 does not require a separate Investor-facing Registry product surface.
- Existing Indicator formulas/business logic are not redesigned merely to fit the Registry. The feature versions and exposes the existing authoritative definitions.
- Historical versions are created only where real prior definitions can be established; V5 must not fabricate a fake version history for legacy Indicators.

## Evidence contract
Each Indicator evaluation must be able to expose, as applicable:
- stable Indicator ID;
- Indicator version;
- effective parameter set;
- value/result state;
- as-of/session date;
- underlying component/evidence references sufficient for auditability;
- missing/incomplete/error reason when no valid result exists.

## Architecture
- Registry definition/version data is authoritative metadata; runtime calculation services remain responsible for actual Indicator computation.
- Artifact dependencies reference exact Indicator versions where FEAT-008 requires immutable publication.
- Dependency graph/impact queries are derived from real artifact/dependency relationships rather than duplicated manually maintained lists.
- Existing shipped Indicator infrastructure should be evolved in place; avoid parallel registries.

## UX
- Admin/read-only registry catalogue with lifecycle/version/dependency/impact visibility is sufficient for V5.
- Progressive disclosure: catalogue first, then version/schema/evidence/dependency details.
- Investor workflows continue to encounter Indicators through Strategies/Screeners rather than a new standalone Registry navigation requirement.

## Acceptance criteria
1. Every active Indicator has stable identity, current explicit version and lifecycle state.
2. Exact Indicator version/parameters can be persisted with Strategy/Screener artifact dependencies and evaluation evidence.
3. Publishing a newer Indicator version never silently changes an already-published/pinned Strategy/Screener version.
4. Registry defaults cannot overwrite explicit Strategy parameter values.
5. Deprecation/retirement preserves historical artifacts/evidence and produces dependency impact visibility.
6. Missing/error Indicator results are explicit and never fabricated as valid numeric values.
7. Legacy Indicators are cut over without inventing unsupported historical version lineage.
8. Automated tests cover version pinning, lifecycle, parameter precedence and evidence identity.

## Dependencies
- Existing V3 Indicator/Screener/Strategy registries and evaluation engine.
- FEAT-008 Trading Artifact Framework consumes exact Indicator versions/dependency graph.
- FEAT-020 simulation uses pinned Indicator/dependency worlds.

## Non-goals
- Formula redesign of existing Indicators.
- Investor-authored Indicators in V5 unless already supported elsewhere.
- Automatic migration of existing published artifacts to newer Indicator versions.
- Fabricated historical Indicator versions.
- Standalone Investor Registry product surface.

## Completion evidence

- The in-memory authoritative registry retains multiple exact SemVer definitions per stable ID, selects the highest Active version for new authoring, supports the full Planned/Stub/Active/Deprecated/Retired lifecycle, and preserves exact deprecated/retired lookup without inventing legacy history.
- Strategy and Screener authoring persist exact Indicator versions. Existing unpinned legacy artifacts project against their established baseline, explicit pins remain unchanged, and invalid requested versions are rejected.
- Indicator, Strategy and Screener artifact dependencies expose exact `ref_version` values. Admin detail APIs and UI expose available versions, version-specific dependency trees, and direct dependent impact.
- Evaluation keeps backward-compatible numeric fields and adds structured evidence for registry identity/version, effective parameters, explicit available/unavailable/error state, value, source components, reason/error and market-session as-of date. Neutral legacy stubs are identified as unavailable instead of represented as fabricated valid evidence.
- Strategy parameters override global defaults, and evaluation grouping fingerprints include the pinned Indicator-version world.
- Verification on 2026-09-08: full backend suite passed 1,408 tests / 8,516 assertions; all 198 JavaScript tests passed; strict TypeScript checking and the production Vite build passed under Node 22.
