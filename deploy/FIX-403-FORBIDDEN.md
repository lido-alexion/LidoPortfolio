# Fix 403 Forbidden on /portfolio/

> **Full guide:** [DEPLOY.md](DEPLOY.md) §7.


## Most common cause

`Deny from all` was uploaded to **`public_html/portfolio/.htaccess`** by mistake.

That file belongs only in **`public_html/lidoportfolio/.htaccess`** (blocks direct web access to Laravel code).

The **portfolio** web folder must NOT use "Deny from all".

## Fix steps (File Manager)

### 1. Check `public_html/portfolio/.htaccess`

Open it. If you see:

```apache
Deny from all
```

or

```apache
Require all denied
```

**Delete that file** or replace entire contents with `deploy/public_html-portfolio-.htaccess` from your PC (Laravel rewrites only — no Deny).

### 2. Check `public_html/lidoportfolio/.htaccess`

This one **should** contain Deny from all (from `deploy/public_html-lidoportfolio-.htaccess`).

It must **not** be copied to `portfolio/`.

### 3. Permissions

| Path | Folders | Files |
|------|---------|-------|
| `public_html/portfolio/` | 755 | 644 |
| `public_html/portfolio/index.php` | — | 644 |
| `public_html/lidoportfolio/storage/` | 775 | 664 |

### 4. Minimal test

Upload `deploy/test-ok.php` → `public_html/portfolio/test-ok.php`

Open: https://lidoalexion.com/portfolio/test-ok.php

- **OK - PHP works** → .htaccess was the problem; fix portfolio/.htaccess
- **Still 403** → permissions or cPanel security (see below)

Delete `test-ok.php` after test.

### 5. Temporarily disable portfolio .htaccess

Rename `public_html/portfolio/.htaccess` → `.htaccess.bak`

Try `test-ok.php` again. If it works, the .htaccess content was wrong.

### 6. cPanel security (if still 403)

- **ModSecurity** → disable for domain temporarily, or check error log
- **IP Blocker** — your IP not blocked
- **Hotlink Protection** — usually unrelated

### 7. Root .htaccess

The PORTFOLIO block should use `[L]` only, not `[F]` (forbidden).

Correct:

```apache
RewriteRule ^ - [L]
```

Wrong:

```apache
RewriteRule ^ - [F]
```
