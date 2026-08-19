<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ config('psp.name') }} — Edge Hashing Demo</title>
  <style>
    :root { --bg:#0d1117; --card:#161b25; --line:#232a36; --ink:#e8eaf0; --muted:#9aa4b2; --gold:#c9a227; --navy:#1c2138; }
    * { box-sizing:border-box; }
    body { margin:0; min-height:100vh; display:grid; place-items:center; background:var(--bg);
           color:var(--ink); font-family:-apple-system,'Helvetica Neue',Arial,sans-serif; padding:24px; }
    .card { max-width:640px; width:100%; background:var(--card); border:1px solid var(--line);
            border-radius:16px; padding:44px 40px; }
    .badge { display:inline-block; font-size:12px; letter-spacing:.14em; text-transform:uppercase;
             color:var(--gold); font-weight:700; margin-bottom:18px; }
    h1 { font-size:30px; line-height:1.2; margin:0 0 14px; }
    p { color:var(--muted); font-size:16px; line-height:1.6; margin:0 0 26px; }
    .btn { display:inline-block; background:var(--gold); color:#12151c; font-weight:700; font-size:16px;
           text-decoration:none; padding:14px 22px; border-radius:10px; }
    .btn:hover { filter:brightness(1.06); }
    .foot { margin:28px 0 0; font-size:13px; color:#6b7280; }
  </style>
</head>
<body>
  <div class="card">
    <div class="badge">{{ config('psp.name') }}</div>
    <h1>Cross-network fraud screening,<br>without exposing customer data.</h1>
    <p>Before a payout leaves, {{ config('psp.name') }} checks the recipient against the
       Masenu fraud-intelligence network. The raw number never leaves the browser — it is
       <strong style="color:var(--ink)">hashed at the edge</strong> first, and only the hash is sent.</p>
    <a class="btn" href="/demo/hash">Try edge hashing →</a>
    <p class="foot">Powered by Masenu · hash-at-edge · demo</p>
  </div>
</body>
</html>
