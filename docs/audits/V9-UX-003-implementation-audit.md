# V9-UX-003 implementation audit

- **Frozen specification:** `docs/archive/specs/V9-Customizable-Summary-Fields-Dashboard-Layouts-Specification.md`
- **Starting SHA:** `04b7e93b` (`origin/master`, 2026-10-15)
- **Audit status:** **IMPLEMENTATION IN PROGRESS**; hosted PR CI, review, merge and deployment evidence are pending.

## Acceptance matrix

| Acceptance area | Implementation and evidence | Audit result |
|---|---|---|
| Main dashboard cards, supported sizes, visibility, order, one visible minimum | `DashboardPage.jsx` applies persisted visibility, bounded Bootstrap widths and flex order; `DashboardLayoutToolbar.jsx` offers card-specific presets, drag/drop and Up/Down controls. `dashboard-combo-journeys.spec.js` E2E-49 checks visibility, size and ordering. | Implemented; full gates pending |
| Mandatory summary fields and configurable optional fields/order | `dashboardLayouts.js` defines three mandatory fields; client and `DashboardLayoutDefinition` enforce them. Summary rendering consumes visibility/order. E2E-49 checks required controls and independent variants. | Implemented; full gates pending |
| Desktop/mobile variants and account-local working copy | Local keys include user ID; local data stores both variants and lock state. E2E-49 verifies account separation, variant independence, lock and reset. | Implemented; full gates pending |
| Account-private named dashboards | Authenticated `/dashboard-layouts` API queries by authenticated user; item writes conceal cross-account records with 404. `V9DashboardLayoutApiTest` covers ownership, listing, default and duplicate behavior. | Implemented; full gates pending |
| CRUD, duplicate, default and autosave | `DashboardLayoutController` and `DashboardLayoutToolbar` implement named lifecycle and Saving/Saved/Failed feedback. E2E-50 exercises success and rejected create/rename/default/duplicate/delete/autosave paths. | Implemented; full gates pending |
| Cross-device stale-write protection | Added per-record integer revision, forward migration, revision-conditional definition updates and HTTP 409 on stale saves. API test verifies first update increments revision and stale update cannot change it; browser journey checks conflict preserves local edits and the newer server definition. | Implemented; focused and CI gates pending |
| Named/local conversion and reset | Toolbar supports save-as-named, copy-to-device and local reset. E2E-50 exercises copy and reset. | Implemented; full gates pending |
| Configuration-only validated JSON import/export | Export serializes only schema/version/lock/desktop/mobile preferences. Import caps size, parses JSON, normalizes known cards/fields, keeps mandatory fields and warns on unsupported entries; API normalizes before persistence. E2E-50 checks invalid/oversized input, unknown entries, failed/retried import and export keys. | Implemented; full gates pending |
| Forward compatibility | Client/server normalizers add current cards/fields with factory defaults, filter retired/unknown entries, clamp order, normalize sizes and preserve compatible visibility/order. Definitions are normalized on API read/write. | Implemented; add-card/retirement/schema evolution is code-path behavior, no future-version fixture exists |
| Meaningful authorization, persistence and browser coverage | `V9DashboardLayoutApiTest.php`, `dashboardLayouts.test.mjs`, toolbar recovery tests and E2E-49/50 provide coverage. | Present; CI pending |
| Migration portability, OpenAPI/static documentation, journeys and register | New revision migration is forward-only; migration portability and generation/check gates are required. E2E-50 is documented in `18-dashboard-combo-charts.md`; register remains in progress until verification closes. | Pending verification |

## Changes in this delivery

The existing UX003 implementation was present on the fetched master baseline. The audit identified that client-side request ordering protected one browser instance but did not prevent a stale dashboard definition from overwriting a newer save on another device. Named records now carry a monotonic revision. Definition updates must present the revision they loaded; the API locks the current row, rejects mismatches with HTTP 409 and increments revision after accepted writes. Client conflict feedback preserves the local definition and explains how to load the latest server copy. The migration adds the revision column to existing installations.

## Verification record

- **PHPUnit focused API suite:** PASS — 4 tests / 45 assertions with PHPUnit's isolated SQLite test configuration. This is focused evidence only; it does not replace the MySQL backend CI gate.
- **Revision conflict browser journey:** PASS — 2/2 desktop/mobile cases; local changes remained available and the simulated newer server definition was not overwritten.
- **Named dashboard journeys:** PASS — E2E-48 recovery 2/2 desktop/mobile; E2E-50 lifecycle/import/autosave and conflict cases passed on both desktop and mobile.
- **Other UX003 browser journey:** E2E-49 desktop passed. Existing E2E-49 mobile failed its screenshot comparison with 162 differing pixels (0.01 ratio), localized to the small StoX header branding text; feature assertions reached the screenshot and passed. The failure repeated locally. Snapshot was not regenerated because the dashboard editor UI did not change in that area.
- **JavaScript:** PASS — 225 Node tests and 281 Vitest tests.
- **TypeScript:** PASS — `npm run typecheck`.
- **Production build:** PASS — `VITE_APP_BASE=/portfolio/build/ npm run build`; Vite reported the existing large-chunk advisory.
- **Static docs:** PASS — `npm run docs:static:check`; 53 topics current. Journey source, generated journey metadata and generated static E2E-50 page were updated. Unrelated generated timestamps were restored.
- **OpenAPI:** PASS — `php artisan openapi:v1 --check` (221 operations current).
- **Migration portability:** PASS — `php scripts/verify-migration-portability.php` (181 migrations).
- **Diff whitespace:** PASS — `git diff --check`.
- **Canonical CI parity:** not run locally. Backend mode would run `migrate:fresh` against a configured MySQL service; this task did not select or alter an unspecified/shared database. Frontend mode would install OS dependencies via Playwright `--with-deps`; this task avoided system package changes and used the already-installed pinned Chromium instead. Require exact-head hosted PR checks before merge.
- **Hosted PR checks/review, merge, production deployment and SHA health smoke:** pending.

Do not mark this audit or the register verified until exact-head hosted checks pass, review feedback is resolved, the PR is merged through normal policy, and the verified deployment plus non-destructive SHA health smoke are recorded.
