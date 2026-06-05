<!doctype html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#000000">
    @php
        $appBase = rtrim(parse_url(config('app.url'), PHP_URL_PATH) ?? '', '/');
    @endphp
    <meta name="app-base" content="{{ $appBase }}">
    <title>Lido Portfolio</title>
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
</head>
<body>
    <div id="app"></div>
</body>
</html>
