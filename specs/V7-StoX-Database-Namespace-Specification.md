# StoX V7 Database Namespace / Prefix Specification

| Field | Value |
|---|---|
| **Feature** | V4-FEAT-055 — StoX Database Table Namespace / Prefix |
| **Version** | V7 |
| **Status** | CLOSED / SUPERSEDED BY DEPLOYMENT ARCHITECTURE CHANGE |
| **Owner** | Architecture |
| **Closed** | 2026-09-15 |

## 1. Closure decision

FEAT-055 is closed at its current implementation state.

The epic was created because StoX previously used a database shared with other applications, making a product-specific namespace necessary to distinguish StoX-owned database objects from unrelated application objects.

That architecture no longer applies. StoX has moved to the dedicated `stoxla.in` VPS and now uses a dedicated StoX production database. The original requirement to rename every legacy `portfolio_*` object solely to avoid collisions or ambiguity inside a shared database therefore no longer provides sufficient product or operational value to justify a large coordinated migration.

The work already implemented remains valid:

- new V7-owned StoX tables use the `stox_` prefix;
- automated migration validation prevents accidental namespace drift for new V7 objects;
- existing V1–V6 `portfolio_*` tables remain unchanged and continue to use their established application mappings.

No full legacy `portfolio_*` → `stox_*` cutover is required for FEAT-055 closure.

## 2. Historical purpose

The original purpose was to ensure every database object owned by StoX was immediately identifiable in a database shared with other applications by enforcing a common StoX-specific naming prefix.

The canonical prefix selected for new StoX-owned objects was:

`stox_`

## 3. Implemented state retained

The following implementation remains part of StoX:

1. New V7 analytical/fundamental/ML database objects use the `stox_` prefix.
2. Automated migration/schema validation covers newly introduced V7 StoX-owned objects.
3. Existing legacy V1–V6 objects retain their `portfolio_*` names to preserve frozen application behavior and avoid a high-risk migration with no current architectural requirement.

## 4. Superseded original migration requirement

The earlier specification required all existing StoX-owned database objects to be migrated to `stox_*` in one coordinated cutover and prohibited long-term legacy names.

That requirement is explicitly superseded by the move to a dedicated StoX database.

The existing `portfolio_*` namespace is now accepted technical history rather than an open V7 product gap. Future work must not reopen a mass rename merely to satisfy this obsolete shared-database requirement.

A later database-name migration may still be proposed if it has an independent engineering or product justification, but it would be a new piece of work and not unfinished FEAT-055 scope.

## 5. Final acceptance state

FEAT-055 is considered complete/closed because:

- the shared-database collision/identification problem that motivated the epic no longer exists;
- new V7 objects already follow the selected `stox_` convention;
- future V7 namespace drift is guarded automatically; and
- retaining legacy `portfolio_*` tables is now an intentional architecture decision for the dedicated StoX database.

## 6. Non-goals after closure

FEAT-055 does not require:

- renaming existing V1–V6 `portfolio_*` tables;
- rewriting historical migrations solely for naming consistency;
- adding compatibility aliases or duplicate tables;
- changing existing ORM/model mappings solely for prefix uniformity; or
- performing a production schema cutover whose only benefit would be cosmetic consistency.
