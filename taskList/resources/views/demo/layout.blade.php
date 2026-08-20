<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', config('psp.name'))</title>
  <style>
    :root { --bg:#0d1117; --card:#161b25; --line:#232a36; --ink:#e8eaf0; --muted:#9aa4b2; --gold:#c9a227;
            --green:#2ea043; --red:#e5484d; --amber:#d29922; }
    * { box-sizing:border-box; }
    body { margin:0; min-height:100vh; background:var(--bg); color:var(--ink);
           font-family:-apple-system,'Helvetica Neue',Arial,sans-serif; }
    /* ── navbar ─────────────────────────────────────────────── */
    nav { position:sticky; top:0; z-index:10; display:flex; align-items:center; gap:26px;
          padding:0 24px; height:60px; background:rgba(13,17,23,.85); backdrop-filter:blur(8px);
          border-bottom:1px solid var(--line); }
    nav .brand { display:flex; align-items:center; gap:9px; font-weight:800; font-size:18px;
                 letter-spacing:-.01em; margin-right:8px; text-decoration:none; color:var(--ink); }
    nav .brand .dot { width:11px; height:11px; border-radius:50%; background:var(--gold);
                      box-shadow:0 0 0 4px rgba(201,162,39,.18); }
    nav a.tab { color:var(--muted); text-decoration:none; font-size:14.5px; font-weight:600;
                padding:8px 2px; border-bottom:2px solid transparent; }
    nav a.tab:hover { color:var(--ink); }
    nav a.tab.active { color:var(--ink); border-bottom-color:var(--gold); }
    nav .spacer { flex:1; }
    nav .env { font-size:11px; letter-spacing:.12em; text-transform:uppercase; color:#6b7280;
               border:1px solid var(--line); border-radius:999px; padding:4px 10px; }
    main { display:grid; place-items:start center; padding:32px 24px 64px; }
    .card { max-width:760px; width:100%; background:var(--card); border:1px solid var(--line);
            border-radius:16px; padding:38px; }
    h1 { font-size:25px; margin:0 0 8px; }
    .lead { color:var(--muted); font-size:15px; line-height:1.6; margin:0 0 20px; }
    .row { display:flex; gap:10px; }
    input[type=text], input:not([type]) { flex:1; background:#0d1117; border:1px solid var(--line);
            border-radius:10px; color:var(--ink); font-size:17px; padding:14px 16px; outline:none; }
    input:focus { border-color:var(--gold); }
    button { border:0; border-radius:10px; font-weight:700; font-size:15px; padding:12px 20px; cursor:pointer; }
    .primary { background:var(--gold); color:#12151c; }
    .ghost { background:#0d1117; color:var(--ink); border:1px solid var(--line); }
    button:disabled { opacity:.5; cursor:default; }
    .step { border-top:1px solid var(--line); margin-top:22px; padding-top:18px; }
    .step[hidden]{ display:none; }
    .lbl { font-size:12px; letter-spacing:.1em; text-transform:uppercase; color:#6b7280; margin:0 0 4px; }
    code { font-family:ui-monospace,Menlo,Consolas,monospace; word-break:break-all; }
    .hash { color:var(--gold); }
    details { margin-top:22px; border-top:1px solid var(--line); padding-top:14px; }
    summary { cursor:pointer; color:var(--muted); font-size:14px; }
    .paths { font-size:13.5px; color:var(--muted); line-height:1.7; margin-top:10px; }
    .paths b { color:var(--ink); }
    .paths code { color:var(--gold); font-size:13px; }
    .hint { margin-top:16px; font-size:13px; color:#6b7280; }
    @yield('style')
  </style>
</head>
<body>
  <nav>
    <a class="brand" href="/demo/hash"><span class="dot"></span>{{ config('psp.name') }}</a>
    <a class="tab @yield('tab_submit')" href="/demo/submit">Fraud submission</a>
    <a class="tab @yield('tab_demo')" href="/demo/hash">Live demo</a>
    <span class="spacer"></span>
    <span class="env">Sandbox</span>
  </nav>
  <main>
    @yield('main')
  </main>
  @yield('scripts')
</body>
</html>
