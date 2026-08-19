@extends('demo.layout')

@section('title', 'Live demo — edge hash + network check · AllPay')
@section('tab_demo', 'active')

@section('style')
    .verdict { display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
    .pill { font-weight:800; font-size:20px; padding:8px 16px; border-radius:999px; letter-spacing:.02em; }
    .pill.allow { background:rgba(46,160,67,.15); color:var(--green); }
    .pill.block { background:rgba(229,72,77,.15); color:var(--red); }
    .pill.review { background:rgba(210,153,34,.15); color:var(--amber); }
    .meta { color:var(--muted); font-size:14px; }
    .meta b { color:var(--ink); }
    .kv { margin:0 0 12px; }
    .kv code { display:block; font-size:14.5px; }
    ul.expl { margin:12px 0 0; padding-left:18px; color:var(--muted); font-size:14px; }
    ul.expl li { margin:4px 0; }
@endsection

@section('main')
  <div class="card">
    <h1>Edge hash → live network check</h1>
    <p class="lead">Type a number. Step 1 hashes it <strong style="color:var(--ink)">in your browser</strong>
       (nothing raw is sent). Step 2 runs the PSP's real fraud screen against the Masenu network and shows
       the decision and how long it took. Try <code>0559000911</code> (a flagged number → <b>block</b>) vs a
       clean number like <code>0201234567</code> (→ <b>allow</b>).</p>

    <div class="row">
      <input type="text" id="phone" placeholder="e.g. 0559000911" autocomplete="off" spellcheck="false">
      <button class="primary" id="hashBtn">Hash →</button>
    </div>

    <div class="step" id="hashOut" hidden>
      <div class="kv"><p class="lbl">normalized</p><code id="nm"></code></div>
      <div class="kv"><p class="lbl">one-way hash (h1) — all that leaves the box</p><code id="hx" class="hash"></code></div>
      <button class="ghost" id="checkBtn">Check the Masenu network →</button>
    </div>

    <div class="step" id="netOut" hidden>
      <div class="verdict">
        <span class="pill" id="pill"></span>
        <span class="meta">score <b id="score"></b> · band <b id="band"></b></span>
      </div>
      <p class="meta" id="timing" style="margin-top:12px"></p>
      <ul class="expl" id="expl"></ul>
    </div>

    <details>
      <summary>Behind the scenes — where this lives in the code</summary>
      <div class="paths">
        <p><b>In this PSP ({{ config('psp.name') }}):</b><br>
           • <code>app/Services/Fraud/MasenuClient.php</code> — <code>edgeHash()</code> (the hashing above) and
             <code>assess()</code> (this live lookup)<br>
           • <code>app/Services/Transactions/PayoutService.php</code> — <code>assertAllowed()</code> runs before every payout</p>
        <p><b>In Masenu (the network backend that checks the database):</b><br>
           • <code>POST /v1/lookups</code> → <code>app/lookups/routes.py</code><br>
           • <code>app/lookups/service.py</code> — applies the 2nd hash layer and assembles the decision<br>
           • <code>app/ai/scoring.py</code> — the DB checks: corroboration, cross-sector caps, explanations</p>
      </div>
    </details>

    <p class="hint">Open DevTools → <em>Sources</em> to see the pepper key and <code>normalize()</code>; open the
       <em>Network</em> tab to watch the <code>/demo/check</code> request go out and time itself.</p>
  </div>
@endsection

@section('scripts')
  <script>
    // ─── EDGE HASHING — in the browser. Same normalize + HMAC-SHA256 as the PSP
    //     backend (MasenuClient) and the SDKs. (Demo: pepper is server-secret in prod.)
    const CONSORTIUM_PEPPER = @json(config('psp.fraud.pepper') ?? 'dev-only-pepper-not-for-production');
    const CSRF = document.querySelector('meta[name=csrf-token]').content;
    let lastPhone = '';

    function normalize(phone) {
      let d = String(phone).replace(/\D/g, '');
      if (d.length === 10 && d.startsWith('0')) d = '233' + d.slice(1);   // Ghana local → E.164
      return d;
    }
    async function edgeHash(phone) {
      const enc = new TextEncoder();
      const key = await crypto.subtle.importKey('raw', enc.encode(CONSORTIUM_PEPPER),
        { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
      const sig = await crypto.subtle.sign('HMAC', key, enc.encode(normalize(phone)));
      return [...new Uint8Array(sig)].map(b => b.toString(16).padStart(2, '0')).join('');
    }

    async function doHash() {
      const phone = document.getElementById('phone').value.trim();
      if (!phone) return;
      lastPhone = phone;
      document.getElementById('nm').textContent = normalize(phone);
      document.getElementById('hx').textContent = await edgeHash(phone);
      document.getElementById('hashOut').hidden = false;
      document.getElementById('netOut').hidden = true;
    }

    async function doCheck() {
      const btn = document.getElementById('checkBtn');
      btn.disabled = true; btn.textContent = 'Checking…';
      const t0 = performance.now();
      let data;
      try {
        const res = await fetch('/demo/check', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
          body: JSON.stringify({ phone: lastPhone }),
        });
        data = await res.json();
      } finally {
        btn.disabled = false; btn.textContent = 'Check the Masenu network →';
      }
      const rtt = Math.round(performance.now() - t0);
      const map = { allow: 'allow', block: 'block', review: 'review', step_up: 'review' };
      const pill = document.getElementById('pill');
      pill.className = 'pill ' + (map[data.action] || 'review');
      pill.textContent = data.action.toUpperCase();
      document.getElementById('score').textContent = data.score;
      document.getElementById('band').textContent = data.band;
      document.getElementById('timing').innerHTML =
        'Masenu processed in <b>' + (data.masenu_latency_ms ?? '—') + ' ms</b> · ' +
        'browser round-trip <b>' + rtt + ' ms</b>' +
        (data.hits && data.hits.length ? ' · <b>' + data.hits.length + '</b> hit(s)' : '');
      const ul = document.getElementById('expl'); ul.innerHTML = '';
      (data.explanations || []).slice(0, 4).forEach(e => {
        const li = document.createElement('li'); li.textContent = e; ul.appendChild(li);
      });
      document.getElementById('netOut').hidden = false;
    }

    document.getElementById('hashBtn').addEventListener('click', doHash);
    document.getElementById('phone').addEventListener('keydown', e => { if (e.key === 'Enter') doHash(); });
    document.getElementById('checkBtn').addEventListener('click', doCheck);
  </script>
@endsection
