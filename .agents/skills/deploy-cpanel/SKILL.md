---
name: deploy-cpanel
description: >-
  Prepare Lido Portfolio cPanel/GoDaddy production deploy: run build, stage
  artifacts, and emit the upload table. Use when the user says deploy, lets
  deploy, prepare upload, or what to upload to production.
---

# Deploy to cPanel (Lido Portfolio)

## Hard rules

- **Never** ask the user to run `npm run build` or `prepare-upload.ps1` — the script runs them.
- **Never** instruct SSH or `php artisan` on production — use `deploy/cpanel-*.php` browser URLs.
- Replace `USER` in targets with the user's cPanel username (e.g. `p7xatiz6j0mk`).
- Exclude from upload: `app/tests/`, `implementation.md`, `node_modules/`, `.env`, `app/config/DBConfig.php`, `public/hot`.

## Workflow

```
Progress:
- [ ] 1. Run prepare-deploy.ps1
- [ ] 2. Present deploy-table.md to user
- [ ] 3. Mention main bundle name for smoke test
```

### 1. Run script (repo root)

```powershell
powershell -ExecutionPolicy Bypass -File .cursor/skills/deploy-cpanel/scripts/prepare-deploy.ps1
```

Options:

| Flag | Use when |
|------|----------|
| `-SinceCommit <ref>` | Upload table should cover a specific commit range (default: auto since upstream merge-base or last commit) |
| `-SkipBuild` | Build already ran; only regenerate the table |
| `-Debug` | Write `deploy/deploy-trace.log` for troubleshooting |

**PowerShell note:** keep this script ASCII-only in double-quoted strings (no em dash `—`; it breaks the parser on Windows PowerShell).

The script:

1. Runs `deploy/prepare-upload.ps1` (unless `-SkipBuild`)
2. Reads `app/public/build/manifest.json` for main JS/CSS bundle names
3. Maps `git diff` paths → cPanel upload rows
4. Writes **`deploy/deploy-table.md`** and **`deploy/deploy-manifest.json`**

### 2. Present to user

Read **`deploy/deploy-table.md`** and paste its table + **After upload** + **Smoke test** sections. Do not re-derive paths from scratch.

Cite the **Main JS bundle** line from the file (e.g. `app-D2A81yEZ.js`).

### 3. Frontend = two build folders

Any frontend change → **replace entire directory** on **both**:

- `/home/USER/public_html/lidoportfolio/public/build/`
- `/home/USER/public_html/portfolio/build/`

### Migrations

If the table includes `database/migrations/`, **After upload** must include:

`https://YOUR-DOMAIN/portfolio/cpanel-migrate.php?token=YOUR_TOKEN`

### New cpanel scripts

If you add `deploy/cpanel-*.php`, add a `Copy-Item` line in `deploy/prepare-upload.ps1`.

## Path mapping (reference)

| Local prefix | Server target |
|---|---|
| `app/app/...` | `/home/USER/public_html/lidoportfolio/app/...` |
| `app/routes/...` | `/home/USER/public_html/lidoportfolio/routes/...` |
| `app/config/...` | `/home/USER/public_html/lidoportfolio/config/...` |
| `app/database/migrations/...` | `/home/USER/public_html/lidoportfolio/database/migrations/...` |
| `app/public/build/` | both build folders (see above) |
| `deploy/cpanel-*.php` | `/home/USER/public_html/portfolio/cpanel-*.php` |

Bundled-only (no separate PHP upload): `app/resources/js/`, `app/resources/css/`, `app/resources/views/`.

## More detail

See [deploy/DEPLOY.md](../../../deploy/DEPLOY.md) for server layout and troubleshooting.
