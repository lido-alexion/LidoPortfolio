# Fix: open_basedir shows G:/PleskVhosts/... (wrong paths)

> **Full guide:** [DEPLOY.md](DEPLOY.md) §2 and §7.


## What it means

PHP 8.4 is running, but `open_basedir` points at **old Windows Plesk paths**, not your Linux account:

```
/G:/PleskVhosts/lidoalexion.com/;C:/Windows/Temp/
```

So PHP cannot read `/home/p7xatiz6j0mk/public_html/...` at all.

## Fix (File Manager)

Search and edit/delete these files under `public_html/` (and `portfolio/`):

| File | Action |
|------|--------|
| `.user.ini` | Open — if you see `open_basedir` with `G:` or `PleskVhosts`, **delete the whole file** or remove that line |
| `php.ini` | Same |
| `.htaccess` | Remove any `php_value open_basedir` or `php_admin_value open_basedir` lines with Windows paths |

Check:

- `/home/p7xatiz6j0mk/public_html/.user.ini`
- `/home/p7xatiz6j0mk/public_html/portfolio/.user.ini`
- `/home/p7xatiz6j0mk/public_html/lidoportfolio/.user.ini`

Parent sites sometimes inherit bad values — fix at `public_html` level first.

## cPanel MultiPHP INI Editor

1. cPanel → **MultiPHP INI Editor**
2. Mode: **Basic** or **Editor**
3. Path: `public_html/portfolio` (or domain root)
4. Find **open_basedir** — clear it or set to default (empty = host default)
5. Save

## Verify

1. Upload `deploy/check-server-php.php` → `public_html/portfolio/check-server-php.php`
2. Open https://lidoalexion.com/portfolio/check-server-php.php
3. Expect something like:
   - `PHP version: 8.4.x`
   - `open_basedir:` empty OR paths under `/home/p7xatiz6j0mk/...`
4. **Delete** check-server-php.php

Then retry `cpanel-once-setup.php`.

## If still broken

Contact GoDaddy support: domain `lidoalexion.com` PHP `open_basedir` contains Windows Plesk paths; request reset for Linux cPanel account `p7xatiz6j0mk`.
