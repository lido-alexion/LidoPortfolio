# StoX V7 Database Namespace / Prefix Specification

| Field | Value |
|---|---|
| **Feature** | V4-FEAT-055 — StoX Database Table Namespace / Prefix |
| **Version** | V7 |
| **Status** | DECIDED |
| **Owner** | Architecture |

## 1. Purpose

Ensure every database object owned by StoX is immediately identifiable in a database shared with other applications by enforcing a common StoX-specific naming prefix.

## 2. Canonical namespace

The canonical prefix for StoX-owned database objects is:

`stox_`

This prefix is mandatory for all new StoX-owned database objects and is the target naming convention for all existing StoX-owned objects.

## 3. Scope

The namespace rule applies to all StoX-owned database objects where naming is under application control, including:

- tables
- views and materialized views
- sequences
- indexes
- constraints
- other explicitly named StoX-owned database objects

Objects owned by other applications or shared infrastructure are outside the rename scope.

## 4. Existing-object migration

V7 shall audit the complete StoX persistence/schema surface and identify every existing StoX-owned object that does not conform to the `stox_` prefix.

All such objects shall be migrated/renamed so the resulting schema is fully consistent. Applying the prefix only to newly created objects is not acceptable.

The migration must preserve existing data, relationships, keys, indexes, constraints, application behaviour and migration history semantics.

## 5. Cutover model

The migration uses a coordinated cutover:

- database object names and all application references move together;
- ORM/model mappings, SQL, migrations, tests, scripts, jobs and operational tooling must be updated consistently;
- no long-term compatibility aliases, compatibility views or duplicate old-name objects are retained.

Temporary implementation-only mechanisms used during a single controlled migration are acceptable if removed before the epic is considered complete.

## 6. Future enforcement

The naming convention must be automatically enforced so future changes cannot silently reintroduce non-conforming StoX object names.

V7 shall add an automated CI/test/migration validation that fails when a newly introduced StoX-owned database object does not follow the `stox_` naming convention.

Documentation alone is not sufficient enforcement.

## 7. Acceptance criteria

FEAT-055 is complete when:

1. all StoX-owned database objects have been inventoried;
2. every controllable StoX-owned object name conforms to the `stox_` prefix;
3. all affected application and operational references have been migrated;
4. existing data and application behaviour are preserved;
5. no long-term old-name compatibility layer remains; and
6. automated validation prevents future namespace drift.

## 8. Non-goals

This epic does not redesign the database schema, normalize unrelated tables, change data ownership, split StoX into a separate database, or rename objects belonging to other applications merely for stylistic consistency.
