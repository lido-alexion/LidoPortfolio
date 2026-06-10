# Fix 404 on /portfolio/ (cpanel-ping, mobile-debug, whole app)

Use this when you see **Error not found** / **404** on URLs under `https://lidoalexion.com/portfolio/`.

## A. Confirm files exist on the server (cPanel File Manager)

Open **`public_html/portfolio/`** (not `lidoportfolio`). You should see at least:

| File | Required |
|------|----------|
| `index.php` | Yes |
| `.htaccess` | Yes |
| `build/` folder | Yes |
| `cpanel-ping.php` | For ping test |
| `portfolio-OK.txt` | Plain-text test (no PHP) |

If **`portfolio/` is empty or missing** → uploads went to the wrong folder.

Common mistake: uploading into `public_html/lidoportfolio/` instead of `public_html/portfolio/`.

## B. Three URL tests (phone or PC)

Replace host with yours if different.

1. **Static file (no PHP, no Laravel)**  
   `https://lidoalexion.com/portfolio/portfolio-OK.txt`  
   - **Shows one line of text** → folder path is correct; continue to C.  
   - **404** → wrong folder, wrong domain path, or root `.htaccess` blocks `/portfolio/` → go to **D**.

2. **PHP ping**  
   `https://lidoalexion.com/portfolio/cpanel-ping.php`  
   - **OK — PHP works** → PHP + portfolio `.htaccess` are fine.  
   - **404** but step 1 worked → upload `cpanel-ping.php`; check portfolio `.htaccess` (section C).  
   - **403** → see [FIX-403-FORBIDDEN.md](FIX-403-FORBIDDEN.md).

3. **Main app**  
   `https://lidoalexion.com/portfolio/`  
   - Login page or app shell → good.  
   - **404** → fix `index.php` + `.htaccess` (section C).

## C. Fix `public_html/portfolio/.htaccess`

**Replace the entire file** with `deploy/public_html-portfolio-.htaccess` from your PC.

The file **must** end with (Laravel front controller):

```apache
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
```

Without those 3 lines, missing files return **404** instead of loading the app.

Also confirm `index.php` exists — copy from `deploy/index.php` if missing.

### Temporary test

Rename `.htaccess` → `.htaccess.bak`, then open:

`https://lidoalexion.com/portfolio/portfolio-OK.txt`

- If it works with `.htaccess` disabled but not with it enabled → portfolio `.htaccess` content is wrong; use the deploy file above.

## D. Fix root `public_html/.htaccess`

If **step 1 (portfolio-OK.txt) 404s**, another site rule is swallowing `/portfolio/` before Apache reaches the folder.

1. Open **`public_html/.htaccess`** in File Manager.
2. Find `RewriteEngine On`.
3. **Immediately after it**, paste the block from `deploy/public_html-root-portfolio-snippet.htaccess`.
4. The portfolio block must be **above** WordPress / other catch-all rules.

Save and retry `portfolio-OK.txt`.

## E. Upload checklist (from your PC)

| Local file | Server path |
|------------|-------------|
| `deploy/public_html-portfolio-.htaccess` | `public_html/portfolio/.htaccess` |
| `deploy/index.php` | `public_html/portfolio/index.php` |
| `deploy/portfolio-OK.txt` | `public_html/portfolio/portfolio-OK.txt` |
| `deploy/cpanel-ping.php` | `public_html/portfolio/cpanel-ping.php` |
| `deploy/public_html-root-portfolio-snippet.htaccess` | paste into `public_html/.htaccess` |

Or run `deploy/prepare-upload.ps1` — staging includes `portfolio/.htaccess`, `portfolio-OK.txt`, and `cpanel-ping.php`.

## F. www vs non-www

Try both:

- `https://lidoalexion.com/portfolio/portfolio-OK.txt`
- `https://www.lidoalexion.com/portfolio/portfolio-OK.txt`

Use whichever matches your live site (same as `APP_URL` in `.env`).
