# V5 FEAT-008 — Implementation Status and Closure Evidence

**Status:** COMPLETE

**Reconciled:** 2026-09-15

**Authority:** [`V5-FEAT-008-Trading-Artifact-Framework.md`](V5-FEAT-008-Trading-Artifact-Framework.md)

## Implemented and verified

- Account-owned artifact lineages with one optimistic-concurrency Draft and immutable SemVer publications.
- Exact artifact/Indicator dependency pinning, strict DAG validation and nested-Bundle rejection.
- Stable Portfolio bindings with immutable revision history, explicit settings/enablement revisions and atomic opt-in upgrades; no latest-version following.
- Authenticated immutable-version sharing with exact dependency closure, durable adoption, revocation and Fork provenance.
- Portable checksum-validated export and all-or-nothing staged foreign import into new local Draft lineages.
- Transactional Bundle plans with stale-plan rejection, full member rollback on failure, retained deployment evidence and no perpetual Bundle control.
- Derived `Usable | Warning | Blocked` binding state over transitive exact dependencies, hourly refresh and deduplicated FEAT-004 Action-required notification/resolution for blocked enabled bindings.
- Account/Portfolio-scoped APIs for Library inspection, structural diff, lifecycle actions, binding actions, sharing, packages and Bundles.
- Consolidated Investor Artifact Library UI for search, provenance, lifecycle, exact dependencies, diff, Draft authoring, publishing, explicit deployment, sharing/Fork, import/export and archive.
- Published history cannot be physically deleted; archive leaves history and valid active bindings intact.
- Deterministic, idempotent legacy Screener/Strategy backfill creates exact immutable publications and Portfolio bindings, resolves Screeners before dependent Strategies, supports one-Portfolio rollout, and rolls back invalid rows without partial mappings.
- Library binding and explicit-upgrade transactions create/update the existing Strategy/Screener runtime identities rather than a parallel execution model; projection failure rolls back the binding transaction.
- Live Recommendation generation consumes the exact enabled usable Strategy binding definition and fails closed for mapped-but-unavailable bindings. Recommendation, manual/live/GTT order, and fill evidence retain the originating artifact version and binding revision.
- Manual/scheduled Screener runs pin the exact immutable definition and binding revision at start and retain it across chunk continuation; inconsistent or unavailable mapped bindings fail closed.
- Screener and Strategy backtests retain exact artifact-version/binding-revision evidence and immutable definition/config snapshots across resumable chunks, so a later binding upgrade cannot reinterpret an in-flight simulation.
- Mapped legacy editors/registries are explicitly read-only compatibility projections and link to the authoritative Artifact Library; legacy mutation/enable/archive/delete endpoints reject lifecycle bypasses.
- All remaining legacy Strategy/Screener creation, validated JSON import and shared-copy authoring paths now create account-owned Artifact Library Drafts without creating runnable unmapped rows. New Screener Drafts retain suggested Portfolio scope, watchlist/index, schedule and notification settings for the later explicit bind transaction.
- The rollout command supports an exact transactional `--dry-run` inventory, including per-row failures and a failing exit code, before any committing run. The tested operational and restore procedure is documented in [`V5-FEAT-008-ROLLOUT-RUNBOOK.md`](V5-FEAT-008-ROLLOUT-RUNBOOK.md).
- Strategy envelopes support an optional minimal immutable configurable-parameter declaration (stable key, definition-relative path, scalar type, and optional bounds/choices). Publication rejects malformed or nonexistent paths. FEAT-020 consumes only these declarations for validated run-local overrides and provenance-linked Backtest-to-Draft creation.

Closure evidence:

- 2026-09-11 closure recheck: an isolated fresh migrated/seeded database completed the full migration chain and the read-only `--dry-run` predicted 2 mappings, 0 already mapped and 0 failures with no committed writes. At that checkpoint, production-shaped restored-data evidence remained required; the 2026-09-15 production rollout below satisfies that gate.
- 2026-09-15 production rollout on the dedicated StoX VPS/database (`https://stoxla.in`, deployed code lineage `6307a4e` with closure remediation committed as `75ed8ba`):
  - Production dry-run: `10 would be created; 0 already mapped; 0 failed`.
  - Production committing rollout: `10 created; 0 skipped; 0 failed`.
  - Immediate idempotence rerun: `0 created; 10 skipped; 0 failed`.
  - All intended legacy Screeners and Strategies were mapped; matching Portfolio bindings exist with published active versions; enabled/disabled state was preserved; Strategy dependencies resolve to exact published Screener versions.
  - Compatibility Registry surfaces remained read-only projections linked to the Artifact Library.
  - Representative mapped Screener execution completed and persisted `reusable_artifact_version_id` plus `artifact_binding_revision_id` evidence.
  - Representative Recommendation pipeline completed with `298` mapped recommendations carrying artifact-version and binding-revision evidence.
  - Representative Screener and Strategy backtests retained pinned artifact-version/binding-revision evidence and immutable definition/config snapshots across continuation/completion.
- Final CI verification on 2026-09-15 passed on GitHub Actions run `34961289670` for commit `75ed8bac51cac12a59186c0319df45eb2333657b`: MySQL 8.4 migration/seed, backend suite, frontend tests, TypeScript and production build all succeeded.

- Dedicated Artifact lifecycle/runtime/API/rollout suite: **73 tests / 434 assertions passed** after the authoring cutover and dry-run addition.
- Combined Screener behavior, authoring, registry and sharing suite: **38 tests / 359 assertions passed**.
- Frontend JavaScript groups: **144 contract/unit tests + 59 component tests passed**; TypeScript no-emit passed.
- A clean MariaDB 11.4 database completed the entire forward migration/seed chain. The backfill dry run predicted two factory runtime mappings with zero retained writes; the committing run created both with zero failures; the immediate second run skipped both, proving idempotence. A separate MySQL-backed focused suite passed **21 tests / 119 assertions**.
- After repairing the migration, seed, generated OpenAPI and stale unit-fixture gaps exposed by that exercise, the complete backend suite passed **1,457 tests / 8,849 assertions**.
- Local final verification before CI passed: full backend suite **1,575 tests / 9,810 assertions**, OpenAPI `183` operations, frontend tests, TypeScript and production build.
- Final CI backend suite passed with **1 skipped / 1,574 passed / 9,808 assertions** after the MySQL 8.4 migration/seed gate.
- Implementation commits: `843c34e`, `80577a4`, `278a528`, `7f5248d`, `dcf85d6`, `47738b2`, `70f686c`, `11761a9`, `8b6a7b4`, `26d8ed2`, `78fdaf7`, `8214c0d`, `40e0db`, `46485a2`, `a68e473`, `f8e45c8`, `4a83f78`, `0ceac28`.

## Material work still required

None for V5 closure.

## Closure decision

FEAT-008 is **COMPLETE**. Runtime cutover, immutable trading/simulation evidence, authoring cutover, production rollout, idempotence, representative runtime/backtest evidence and final CI/MySQL verification are complete.
