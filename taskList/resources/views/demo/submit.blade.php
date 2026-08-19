@extends('demo.layout')

@section('title', 'Fraud submission — edge-hash a spreadsheet · AllPay')
@section('tab_submit', 'active')

@section('style')
    .drop { border:1.5px dashed var(--line); border-radius:12px; padding:26px; text-align:center;
            color:var(--muted); cursor:pointer; transition:border-color .15s, background .15s; }
    .drop:hover, .drop.over { border-color:var(--gold); background:rgba(201,162,39,.05); color:var(--ink); }
    .drop input { display:none; }
    .controls { display:flex; gap:12px; flex-wrap:wrap; margin-top:16px; align-items:flex-end; }
    .field { display:flex; flex-direction:column; gap:5px; }
    .field label { font-size:12px; letter-spacing:.06em; text-transform:uppercase; color:#6b7280; }
    select { background:#0d1117; border:1px solid var(--line); border-radius:9px; color:var(--ink);
             font-size:14px; padding:10px 12px; outline:none; }
    select:focus { border-color:var(--gold); }
    .summary { display:flex; gap:22px; flex-wrap:wrap; margin:18px 0 6px; font-size:14px; color:var(--muted); }
    .summary b { color:var(--ink); font-size:22px; font-weight:800; display:block; }
    .cols { display:flex; flex-wrap:wrap; gap:7px; margin:10px 0 4px; }
    .tag { font-size:12px; padding:4px 10px; border-radius:999px; border:1px solid var(--line); }
    .tag.sent { color:var(--gold); border-color:rgba(201,162,39,.4); background:rgba(201,162,39,.07); }
    .tag.meta { color:var(--muted); }
    .tag.local { color:#6b7280; }
    .tag.local::before { content:'⌂ '; }
    .tag.sent::before { content:'# '; }
    .tag.meta::before { content:'✎ '; }
    .tbl-wrap { overflow-x:auto; border:1px solid var(--line); border-radius:12px; margin-top:14px; }
    table { border-collapse:collapse; width:100%; font-size:13px; }
    th, td { text-align:left; padding:9px 12px; border-bottom:1px solid var(--line); white-space:nowrap; }
    th { color:#6b7280; font-weight:600; font-size:11px; letter-spacing:.08em; text-transform:uppercase;
         position:sticky; top:0; background:var(--card); }
    td.kind { color:var(--muted); }
    td.hash code { color:var(--gold); font-size:12px; }
    td.mask { color:var(--muted); font-family:ui-monospace,Menlo,Consolas,monospace; }
    tr.local-row td { color:#6b7280; }
    tr.local-row td.kind::after { content:' · stays local'; color:#4b5563; font-size:11px; }
    .actions { display:flex; gap:12px; flex-wrap:wrap; margin-top:20px; }
    .result { margin-top:16px; font-size:14px; }
    .result.ok { color:var(--green); }
    .result.err { color:var(--red); }
    .note { font-size:13px; color:#6b7280; line-height:1.6; margin-top:14px; }
    .note b { color:var(--muted); }
@endsection

@section('main')
  <div class="card">
    <h1>Fraud submission</h1>
    <p class="lead">Your team categorises confirmed fraudsters in a spreadsheet — name, ID, number, location,
       bank details. Drop it here. AllPay hashes the <strong style="color:var(--ink)">joinable</strong> columns
       (number, ID, bank, wallet, email…) <strong style="color:var(--ink)">in your browser</strong>, keeps name
       &amp; location local, and lets you download the hashed file before anything is sent. Masenu applies the
       second hash layer server-side.</p>

    <label class="drop" id="drop">
      <input type="file" id="file" accept=".xlsx,.xls,.csv">
      <div><strong style="color:var(--gold)">Choose a spreadsheet</strong> or drop it here</div>
      <div style="font-size:13px;margin-top:6px">.xlsx / .csv — one row per fraudster, a header row on top</div>
    </label>

    <div class="controls" id="controls" hidden>
      <div class="field">
        <label>Default fraud type</label>
        <select id="fraudType">
          <option value="social_engineering" selected>Social engineering / vishing</option>
          <option value="sim_swap">SIM swap</option>
          <option value="account_takeover">Account takeover</option>
          <option value="mule_account">Mule account</option>
          <option value="agent_fraud">Agent fraud</option>
          <option value="phishing">Phishing</option>
          <option value="identity_theft">Identity theft</option>
          <option value="other">Other</option>
        </select>
      </div>
      <div class="field">
        <label>Role in the fraud</label>
        <select id="role">
          <option value="perpetrator" selected>Perpetrator</option>
          <option value="mule">Mule</option>
          <option value="instrument">Instrument</option>
        </select>
      </div>
    </div>

    <div id="mapped" hidden>
      <div class="summary">
        <span><b id="nRows">0</b> fraudsters</span>
        <span><b id="nHashes">0</b> hashed identifiers</span>
        <span><b id="nLocal">0</b> columns kept local</span>
      </div>
      <div class="cols" id="cols"></div>

      <div class="tbl-wrap">
        <table id="preview">
          <thead><tr><th>#</th><th>identifier</th><th>value (masked)</th><th>one-way hash (h1)</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>

      <div class="actions">
        <button class="ghost" id="dlBtn">⬇ Download hashed CSV</button>
        <button class="primary" id="submitBtn">Submit to Masenu (2nd hashing) →</button>
      </div>
      <div class="result" id="result" hidden></div>

      <p class="note"><b>What leaves the box:</b> the <span style="color:var(--gold)">#</span> columns as one-way
         hashes, plus <span>✎</span> fraud type &amp; notes as case metadata (org-private in Masenu).
         <b>Name &amp; location</b> (<span>⌂</span>) never leave — they stay in AllPay's own case record.
         Masenu joins fraud on hashed identifiers, not names.</p>
    </div>

    <details>
      <summary>Behind the scenes — where this lives in the code</summary>
      <div class="paths">
        <p><b>In this PSP (AllPay):</b><br>
           • this page — SheetJS parses the Excel, <code>edgeHash()</code> hashes each joinable cell in the browser<br>
           • <code>POST /demo/submit</code> (<code>routes/web.php</code>) — forwards the <em>already-hashed</em>
             reports to Masenu, one <code>/v1/reports</code> call each, with an <code>Idempotency-Key</code></p>
        <p><b>In Masenu (the network backend):</b><br>
           • <code>POST /v1/reports</code> → <code>app/reports/routes.py</code><br>
           • <code>app/crypto/peppering.py</code> — applies the 2nd (central) hash layer → the join key <code>h2</code><br>
           • <code>app/reports/service.py</code> — stores the report, rescoring every joined entity in &lt; 5s</p>
      </div>
    </details>
  </div>
@endsection

@section('scripts')
  <script src="/vendor/xlsx.full.min.js"></script>
  <script>
    const CONSORTIUM_PEPPER = @json(config('psp.fraud.pepper') ?? 'dev-only-pepper-not-for-production');
    const CSRF = document.querySelector('meta[name=csrf-token]').content;

    // ── Column header → identifier kind. Joinable kinds get hashed and sent.
    //    name/location are recognised but KEPT LOCAL (never sent, not join keys).
    const KIND_SYNONYMS = {
      msisdn:       ['msisdn','phone','number','mobile','tel','telephone','contact','momo number','phone number'],
      momo_wallet:  ['wallet','momo wallet','momo','ewallet','e-wallet'],
      bank_account: ['bank','account','bank account','acct','account number','iban'],
      ghana_card:   ['id','ghana card','ghanacard','national id','card','card number','ghana-card','nid'],
      passport:     ['passport','passport no','passport number'],
      tin:          ['tin','tax id','tin number'],
      email:        ['email','e-mail','mail','email address'],
      device_id:    ['device','device id','imei'],
      ip:           ['ip','ip address'],
      social_handle:['handle','social','username','social handle'],
      url:          ['url','website','link'],
    };
    const LOCAL_FIELDS = {
      name:     ['name','full name','fullname','fraudster','fraudster name','person'],
      location: ['location','city','town','region','address','area','district'],
    };
    const META_FIELDS = {
      fraud_type: ['fraud type','type','category','scam type','fraud'],
      narrative:  ['narrative','notes','description','details','remarks','comment','comments'],
    };

    function classify(header) {
      const h = String(header || '').trim().toLowerCase();
      if (!h) return { role: 'ignore' };
      for (const [kind, syns] of Object.entries(KIND_SYNONYMS))
        if (syns.includes(h)) return { role: 'kind', kind };
      for (const [field, syns] of Object.entries(LOCAL_FIELDS))
        if (syns.includes(h)) return { role: 'local', field };
      for (const [field, syns] of Object.entries(META_FIELDS))
        if (syns.includes(h)) return { role: 'meta', field };
      return { role: 'ignore', header: h };   // unrecognised → left out entirely
    }

    // ── Edge hashing — same normalize + HMAC-SHA256 as MasenuClient + the SDKs.
    function normalize(kind, value) {
      let v = String(value).trim();
      if (['msisdn','momo_wallet','bank_account'].includes(kind)) {
        let d = v.replace(/\D/g, '');
        if (kind === 'msisdn' && d.length === 10 && d.startsWith('0')) d = '233' + d.slice(1);
        return d;
      }
      if (['email','social_handle'].includes(kind)) return v.toLowerCase().replace(/^@+/, '');
      if (kind === 'url') return v.toLowerCase().replace(/\/+$/, '');
      if (['ghana_card','passport','tin'].includes(kind)) return v.replace(/[\s\-]/g, '').toUpperCase();
      return v.toLowerCase();
    }
    async function edgeHash(kind, value) {
      const enc = new TextEncoder();
      const key = await crypto.subtle.importKey('raw', enc.encode(CONSORTIUM_PEPPER),
        { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
      const sig = await crypto.subtle.sign('HMAC', key, enc.encode(normalize(kind, value)));
      return [...new Uint8Array(sig)].map(b => b.toString(16).padStart(2, '0')).join('');
    }
    function mask(value) {
      const s = String(value);
      if (s.length <= 4) return '•'.repeat(s.length);
      return s.slice(0, 2) + '•'.repeat(Math.max(2, s.length - 4)) + s.slice(-2);
    }

    let REPORTS = [];   // [{ fraud_type, narrative, identifiers:[{kind,value_hash,role}], _preview:[...] }]

    async function handleRows(rows) {
      if (!rows.length) return;
      const headers = rows[0].map(h => String(h ?? ''));
      const cls = headers.map(classify);
      const defaultType = document.getElementById('fraudType').value;
      const defaultRole = document.getElementById('role').value;

      // Column tags
      const seenKinds = new Set(), seenLocal = new Set();
      cls.forEach((c, i) => {
        if (c.role === 'kind') seenKinds.add(headers[i] + '→' + c.kind);
        if (c.role === 'local') seenLocal.add(headers[i]);
      });
      const colsEl = document.getElementById('cols'); colsEl.innerHTML = '';
      cls.forEach((c, i) => {
        if (c.role === 'ignore') return;
        const t = document.createElement('span');
        if (c.role === 'kind')      { t.className = 'tag sent';  t.textContent = `${headers[i]} → ${c.kind}`; }
        else if (c.role === 'meta') { t.className = 'tag meta';  t.textContent = `${headers[i]} → ${c.field}`; }
        else                        { t.className = 'tag local'; t.textContent = headers[i]; }
        colsEl.appendChild(t);
      });

      REPORTS = [];
      const previewRows = [];
      let hashCount = 0;
      for (let r = 1; r < rows.length; r++) {
        const row = rows[r];
        if (!row || row.every(c => c === '' || c == null)) continue;
        const ids = [], previews = [];
        let fraud_type = defaultType, narrative = '';
        for (let i = 0; i < cls.length; i++) {
          const c = cls[i], cell = row[i];
          if (cell === '' || cell == null) continue;
          if (c.role === 'kind') {
            const value_hash = await edgeHash(c.kind, cell);
            ids.push({ kind: c.kind, value_hash, role: defaultRole });
            previews.push({ kind: c.kind, mask: mask(cell), value_hash, local: false });
            hashCount++;
          } else if (c.role === 'local') {
            previews.push({ kind: c.field, mask: mask(cell), value_hash: '', local: true });
          } else if (c.role === 'meta' && c.field === 'fraud_type') {
            fraud_type = String(cell).trim().toLowerCase().replace(/[\s\-]+/g, '_');
          } else if (c.role === 'meta' && c.field === 'narrative') {
            narrative = String(cell).trim();
          }
        }
        if (!ids.length) continue;   // no joinable identifier on this row → skip
        REPORTS.push({ fraud_type, narrative, identifiers: ids, _row: REPORTS.length + 1 });
        previews.forEach(p => previewRows.push({ ...p, row: REPORTS.length }));
      }

      // Fill preview table
      const tb = document.querySelector('#preview tbody'); tb.innerHTML = '';
      previewRows.forEach(p => {
        const tr = document.createElement('tr');
        if (p.local) tr.className = 'local-row';
        tr.innerHTML =
          `<td>${p.row}</td><td class="kind">${p.kind}</td>` +
          `<td class="mask">${p.mask}</td>` +
          `<td class="hash">${p.local ? '—' : '<code>' + p.value_hash + '</code>'}</td>`;
        tb.appendChild(tr);
      });

      document.getElementById('nRows').textContent = REPORTS.length;
      document.getElementById('nHashes').textContent = hashCount;
      document.getElementById('nLocal').textContent = seenLocal.size;
      document.getElementById('controls').hidden = false;
      document.getElementById('mapped').hidden = false;
      document.getElementById('result').hidden = true;
    }

    function parseFile(file) {
      const reader = new FileReader();
      reader.onload = e => {
        const wb = XLSX.read(new Uint8Array(e.target.result), { type: 'array' });
        const ws = wb.Sheets[wb.SheetNames[0]];
        const rows = XLSX.utils.sheet_to_json(ws, { header: 1, blankrows: false, defval: '' });
        handleRows(rows);
      };
      reader.readAsArrayBuffer(file);
    }

    // ── Download the hashed CSV — the proof that only hashes leave.
    function downloadCsv() {
      const lines = [['report_row', 'fraud_type', 'role', 'kind', 'value_hash', 'pepper_v'].join(',')];
      REPORTS.forEach(rep => rep.identifiers.forEach(id => {
        lines.push([rep._row, rep.fraud_type, id.role, id.kind, id.value_hash, 1].join(','));
      }));
      const blob = new Blob([lines.join('\n')], { type: 'text/csv' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'allpay-hashed-fraud-report.csv';
      a.click();
      URL.revokeObjectURL(a.href);
    }

    async function submit() {
      const btn = document.getElementById('submitBtn');
      const res = document.getElementById('result');
      btn.disabled = true; btn.textContent = 'Submitting…';
      try {
        const payload = { reports: REPORTS.map(r => ({
          fraud_type: r.fraud_type, narrative: r.narrative || undefined,
          identifiers: r.identifiers.map(i => ({ kind: i.kind, value_hash: i.value_hash, role: i.role })),
        })) };
        const r = await fetch('/demo/submit', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
          body: JSON.stringify(payload),
        });
        const data = await r.json();
        res.hidden = false;
        if (r.ok && data.submitted === data.total) {
          res.className = 'result ok';
          res.textContent = `✔ ${data.submitted}/${data.total} reports accepted by Masenu — every joined entity is being rescored now.`;
        } else {
          res.className = 'result err';
          res.textContent = `Submitted ${data.submitted}/${data.total}. Some rows were rejected — check the console.`;
          console.log('submit results', data);
        }
      } catch (e) {
        res.hidden = false; res.className = 'result err'; res.textContent = 'Submit failed: ' + e.message;
      } finally {
        btn.disabled = false; btn.textContent = 'Submit to Masenu (2nd hashing) →';
      }
    }

    // ── wiring
    const drop = document.getElementById('drop'), fileInput = document.getElementById('file');
    fileInput.addEventListener('change', e => { if (e.target.files[0]) parseFile(e.target.files[0]); });
    ['dragover','dragenter'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
    ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
    drop.addEventListener('drop', e => { const f = e.dataTransfer.files[0]; if (f) parseFile(f); });
    document.getElementById('dlBtn').addEventListener('click', downloadCsv);
    document.getElementById('submitBtn').addEventListener('click', submit);
  </script>
@endsection
