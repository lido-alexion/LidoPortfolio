# Deploy assets

| File | Purpose |
|------|---------|
| **[DEPLOY.md](DEPLOY.md)** | **Start here** — full deploy & update guide |
| `cpanel-diagnose.php` | Upload to `public_html/portfolio/` — pre-flight checks |
| `cpanel-once-setup.php` | One-time migrate / key / config:cache (delete after) |
| `public_html-portfolio-index.php` | `/portfolio` front controller |
| `public_html-portfolio-.htaccess` | Rewrites for `/portfolio/` |
| `public_html-lidoportfolio-.htaccess` | Block web access to Laravel tree |
| `public_html-root-portfolio-snippet.htaccess` | Paste into root `public_html/.htaccess` |
| `FIX-*.md` | Troubleshooting deep-dives (linked from DEPLOY.md) |
