<?php
/**
 * Mobile blank-page diagnostics — bypasses Laravel.
 *
 * Upload to: public_html/portfolio/cpanel-mobile-debug.php
 * Visit:     https://lidoalexion.com/portfolio/cpanel-mobile-debug.php?token=YOUR_TOKEN
 * DELETE after fixing the blank page.
 *
 * Optional: also upload mobile-debug.html beside this file for the static URL.
 */
declare(strict_types=1);

const SETUP_TOKEN = 'Lido';

if (($_GET['token'] ?? '') !== SETUP_TOKEN) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden. Add ?token=YOUR_TOKEN (same as cpanel-migrate.php).\n");
}

$htmlPath = __DIR__.'/mobile-debug.html';
if (is_file($htmlPath)) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    readfile($htmlPath);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lido Portfolio — quick debug</title>
    <style>
        body { margin: 0; font-family: system-ui, sans-serif; background: #111; color: #eee; padding: 1rem; line-height: 1.45; }
        h1 { font-size: 1.1rem; }
        pre { background: #1a1a1a; padding: .75rem; border-radius: .35rem; overflow: auto; font-size: .8rem; }
        button { padding: .55rem .85rem; margin: .25rem .5rem .25rem 0; }
        .ok { color: #4ade80; } .bad { color: #f87171; }
    </style>
</head>
<body>
    <h1>Lido Portfolio — quick debug</h1>
    <p>PHP script is working. Full tests run below. Upload <code>mobile-debug.html</code> beside this file for the static URL.</p>
    <button type="button" id="run">Run tests</button>
    <button type="button" id="copy">Copy report</button>
    <pre id="out">(tap Run tests)</pre>
    <script>
    (function () {
        var out = document.getElementById('out');
        var lines = [];
        function log(s) { lines.push(s); out.textContent = lines.join('\n'); }
        function base() {
            var p = location.pathname || '';
            return p.indexOf('/portfolio') === 0 ? '/portfolio' : '';
        }
        async function probe(label, url, opts) {
            opts = opts || {};
            try {
                var r = await fetch(url, {
                    cache: 'no-store',
                    credentials: 'same-origin',
                    headers: opts.headers || { Accept: 'application/json' },
                });
                log(label + ': HTTP ' + r.status + ' ' + r.statusText);
                if (opts.logBody && r.status >= 400) {
                    var body = await r.text();
                    log(label + ' body: ' + body.slice(0, 300));
                }
                return r;
            } catch (e) {
                log(label + ': FAILED ' + (e.message || e));
                return null;
            }
        }
        async function run() {
            lines = [];
            var b = base();
            var build = b + '/build/';
            log('URL: ' + location.href);
            log('UA: ' + navigator.userAgent);
            log('App base: ' + (b || '(root)'));

            var manifestRes = await probe('manifest.json', build + 'manifest.json');
            var mainJs = '';
            var mainCss = [];
            if (manifestRes && manifestRes.ok) {
                try {
                    var manifest = await manifestRes.json();
                    var entry = manifest['resources/js/app.jsx'];
                    if (entry && entry.file) {
                        mainJs = build + entry.file;
                        if (entry.css) mainCss = entry.css.map(function (f) { return build + f; });
                    }
                } catch (e) {
                    log('manifest parse: ' + e.message);
                }
            }

            if (mainJs) {
                var jsRes = await probe('main JS bundle', mainJs);
                if (jsRes && jsRes.ok) {
                    var jsText = await jsRes.text();
                    log('main JS bytes: ' + jsText.length);
                }
            } else {
                log('main JS bundle: not found in manifest');
            }

            for (var i = 0; i < Math.min(mainCss.length, 2); i++) {
                var cssRes = await probe('CSS ' + (i + 1), mainCss[i]);
                if (cssRes && cssRes.ok) {
                    log('CSS ' + (i + 1) + ' bytes: ' + (await cssRes.clone().text()).length);
                }
            }

            var mr = await probe('main app HTML', b + '/', { headers: { Accept: 'text/html' } });
            if (mr && mr.ok) {
                var html = await mr.text();
                log('HTML bytes: ' + html.length);
                log('Vite dev URLs in HTML: ' + (html.indexOf(':5173') >= 0 ? 'YES (bad)' : 'no'));
                log('Has #app: ' + (html.indexOf('id="app"') >= 0 ? 'yes' : 'no'));
                var scriptMatch = html.match(/src="([^"]+\/build\/assets\/[^"]+\.js)"/);
                if (scriptMatch) {
                    log('HTML script src: ' + scriptMatch[1]);
                    try {
                        var scriptHost = new URL(scriptMatch[1], location.origin).host;
                        if (scriptHost && scriptHost !== location.host) {
                            log('*** HOST MISMATCH: page=' + location.host + ' script=' + scriptHost
                                + ' (breaks ES modules on mobile) ***');
                        } else {
                            log('Script host matches page: OK');
                        }
                    } catch (e) {}
                }
            }

            var meRes = await probe('API /api/auth/me', b + '/api/auth/me', { logBody: true });
            if (meRes && meRes.ok) {
                try {
                    var meJson = await meRes.json();
                    log('/api/auth/me user: ' + (meJson.user ? meJson.user.email : 'null (guest — OK)'));
                } catch (e) {}
            } else if (meRes && meRes.status === 500) {
                log('*** /api/auth/me 500 — upload routes/api.php fix + run cpanel-api-probe.php ***');
            }

            try {
                var stored = sessionStorage.getItem('lido_boot_error');
                if (stored) log('Stored boot error (may be old):\n' + stored);
            } catch (e) {}
            log('Done.');
        }
        document.getElementById('run').onclick = run;
        document.getElementById('copy').onclick = function () {
            var t = lines.join('\n') + '\nUA: ' + navigator.userAgent;
            if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(t);
            else prompt('Copy:', t);
        };
        run();
    })();
    </script>
</body>
</html>
