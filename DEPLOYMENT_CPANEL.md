# cPanel / shared hosting deployment

> **Production (lidoalexion.com/portfolio):** use the verified guide **[deploy/DEPLOY.md](deploy/DEPLOY.md)** — GoDaddy layout, `DBConfig.php`, Vite assets, browser setup scripts, and update workflow.

This file keeps **generic** notes for other hosts where Laravel’s `public/` folder is the document root.

---

## Generic layout (subdomain or dedicated vhost)

When **open_basedir** is not restricted and you control the document root:

1. Upload repo `app/` to the server.
2. Set document root to `app/public` (not the repo root).
3. Copy [app/.env.production.example](app/.env.production.example) → `.env` and set `DB_*` or `DB_CONFIG_PATH`.
4. `composer install --no-dev`, `php artisan migrate --force`, `php artisan config:cache`.
5. Build: `npm run build` (no `VITE_APP_BASE` unless using a subdirectory).
6. Cron: `* * * * * php /path/to/artisan schedule:run`.

**Do not** use this layout on GoDaddy main-domain `/portfolio` — see **[deploy/DEPLOY.md](deploy/DEPLOY.md)**.

---

## Related docs

| Document | Use |
|----------|-----|
| [deploy/DEPLOY.md](deploy/DEPLOY.md) | **lidoalexion.com/portfolio** (canonical) |
| [DEPLOYMENT_VALIDATION_PLAN.md](DEPLOYMENT_VALIDATION_PLAN.md) | Pre/post checklists |
| [implementation.md](implementation.md) | Architecture, auth, logging |
| [app/API_DOCUMENTATION.md](app/API_DOCUMENTATION.md) | REST API |

---

## HTTPS, cron, features (all hosts)

- **HTTPS** required for `SESSION_SECURE_COOKIE=true` and Sanctum login.
- **Cron** every minute: `schedule:run` (runs `portfolio:daily-sync`, `stocks:sync`, notifications per settings).
- **Queue worker** usually not required (`dispatchSync` for daily sync).
- **Telegram / Alpha Vantage / log level:** Settings UI after deploy.
- **Troubleshooting:** [deploy/DEPLOY.md §7](deploy/DEPLOY.md#7-troubleshooting).

---

## Security checklist

- `APP_DEBUG=false` on production
- `.env` outside web root or protected
- Delete temporary `cpanel-*.php` after setup
- HTTPS forced
- No dev seeded passwords on production
