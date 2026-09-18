# AUD-014 - Database Namespace / Prefix Runtime Verification

## 1. Finding Recap

AUD-014 began as `PARTIALLY_IMPLEMENTED` because the repository validated only
the V7 fundamentals/ML migration and had no complete migration or deployed
schema inventory. The accepted contract governs new StoX-owned objects; it
does not require renaming the frozen V1-V6 `portfolio_*` schema in this pass.

The complete review found two real post-cutoff violations in the historical
Replay evidence migration. A forward compatibility migration renamed those
tables to their canonical `stox_` names, and the validator scans all governed
migrations. Production deployment verification now confirms the rename was
applied successfully.

## 2. Authoritative Contract and Cutoff

The V7 namespace specification requires `stox_` for new StoX-owned objects
where naming is under application control, including tables, views, sequences,
indexes, constraints, and other explicitly named objects. It explicitly
preserves compatibility with the existing V1-V6 `portfolio_*` landscape until
a separate coordinated cutover.

This audit uses migration filename `2026_09_12_100001_v7_stox_fundamentals_and_ml.php`
as the governance cutoff. Migrations before that point are the legacy baseline;
migrations at or after it are governed new-object introductions. Framework
tables (`cache`, `cache_locks`, `migrations`, `password_reset_tokens`,
`personal_access_tokens`, `sessions`) are infrastructure exceptions, not
StoX-owned domain objects.

## 3. Complete Migration Inventory

The chronological migration scan found 139 `Schema::create` occurrences across
the full migration history, including historical duplicate/rebuild definitions.
The governed post-cutoff inventory contains 10 objects:

| Migration | Governed objects | Domain |
| --- | --- | --- |
| `2026_09_12_100001_v7_stox_fundamentals_and_ml.php` | `stox_fundamental_settings`, `stox_fundamental_update_runs`, `stox_fundamental_update_jobs`, `stox_fundamental_facts` | V7 fundamental data |
| `2026_09_12_100001_v7_stox_fundamentals_and_ml.php` | `stox_ml_training_runs`, `stox_ml_model_versions`, `stox_ml_predictions`, `stox_ml_drift_checks` | V7 ML lifecycle |
| `2026_09_18_000001_historical_replay_lifecycle_evidence.php` | `stox_recommendation_reservation_events`, `stox_tos_recall_bridge_loan_returns` | Historical Replay lifecycle evidence |

All 10 governed objects now comply. The 129 pre-cutoff creation occurrences
are the established V1-V6/application baseline and are not new namespace
violations merely because their physical names begin with `portfolio_`.

No migration creates a view, materialized view, sequence, trigger, or generated
table through raw SQL. Schema references and raw SQL were searched separately;
the only historical Replay table-name references are the two models, migration
compatibility names, and their services' model queries.

## 4. Production Schema Inventory

The read-only production schema query returned 137 tables:

| Classification | Count | Result |
| --- | ---: | --- |
| `stox_*` | 10 | All governed V7 and post-cutoff Replay tables are present |
| Legacy `portfolio_*` | 121 | Accepted pre-cutoff V1-V6/application baseline; no post-cutoff Replay names remain |
| Laravel/framework | 6 | `cache`, `cache_locks`, `migrations`, `password_reset_tokens`, `personal_access_tokens`, `sessions` |
| Other application-owned/unclassified | 0 | None found |

The production migration ledger shows the namespace rename migration has run.
The canonical Replay evidence tables are present:

- `stox_recommendation_reservation_events`
- `stox_tos_recall_bridge_loan_returns`

The old unprefixed names are absent:

- `portfolio_recommendation_reservation_events`
- `portfolio_tos_recall_bridge_loan_returns`

The remaining 121 `portfolio_*` tables are intentionally retained legacy
V1-V6 schema. No unrelated legacy table was renamed.

## 5. Models and References

`RecommendationReservationEvent` now maps to
`stox_recommendation_reservation_events`, and `RecallBridgeLoanReturn` maps to
`stox_tos_recall_bridge_loan_returns`. Service queries use those models rather
than hard-coded legacy names. The compatibility migration retains the old names
only as source/destination identifiers for a one-time forward rename and
reversible rollback; they are not long-term aliases.

## 6. Durable Enforcement

`StoxNamespaceValidationTest` now:

- scans every migration at or after the explicit V7 cutoff;
- extracts every `Schema::create` table;
- requires the complete governed set to use `stox_`;
- asserts the two historical Replay tables are canonical.

The test deliberately excludes pre-cutoff legacy migrations and framework
migrations, preventing false positives while failing when a future governed
migration introduces an unprefixed table.

## 7. Remediation

The original Replay migration now creates canonical `stox_` tables. Migration
`2026_09_19_000001_v7_namespace_historical_replay_evidence.php` safely renames
existing legacy Replay tables when present and is a no-op for fresh installs
where the canonical tables already exist. It preserves data, indexes, foreign
keys, and application semantics through `Schema::rename`; its down path reverses
the rename before the original migration is rolled back.

No destructive table drop, data rewrite, compatibility view, or broad legacy
rename was introduced.

## 8. Runtime Verification Boundary

Production schema inspection is complete and read-only after deployment. The
final state is 10 `stox_*` governed tables, 121 intentionally retained legacy
`portfolio_*` tables, and zero post-cutoff unprefixed Replay tables.

## 9. Gap Register

| ID | Finding | Classification | Severity |
| --- | --- | --- | --- |
| NS-001 | Two post-cutoff Replay evidence tables were initially created with `portfolio_*` names; the deployed forward rename now places both under canonical `stox_` names | `IMPLEMENTED` | Medium |
| NS-002 | Future namespace validation was previously limited to one migration | `IMPLEMENTED` after cutoff-wide validator | Medium |

## 10. Final AUD-014 Assessment

**Disposition: `IMPLEMENTED` (Medium severity, High confidence).**

Repository enforcement, the forward compatibility remediation, and production
verification are complete. All 10 governed post-cutoff objects use the
canonical `stox_` namespace; the 121 legacy V1-V6 `portfolio_*` tables remain
intentionally retained. The cutoff-wide namespace validator remains in place
to prevent future drift.

## 11. Open Questions

None. The forward rename has been deployed and verified; the legacy V1-V6
`portfolio_*` schema remains intentionally outside this namespace cutover.
