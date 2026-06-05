# GoDaddy: PHP stuck at 7.4

> **Full deploy guide:** [DEPLOY.md](DEPLOY.md)


Lido Portfolio (Laravel 13) **cannot run on PHP 7.4**. You need **PHP 8.3 or 8.4** account-wide.

cPanel **PHP Selector** → greyed out = normal on GoDaddy. Change version in **GoDaddy**, not cPanel.

## Step 1 — GoDaddy dashboard (primary fix)

1. [godaddy.com](https://www.godaddy.com) → **My Products**
2. **Web Hosting (cPanel)** → **Manage** (not cPanel login)
3. **Settings** tab
4. **Server Settings** → **Change** next to **PHP Version**
5. Select **PHP 8.3** or **PHP 8.4** → **Save Changes**
6. Wait a few minutes, then retry setup URL

Help article: https://www.godaddy.com/help/view-or-change-the-php-version-for-my-web-hosting-cpanel-16090

## Step 2 — cPanel “Per Domain Settings” tab

In the same **PHP Selector** screen, open **Per Domain Settings** (not Account Global).

If `lidoalexion.com` allows a newer version there, set it to 8.3/8.4 and save.

## Step 3 — .htaccess fallback (if dashboard has no 8.x)

Upload `deploy/portfolio-php84.htaccess.snippet` contents **at the top** of:

`public_html/portfolio/.htaccess`

(Only works if your server has `ea-php84` installed — try phpinfo after.)

## Step 4 — Verify

Create `public_html/portfolio/phpinfo.php`:

```php
<?php phpinfo();
```

Open https://lidoalexion.com/portfolio/phpinfo.php — must show **PHP 8.3+**. Delete file immediately.

## If only 7.4 is offered

- Contact GoDaddy support: ask to enable **PHP 8.3 or 8.4** on Web Hosting (cPanel)
- Or upgrade to **Web Hosting Plus** / newer plan
- Portfolio cannot be deployed on PHP 7.4 (framework requirement)

## After PHP 8.3+

Re-run: `https://lidoalexion.com/portfolio/cpanel-once-setup.php?token=YOUR_TOKEN`
