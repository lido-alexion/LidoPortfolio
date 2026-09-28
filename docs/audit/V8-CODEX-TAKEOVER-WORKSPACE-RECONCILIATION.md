# StoX V8 Codex Takeover Reconciliation

Date: 2026-09-28

## Authoritative starting point

- Branch: `master`
- Takeover HEAD: `8b4f8f021ff329ef833737b19563d2c8b1cbc6d2`
- Remote status: `master...origin/master`
- Staged changes: none
- Tracked files modified: 131
- Untracked paths: 259

The working tree, frozen V8 specifications under `docs/archive/specs/`, and runtime checks are authoritative. Cursor's prose was treated as a hypothesis only.

## Inventory and classification

| Classification | Findings |
|---|---|
| SAFE / COMPLETE | FEAT-055 account-request flow has the inherited model, migrations, policy/verification/admin services, public/admin APIs, mail, purge command, UI, and 135 passing V8 tests overall. FEAT-061 guided-tour backend/frontend foundation is covered by passing tests. FEAT-065 has a tested intraday worker foundation. |
| SAFE / PARTIAL | FEAT-052 telemetry producer instrumentation; FEAT-054 historical bootstrap/fundamentals UI; FEAT-056 ML lifecycle; FEAT-057 feature registry/training support; FEAT-062 deterministic/AI insights; FEAT-063 collector control plane and Python foundation; FEAT-064 provenance and WP-09 return flow. |
| BROKEN / MUST FIX | At takeover, two non-V8 Screener backtest regression tests failed after version-aware cache changes. Both are repaired and now pass. |
| TRANSIENT / DEBUG | Python `__pycache__` directories and compiled `.pyc` files are untracked generated artifacts. They were not used as implementation evidence. Existing documented deployment debug hooks are pre-existing and were not silently removed. |
| MISSING COUNTERPART | FEAT-063 still lacks the complete operational lifecycle: full schema/quality semantics, calendar/session lifecycle, bounded recovery/finalization/backup policy, alerts/thresholds, universe identity handling, and VPS dependency/runtime readiness. FEAT-065 has no complete current-NIFTY-500 orchestration/coverage workflow. |
| REGRESSION RISK | Generated static docs were rewritten by the build command; the changes are tracked and must be separated from source changes when committing. Legacy artifact-library JS assertions conflict with the frozen FEAT-064 ordinary CRUD/copy semantics. |
| SECURITY RISK | Internal collector/backfill endpoints are token-protected in code, but real deployment secret/runtime validation remains outstanding. Public access-request abuse controls require full acceptance audit beyond happy-path tests. |
| MIGRATION RISK | New V8 migrations are ordered after existing schema and use nullable compatibility columns in several provenance paths; live migration/rollback compatibility still needs an environment-backed verification. |
| TEST GAP | Browser build is blocked by Node 18 while Vite requires Node 20.19+; no Playwright run was possible. JS unit suite is 177/182. |

## Verified baseline

- PHP syntax: passed for changed application, config, routes, migrations, and V8 tests.
- Laravel V8: 135 passed, 518 assertions.
- Python microstructure: 5 passed, 1 skipped.
- Python intraday: 9 passed, 5 skipped.
- Frontend build: blocked by environment (`Node.js 18.20.0`; installed Vite requires Node 20.19+ or 22.12+).
- JavaScript unit tests: 177 passed, 5 failed.
- Regression subset at takeover: 35 passed, 2 failed in `ScreenerTest` backtest cache expectations; after repair: 15/15 targeted Screener/backtest tests pass.

## Undocumented inherited work

The prior ledger did not enumerate the substantial FEAT-054/056/057/062/065 additions, the telemetry producer, guided-tour implementation, new V8 test suite, intraday Python package, or the FEAT-064 contextual Strategy-to-Screener return-flow implementation. The working tree contains all of these categories. FEAT-064 WP-09 is implemented through transient browser storage and return selectors; the added e2e/Playwright material is supplementary and must not redefine the frozen UX contract.

## Reconciliation decision

Preserve the inherited implementation and provenance changes. Repair the two backtest regressions, keep the V8 ledger at `REVIEW`/`IN PROGRESS` until frozen acceptance criteria are met, and do not mark FEAT-055, FEAT-061, or any other epic complete solely from file presence. Continue next with backtest compatibility repair, then FEAT-063 operational completeness and the FEAT-055 acceptance audit.

## Codex takeover changes

Commit `2965d39` (`reconcile V8 inherited backtest and microstructure gaps`) contains only the focused reconciliation artifacts, version-aware backtest cache repair, and the FEAT-063 schema/aggregator/test expansion. The remaining inherited work is still uncommitted in the working tree and was not reset, cleaned, stashed, or folded into that commit.

## Continuation checkpoint — 2026-09-28

The frontend baseline was re-established with Node `20.19.1` and npm `10.8.2` from the existing NVM installation. Vite build, typecheck, static documentation checks, and the JavaScript unit suite now pass (`182/182`). The five inherited failures were classified as three superseded V8 expectations, one stale documentation expectation, and one genuine request-account documentation gap; the tests/catalog were corrected to the frozen behavior.

Focused FEAT-063 commits after takeover:

- `b0ef1f3` — durable finalization state, bounded retry, market-session gating, atomic manifests/backups, and finalized-day spool pruning.
- `23fddb0` — Admin status exposure and operational alerts for finalization/backup failure.
- `660a82f` — reconnect resubscription of the complete active universe in Kite full mode.
- `18b0e12` — explicit no-trade rows for active-universe minute coverage.
- `83a0c2a` — persistent VPS paths, dedicated collector venv provisioning, dependency import checks, and corrected systemd release paths.

The collector remains **IN PROGRESS**. Remaining exit-gate work includes explicit coverage/outage quality reporting, full trading-calendar/session tests, universe identity audit/refresh behavior, backup retry evidence, alert cooldown verification, and VPS runtime/live Kite validation. FEAT-055 and FEAT-061 remain **REVIEW** pending their formal acceptance audits.
