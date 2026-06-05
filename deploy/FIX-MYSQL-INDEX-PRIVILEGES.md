# MySQL INDEX denied (1142) on GoDaddy

> **Full guide:** [DEPLOY.md](DEPLOY.md) §7.


Error during migrate:

```text
INDEX command denied to user 'niti_nits'@'...' for table `lido_db`.`portfolio_stocks`
```

## Option A — Re-run migrate (updated migration)

Upload the latest:

`backend/database/migrations/2026_05_29_000001_extend_portfolio_stocks_master.php`

→ `public_html/lidoportfolio/database/migrations/`

Then open `cpanel-once-setup.php` again (or run migrate). The migration now **skips** index changes when INDEX/ALTER is denied and leaves the original `symbol` unique index.

The app works without the composite `(symbol, exchange)` index; you only need Option B if you use the same ticker on NSE and BSE.

## Option B — Grant privileges (cPanel)

1. **cPanel → MySQL® Databases**
2. Under **Add User To Database**, ensure `niti_nits` is linked to `lido_db` with **ALL PRIVILEGES** checked.
3. Save, then re-run setup migrate.

## Option C — Create index in phpMyAdmin (primary MySQL user)

Log in to **phpMyAdmin** as the cPanel account owner (not `niti_nits` if that user is restricted).

Run:

```sql
-- Only if portfolio_stocks_symbol_exchange_unique does not exist yet:
CREATE UNIQUE INDEX portfolio_stocks_symbol_exchange_unique
  ON portfolio_stocks (symbol(32), exchange(10));

-- If symbol-only index still exists and blocks duplicates you do not need:
-- ALTER TABLE portfolio_stocks DROP INDEX portfolio_stocks_symbol_unique;
```

Then in phpMyAdmin → `portfolio_migrations` (or `migrations` if Laravel default): ensure batch for `2026_05_29_000001_extend_portfolio_stocks_master` is recorded, or re-run migrate (it will no-op columns already added).
