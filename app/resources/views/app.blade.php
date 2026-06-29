<!doctype html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content">
    <meta name="theme-color" content="#000000">
    @php
        $appBase = rtrim(parse_url(config('app.url'), PHP_URL_PATH) ?? '', '/');
    @endphp
    <meta name="app-base" content="{{ $appBase }}">
    <script>window.__LIDO_APP_BASE__ = @json($appBase);</script>
    <title>Lido Portfolio</title>
    <link rel="icon" href="{{ ($appBase !== '' ? $appBase : '') }}/favicon.ico" type="image/x-icon" sizes="any">
    <script>
        (function () {
            var key = 'lido-theme';
            var stored = localStorage.getItem(key);
            var resolved = 'dark';
            if (stored === 'light') resolved = 'light';
            else if (stored === 'system') {
                resolved = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-theme', resolved);
        })();
    </script>
    @if (file_exists(public_path('hot')))
        @viteReactRefresh
    @endif
    @vite(['resources/css/app.css', 'resources/js/src/styles/lido-app.css', 'resources/js/app.jsx'])
    <script>
        (function () {
            var panel = null;
            var lines = [];
            var shown = false;

            function persistFailure() {
                try {
                    sessionStorage.setItem('lido_boot_error', lines.join('\n'));
                } catch (e) {}
            }

            function renderPanel(title) {
                if (!panel) {
                    panel = document.getElementById('lido-boot-panel');
                }
                if (!panel) return;
                panel.hidden = false;
                shown = true;
                panel.innerHTML = ''
                    + '<div style="max-width:640px;margin:0 auto;font-family:system-ui,sans-serif;line-height:1.45">'
                    + '<h1 style="font-size:1.1rem;margin:0 0 .5rem">' + title + '</h1>'
                    + '<p style="font-size:.85rem;opacity:.9;margin:0 0 .75rem">'
                    + 'This message stays until you dismiss it or the app loads successfully. '
                    + '<a href="mobile-debug.html" style="color:#7ec8ff">mobile-debug.html</a></p>'
                    + '<pre id="lido-boot-log" style="white-space:pre-wrap;word-break:break-word;font-size:.75rem;'
                    + 'background:rgba(0,0,0,.35);padding:.75rem;border-radius:.35rem;margin:0;max-height:50vh;overflow:auto">'
                    + lines.join('\n').replace(/</g, '&lt;')
                    + '</pre>'
                    + '<div style="margin-top:.75rem;display:flex;gap:.5rem;flex-wrap:wrap">'
                    + '<button type="button" id="lido-boot-reload" style="padding:.5rem .85rem">Reload</button>'
                    + '<button type="button" id="lido-boot-dismiss" style="padding:.5rem .85rem">Dismiss</button>'
                    + '</div></div>';
                var reloadBtn = document.getElementById('lido-boot-reload');
                var dismissBtn = document.getElementById('lido-boot-dismiss');
                if (reloadBtn) reloadBtn.onclick = function () { location.reload(); };
                if (dismissBtn) dismissBtn.onclick = function () { panel.hidden = true; };
            }

            function bootLog(msg) {
                lines.push(new Date().toISOString() + ' ' + msg);
                if (shown && panel) {
                    var pre = document.getElementById('lido-boot-log');
                    if (pre) pre.textContent = lines.join('\n');
                }
            }

            function bootFail(title, msg) {
                bootLog(msg);
                persistFailure();
                renderPanel(title);
            }

            window.__lidoBootLog = bootLog;
            window.__lidoBootFail = bootFail;
            window.__lidoBootSuccess = function () {
                window.__LIDO_APP_BOOTED = true;
                try {
                    sessionStorage.removeItem('lido_boot_error');
                } catch (e) {}
                if (panel) panel.hidden = true;
            };

            function isOurAsset(url) {
                if (!url) return false;
                return url.indexOf('/build/assets/') >= 0
                    || url.indexOf('/build/') >= 0
                    || url.indexOf(location.origin) === 0;
            }

            window.addEventListener('error', function (event) {
                if (window.__LIDO_APP_BOOTED) return;
                var msg = event.message || 'unknown';
                if (msg === 'Script error.' && !event.filename) return;
                if (event.filename && !isOurAsset(event.filename)) return;
                bootFail('Load error', 'Error: ' + msg
                    + (event.filename ? ' @ ' + event.filename + ':' + (event.lineno || '') : ''));
            });

            window.addEventListener('unhandledrejection', function (event) {
                if (window.__LIDO_APP_BOOTED) return;
                var reason = event.reason;
                bootFail('Load error', 'Promise rejection: '
                    + (reason && reason.message ? reason.message : String(reason)));
            });

            document.addEventListener('DOMContentLoaded', function () {
                if (location.pathname.indexOf('mobile-debug') >= 0) {
                    bootFail(
                        'Debug page not served',
                        'This URL should show the standalone diagnostic page, not the main app.\n'
                        + 'Upload public_html/portfolio/mobile-debug.html and update portfolio/.htaccess,\n'
                        + 'or open cpanel-mobile-debug.php?token=… instead.'
                    );
                    return;
                }

                document.querySelectorAll('script[type="module"]').forEach(function (script) {
                    var src = script.src || '(inline module)';
                    if (script.src) {
                        try {
                            var scriptHost = new URL(script.src).host;
                            if (scriptHost && scriptHost !== location.host) {
                                bootFail(
                                    'Script host mismatch',
                                    'Page is on ' + location.host + ' but JS loads from ' + scriptHost
                                    + '. ES modules fail cross-origin. Re-upload AppServiceProvider.php and run config:cache.'
                                );
                                return;
                            }
                        } catch (e) {}
                    }
                    script.addEventListener('error', function () {
                        bootFail('Script failed to load', 'Failed to load: ' + src);
                    });
                });

                document.querySelectorAll('link[rel="stylesheet"]').forEach(function (link) {
                    if (!link.href || link.href.indexOf('/build/') < 0) return;
                    link.addEventListener('error', function () {
                        bootFail('CSS failed to load', 'Failed to load: ' + link.href);
                    });
                });
            });

            window.setTimeout(function () {
                if (window.__LIDO_APP_BOOTED) return;
                var root = document.getElementById('app');
                if (!root || root.childElementCount > 0) return;
                bootFail('App did not start', 'App did not render within 25s (slow network or JS error).');
                bootLog('URL: ' + location.href);
                bootLog('UA: ' + navigator.userAgent);
                persistFailure();
            }, 25000);

            // If React mounted but the boot overlay was shown earlier, hide it on input (keyboard / repaint).
            document.addEventListener('focusin', function () {
                if (!window.__LIDO_APP_BOOTED) return;
                var panel = document.getElementById('lido-boot-panel');
                if (panel && !panel.hidden) {
                    panel.hidden = true;
                }
            }, true);
        })();
    </script>
</head>
<body>
    <div id="app"></div>
    <div id="lido-boot-panel" hidden style="position:fixed;inset:0;z-index:2147483000;overflow:auto;padding:1rem;background:#1a1a1a;color:#e5e7eb"></div>
    <noscript>
        <div style="padding:1rem;font-family:system-ui,sans-serif;background:#1a1a1a;color:#e5e7eb;min-height:100vh">
            JavaScript is required for Lido Portfolio.
        </div>
    </noscript>
</body>
</html>
