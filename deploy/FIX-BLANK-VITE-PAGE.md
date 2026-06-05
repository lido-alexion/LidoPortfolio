# Blank page — requests to 127.0.0.1:5173

> **Full guide:** [DEPLOY.md](DEPLOY.md) §7 and §3B (Vite build upload).


The browser tries to load the **Vite dev server** on your PC. Production must use **built files**, not `npm run dev`.

## Fix on the server (File Manager)

### 1. Delete the dev marker file (most common)

Delete if it exists:

```text
public_html/lidoportfolio/public/hot
```

That file contains `http://127.0.0.1:5173` and forces Laravel into dev mode.

### 2. Upload production build (two copies)

On your **PC**, in `backend/`:

```powershell
cd backend
$env:VITE_APP_BASE='/portfolio/build/'
npm run build
```

Upload the folder `backend/public/build/` (entire folder) to **both**:

| Server path | Why |
|-------------|-----|
| `public_html/lidoportfolio/public/build/` | Laravel reads `manifest.json` here |
| `public_html/portfolio/build/` | Browser loads `/portfolio/build/assets/...` |

After upload, these must exist:

- `lidoportfolio/public/build/manifest.json`
- `portfolio/build/assets/*.js` and `*.css`

### 3. Reload

Hard-refresh `https://lidoalexion.com/portfolio/` (Ctrl+F5).

DevTools → Network should show **`/portfolio/build/assets/...`** returning **200**, not `127.0.0.1:5173`.

## Optional: re-upload diagnose

Upload `deploy/cpanel-diagnose.php` and open it — section 2 should show `public/hot: no` and both manifests `yes`.
