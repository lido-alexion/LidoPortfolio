<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#101820">
    <title>Connect Kite · StoX</title>
    <style>
        :root{color-scheme:dark;font:16px/1.5 system-ui,sans-serif;background:#101820;color:#f3f6f8}*{box-sizing:border-box}body{margin:0;padding:24px;min-height:100vh;display:grid;place-items:center}.card{width:min(100%,520px);background:#192630;border:1px solid #31434e;border-radius:16px;padding:24px;box-shadow:0 14px 40px #0004}h1{font-size:1.45rem;margin:0 0 4px}.muted{color:#afbdc5;margin:0 0 22px}.state{padding:14px;border-radius:10px;background:#24343e;margin:10px 0}.state strong{display:block}.status{font-size:.9rem;color:#d2dde2}.actions{display:flex;gap:12px;align-items:center;margin-top:22px;flex-wrap:wrap}button,a.button{border:0;border-radius:8px;padding:11px 16px;background:#59c6a5;color:#08251d;font-weight:700;text-decoration:none;cursor:pointer}button:disabled{opacity:.55;cursor:wait}.notice{color:#f2cc7c;font-size:.9rem;min-height:1.4em}small{color:#afbdc5}
    </style>
</head>
<body>
<main class="card" aria-labelledby="title">
    <h1 id="title">Daily Kite connection</h1>
    <p class="muted">Sign in to StoX, then connect the configured collector account. Enter your OTP only on Zerodha’s official page.</p>
    <div id="auth" class="notice" role="status">Checking StoX session…</div>
    <section class="state"><strong>Today</strong><span id="overall" class="status">Checking…</span></section>
    <section class="state"><strong>Kite session</strong><span id="session" class="status">Checking…</span></section>
    <section class="state"><strong>Collector WebSocket</strong><span id="socket" class="status">Checking…</span></section>
    <section class="state"><strong>Recent live packets</strong><span id="packets" class="status">Checking…</span></section>
    <div class="actions"><button id="connect" type="button" disabled>Connect Kite</button><a class="button" href="{{ rtrim(config('app.url'), '/') }}/dashboard">Dashboard</a></div>
    <p class="notice" id="notice" aria-live="polite"></p>
    <small>A stored session alone does not confirm live collection. WebSocket and recent packet evidence are shown separately.</small>
</main>
<script>
(() => {
    const api = (path) => fetch(`{{ rtrim(parse_url(config('app.url'), PHP_URL_PATH) ?? '', '/') }}/api${path}`, {credentials:'same-origin',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
    const auth = document.getElementById('auth'); const button = document.getElementById('connect');
    api('/auth/me').then(async r => { if (!r.ok) throw new Error('Sign in to StoX to view collector status.'); const d=await r.json(); if (!d.user) throw new Error('Sign in to StoX to view collector status.'); auth.textContent=`Signed in as ${d.user.name || 'StoX user'}`; button.disabled=false; return api('/microstructure-kite/status'); })
      .then(async r => { if (!r.ok) { if(r.status===403) throw new Error('This StoX account is not configured as the Kite collector.'); throw new Error('Could not load collector status.'); } const d=(await r.json()).data; document.getElementById('overall').textContent=({login_needed:'Login needed',connecting:'Connecting',receiving_live_ticks:'Receiving live ticks',attention:'Attention'})[d.display_state] || 'Attention'; document.getElementById('session').textContent=!d.kite.configured?'Kite is not configured':(d.kite.usable?'Usable':'Login needed'); document.getElementById('socket').textContent=d.collector.websocket_connected?'Connected':'Not connected'; document.getElementById('packets').textContent=d.collector.packet_recent?`Recent packet received${d.collector.last_packet_at?` · ${new Date(d.collector.last_packet_at).toLocaleTimeString()}`:''}`:'No recent packet'; button.disabled=!d.kite.configured; if(d.kite.usable) button.textContent='Reconnect Kite'; })
      .catch(e => { auth.textContent=e.message; });
    button.addEventListener('click', async () => { button.disabled=true; document.getElementById('notice').textContent='Opening Zerodha…'; try { const r=await api('/microstructure-kite/login-url'); if(!r.ok) throw new Error('Could not start Kite connection.'); const d=(await r.json()).data; location.assign(d.url); } catch(e) { document.getElementById('notice').textContent=e.message; button.disabled=false; } });
})();
</script>
</body>
</html>
