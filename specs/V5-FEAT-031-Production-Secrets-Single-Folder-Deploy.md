# V5 FEAT-031 — Production secrets and single-folder deploy hardening

| Field | Value |
|---|---|
| Status | **SUPERSEDED / CLOSED — legacy cPanel deployment architecture** |
| Implemented | 2026-09-04 |
| Superseded | 2026-09-13 production VPS deployment; reconciled 2026-09-15 |
| Original production target | GoDaddy/cPanel, `/portfolio` subdirectory |
| Current production target | VPS at `https://stoxla.in/` |
| Current deployment runbook | [`../deploy/STOXLA-VPS-DEPLOY.md`](../deploy/STOXLA-VPS-DEPLOY.md) |

## Closure decision

FEAT-031 is closed as **superseded**, not because its original cPanel cutover checklist was executed.

The feature successfully implemented the hardening needed for the former GoDaddy/cPanel topology: externalized secrets, single-build packaging, nested Laravel web denial, migration/setup helpers and rollback support. Before that cPanel cutover was production-verified, StoX moved to a dedicated Hostinger VPS and `stoxla.in`.

The old acceptance target is therefore no longer the production architecture and should not remain an open V5 closure gate.

The replacement VPS deployment architecture is now production-operational and provides the corresponding concerns through a different mechanism:

- production secrets remain outside release artifacts;
- releases are packaged by GitHub Actions;
- shared `.env` and Laravel storage live outside individual release directories;
- release activation uses versioned release directories and an atomic `current` symlink;
- migrations and Laravel caches are applied during deployment;
- queue workers are restarted after activation;
- rollback tooling is shipped with the deployment flow; and
- a post-deploy HTTPS health check is mandatory.

The successful production workflow on 2026-09-13 passed backend verification, MySQL migration/seed validation, OpenAPI verification, frontend tests, TypeScript validation, production build, release packaging, VPS activation and HTTPS health checking.

## Historical problem

The legacy production shape used sibling `public_html/lidoportfolio` and `public_html/portfolio` directories and required two copies of every Vite build. It also stored Laravel `.env` below `public_html`. The Laravel sibling was denied by Apache, but duplicated assets created atomicity risk and a future layout error could expose secrets.

## Historical frozen behaviour

The original feature specified:

- one web directory at `public_html/portfolio`, with Laravel nested at `portfolio/laravel`;
- one public build at `portfolio/build`;
- secrets outside the web tree;
- deterministic external environment discovery;
- parent and nested deny rules for Laravel/secrets;
- cPanel-compatible setup/migration helpers; and
- preservation of the former two-folder deployment as rollback during migration.

That behavior remains historical reference only. It does not describe the current VPS deployment topology.

## Current authority

For current production operations, deployment architecture and verification, use:

- `deploy/STOXLA-VPS-DEPLOY.md`
- `.github/workflows/deploy-stoxla-production.yml`
- `deploy/scripts/stoxla-deploy-release.sh`
- `deploy/scripts/stoxla-rollback-release.sh`

These supersede the cPanel single-folder cutover as the active production mechanism.

## Historical acceptance evidence

Before supersession, local verification had already shown that the cPanel package:

- produced exactly one public build;
- included parent and nested deny rules;
- excluded `.env`, `DBConfig.php`, `public/hot` and duplicate build output;
- supported external environment selection; and
- passed application/frontend build checks.

The remaining original cPanel production-cutover checks were intentionally never completed because that hosting target was abandoned.

## Final status

FEAT-031 must no longer be counted as an unfinished V5 implementation or production gate. It is a **completed historical implementation whose target architecture was superseded** by the live VPS deployment.
