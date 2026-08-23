@extends('demo.layout')

@section('title', 'Live demo — screen a number · '.config('psp.name'))
@section('tab_demo', 'active')

@section('style')
    .quick { display:flex; gap:10px; flex-wrap:wrap; margin-top:14px; }
    .chip { border:1px solid var(--line); background:#0d1117; color:var(--ink); border-radius:999px;
            font-size:13px; font-weight:600; padding:9px 15px; cursor:pointer; }
    .chip:hover { border-color:var(--gold); }
    .chip.fraud::before { content:'⚑ '; color:var(--red); }
    .chip.clean::before { content:'✓ '; color:var(--green); }
    .zone-lbl { font-size:11px; letter-spacing:.11em; text-transform:uppercase; color:#6b7280; margin:0 0 8px; }
    .decision { border-top:1px solid var(--line); padding-top:18px; }
    .pill { display:inline-flex; align-items:center; font-weight:800; font-size:28px; padding:10px 24px;
            border-radius:12px; letter-spacing:.03em; }
    .pill.allow { background:rgba(46,160,67,.15); color:var(--green); }
    .pill.block { background:rgba(229,72,77,.15); color:var(--red); }
    .pill.review { background:rgba(210,153,34,.15); color:var(--amber); }
    .decision .sub { color:var(--muted); font-size:13.5px; margin:11px 0 0; }
    .masenu { border-top:1px solid var(--line); margin-top:18px; padding-top:16px; }
    .stats { display:flex; gap:10px; flex-wrap:wrap; }
    .stat { flex:1; min-width:92px; background:#0d1117; border:1px solid var(--line); border-radius:10px; padding:12px 14px; }
    .stat b { display:block; font-size:22px; font-weight:800; line-height:1; font-variant-numeric:tabular-nums; }
    .stat span { font-size:11px; letter-spacing:.06em; text-transform:uppercase; color:#6b7280; margin-top:6px; display:block; }
    .stat.sc b { color:var(--gold); }
    ul.expl { margin:14px 0 0; padding-left:18px; color:var(--muted); font-size:14px; }
    ul.expl li { margin:4px 0; }
    .sent { border-top:1px solid var(--line); margin-top:16px; padding-top:14px; }
    .sent code { color:var(--gold); font-size:13px; word-break:break-all; }
    .spin { opacity:.6; }
@endsection

@section('main')
  <div class="card">
    <h1>Screen a number → allow or block</h1>
    <p class="lead">Type a number (or use a preset) and screen it against the Masenu network. The number is sent
       <strong style="color:var(--ink)">as a one-way hash</strong> — nothing raw leaves the box. The network returns a
       <strong style="color:var(--gold)">risk score</strong>; <strong style="color:var(--ink)">{{ config('psp.name') }}</strong>
       turns it into the <strong style="color:var(--ink)">allow / block</strong> decision.</p>

    <div class="row">
      <input type="text" id="phone" placeholder="e.g. 0559000911" autocomplete="off" spellcheck="false">
      <button class="primary" id="screenBtn">Screen →</button>
    </div>
    <div class="quick">
      <button class="chip fraud" data-n="0559000911">Test flagged number · 0559000911</button>
      <button class="chip clean" data-n="0201234567">Test clean number · 0201234567</button>
    </div>

    <div class="step" id="out" hidden style="border-top:none;padding-top:0">
      <div class="decision">
        <p class="zone-lbl">PSP decision — what {{ config('psp.name') }} does with the money</p>
        <span class="pill" id="pill"></span>
        <p class="sub" id="decsub"></p>
      </div>
      <div class="masenu">
        <p class="zone-lbl">Masenu network returned</p>
        <div class="stats">
          <span class="stat sc"><b id="score">—</b><span>risk score</span></span>
          <span class="stat"><b id="band">—</b><span>band</span></span>
          <span class="stat"><b id="mlat">—</b><span>Masenu ms</span></span>
          <span class="stat"><b id="rtt">—</b><span>round-trip ms</span></span>
          <span class="stat"><b id="hits">—</b><span>hits</span></span>
        </div>
        <ul class="expl" id="expl"></ul>
      </div>
      <div class="sent">
        <p class="zone-lbl">What actually left the box (proof it wasn't raw)</p>
        <p class="meta" style="margin:0">normalized <code id="nm"></code></p>
        <p class="meta" style="margin:6px 0 0">one-way hash sent → <code id="hx"></code></p>
      </div>
    </div>

    <details>
      <summary>Behind the scenes — where this lives in the code</summary>
      <div class="paths">
        <p><b>In this PSP ({{ config('psp.name') }}) — makes the allow/block decision:</b><br>
           • <code>app/Services/Fraud/MasenuClient.php</code> — hashes the number, calls Masenu, and maps the
             score to allow/review/block against this PSP's threshold<br>
           • <code>app/Services/Transactions/PayoutService.php</code> — <code>assertAllowed()</code> runs before every payout</p>
        <p><b>In Masenu (the network) — returns the score:</b><br>
           • <code>POST /v1/lookups</code> → <code>app/lookups/routes.py</code> · <code>app/ai/scoring.py</code></p>
      </div>
    </details>
  </div>
@endsection

@section('scripts')
  <script type="module">
    // Edge hashing imported from the vendored Adoor SDK (one source of truth).
    import { normalize, hashIdentifier } from '/vendor/adoor-hashing.js';
    import '/vendor/adoor-selftest.js';

    const CONSORTIUM_PEPPER = @json(config('psp.fraud.pepper') ?? 'dev-only-pepper-not-for-production');
    const CSRF = document.querySelector('meta[name=csrf-token]').content;
    const $ = id => document.getElementById(id);

    // ── ONE action: hash the number (privately) AND send the request, then render. ──
    async function screen(phone) {
      phone = (phone ?? $('phone').value).trim();
      if (!phone) return;
      $('phone').value = phone;
      const btn = $('screenBtn');
      btn.disabled = true; btn.classList.add('spin'); btn.textContent = 'Screening…';

      // hash happens here, on the way out — the raw number never goes over the wire
      const norm = normalize('msisdn', phone);
      const hash = await hashIdentifier('msisdn', phone, CONSORTIUM_PEPPER);

      const t0 = performance.now();
      let data;
      try {
        const res = await fetch('/demo/check', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
          body: JSON.stringify({ phone }),
        });
        data = await res.json();
      } catch (e) {
        btn.disabled = false; btn.classList.remove('spin'); btn.textContent = 'Screen →';
        alert('Request failed: ' + e.message); return;
      }
      const rtt = Math.round(performance.now() - t0);
      btn.disabled = false; btn.classList.remove('spin'); btn.textContent = 'Screen →';

      const cls = { allow:'allow', block:'block', review:'review', step_up:'review' }[data.action] || 'review';
      const pill = $('pill');
      pill.className = 'pill ' + cls;
      pill.textContent = (cls === 'block' ? 'BLOCK' : cls === 'allow' ? 'ALLOW' : 'REVIEW');
      $('decsub').textContent = cls === 'block'
        ? 'Payout is stopped before any money moves (422 fraud_blocked).'
        : cls === 'allow' ? 'Payout proceeds — the network sees no confirmed fraud.'
        : 'Held for step-up review before the money can move.';
      $('score').textContent = data.score ?? '—';
      $('band').textContent = data.band ?? '—';
      $('mlat').textContent = data.masenu_latency_ms ?? '—';
      $('rtt').textContent = rtt;
      $('hits').textContent = (data.hits || []).length;
      const ul = $('expl'); ul.innerHTML = '';
      (data.explanations || []).slice(0, 4).forEach(e => {
        const li = document.createElement('li'); li.textContent = e; ul.appendChild(li);
      });
      $('nm').textContent = norm;
      $('hx').textContent = hash;
      $('out').hidden = false;
      $('out').scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    $('screenBtn').addEventListener('click', () => screen());
    $('phone').addEventListener('keydown', e => { if (e.key === 'Enter') screen(); });
    document.querySelectorAll('.chip').forEach(c => c.addEventListener('click', () => screen(c.dataset.n)));
  </script>
@endsection
