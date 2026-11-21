/* Cafresco rider partner portal */
(function () {
  'use strict';
  const { api, esc, peso, $, $$, toast, busy, formData, beep, systemNotify, fmtDate } = CF;
  const view = $('#view');
  const S = { user: null, known: new Set(), timer: null, first: true };

  function nav() {
    $('#rnav').innerHTML = S.user ? '<a href="#" id="lo">Log out</a>' : '<a href="rider/#login">Log in</a>';
    const lo = $('#lo');
    if (lo) lo.onclick = async (e) => { e.preventDefault(); await api('auth/logout', { body: {} }); location.reload(); };
  }

  function showLogin() {
    view.innerHTML = `<div class="wrap"><div class="auth"><h1>Rider log in</h1>
      <form id="lf" class="panel shadow"><label class="field"><span>Email or mobile</span><input name="login" required autocomplete="username"></label>
      <label class="field"><span>Password</span><input name="password" type="password" required autocomplete="current-password"></label>
      <button class="btn block">Log in</button><p class="small" style="margin:14px 0 0">New partner? <a href="rider/#register">Register here</a></p></form></div></div>`;
    $('#lf').onsubmit = (e) => { e.preventDefault(); busy(e.submitter, async () => { const r = await api('auth/login', { body: formData(e.target) }); start(r.user); }); };
  }

  async function showRegister() {
    const { companies } = await api('rider/companies');
    view.innerHTML = `<div class="wrap"><div class="auth"><h1>Become a partner rider</h1>
      <p class="muted">Register under your rider company. Once Cafresco approves you, go online to receive pickup jobs.</p>
      <form id="rf" class="panel shadow">
        <label class="field"><span>Rider company</span><select name="company_id" required><option value="">Choose…</option>${companies.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select></label>
        ${companies.length ? '' : '<div class="notice small">No companies are listed yet. Ask Cafresco to add yours.</div>'}
        <label class="field"><span>Full name</span><input name="name" required></label>
        <label class="field"><span>Mobile</span><input name="mobile" type="tel" required placeholder="09XX XXX XXXX"></label>
        <label class="field"><span>Email</span><input name="email" type="email" required></label>
        <div class="grid2"><label class="field"><span>Vehicle</span><input name="vehicle" placeholder="Motorcycle"></label>
        <label class="field"><span>Plate no.</span><input name="plate_no" style="text-transform:uppercase"></label></div>
        <label class="field"><span>Password (8+)</span><input name="password" type="password" minlength="8" required autocomplete="new-password"></label>
        <button class="btn block clay">Register</button>
        <p class="small" style="margin:14px 0 0">Already registered? <a href="rider/#login">Log in</a></p>
      </form></div></div>`;
    $('#rf').onsubmit = (e) => { e.preventDefault(); busy(e.submitter, async () => { const r = await api('rider/register', { body: formData(e.target) }); toast('Registered! Waiting for approval.'); start(r.user); }); };
  }

  function showPending() {
    const r = S.user.rider;
    view.innerHTML = `<div class="wrap"><div class="auth"><h1>${r && r.approval === 'rejected' ? 'Application declined' : 'Waiting for approval'}</h1>
      <div class="panel shadow"><p>Hi ${esc(S.user.name)}! ${r && r.approval === 'rejected' ? 'Cafresco did not approve this rider account. Please message the café on Facebook.' : `Cafresco will review your registration under <b>${esc(r?.company_name || '')}</b>. This page refreshes automatically.`}</p>
      ${S.user.email_verified ? '' : '<p class="small muted">Tip: verify your email using the link we sent you.</p>'}</div></div></div>`;
    clearInterval(S.timer);
    S.timer = setInterval(async () => { const m = await api('auth/me').catch(() => null); if (m?.user?.rider?.approval === 'approved') start(m.user); }, 20000);
  }

  async function showJobs() {
    let d;
    try { d = await api('rider/jobs'); } catch (e) { if (e.status === 403) { const m = await api('auth/me'); S.user = m.user; return showPending(); } toast(e.message, true); return; }
    const fresh = d.available.filter((j) => !S.known.has(j.id));
    if (!S.first && fresh.length) { beep(); fresh.forEach((j) => systemNotify('New Cafresco pickup', `${j.code} · ${j.items.length} item(s)`)); }
    d.available.forEach((j) => S.known.add(j.id));
    S.first = false;
    const r = S.user.rider;
    view.innerHTML = `<div class="wrap" style="padding-top:20px;max-width:720px">
      <div class="panel shadow ${d.online ? '' : 'concrete'}">
        <div class="row" style="justify-content:space-between">
          <div><div class="label">${esc(r.company_name)}</div><b style="font-size:1.2rem">${esc(S.user.name)}</b><div class="small muted">${esc(r.vehicle || '')} ${esc(r.plate_no || '')}</div></div>
          <button class="btn ${d.online ? 'clay' : ''}" id="tog">${d.online ? '● Online' : 'Go online'}</button>
        </div>
        <p class="small muted" style="margin:10px 0 0">${d.online ? 'You will get alerts while this page is open. Keep it open (or installed) on your phone.' : 'You are offline. Go online to see and accept jobs.'}</p>
        ${'Notification' in window && Notification.permission !== 'granted' ? '<button class="btn sm ghost" id="notif" style="margin-top:10px">Enable sound &amp; alerts</button>' : ''}
      </div>
      ${d.base_rate !== null || d.rate_note ? `<div class="notice small">Your company starting rate: <b>${d.base_rate !== null ? peso(d.base_rate) : '—'}</b>${d.rate_note ? ' · ' + esc(d.rate_note) : ''}. Customers see this at checkout.</div>` : ''}
      <h2>My jobs</h2>
      ${d.mine.length ? d.mine.map((j) => jobCard(j, true)).join('') : '<div class="empty small" style="margin-bottom:18px">No active jobs.</div>'}
      <h2 style="margin-top:20px">Available jobs</h2>
      ${d.online ? (d.available.length ? d.available.map((j) => jobCard(j, false, d.base_rate)).join('') : '<div class="empty small">No jobs right now. We will alert you.</div>') : '<div class="empty small">Go online to see jobs.</div>'}
    </div>`;
    $('#tog').onclick = (e) => busy(e.target, async () => { await api('rider/online', { body: { online: !d.online } }); showJobs(); });
    const n = $('#notif');
    if (n) n.onclick = async () => { await Notification.requestPermission(); beep(); showJobs(); };
    $$('[data-act]').forEach((b) => b.onclick = () => busy(b, async () => {
      const body = {};
      if (b.dataset.act === 'accept') {
        const inp = $(`#fee${b.dataset.id}`);
        if (!inp.reportValidity()) return;
        body.fee = inp.value;
      }
      if (b.dataset.act === 'withdraw' && !confirm('Withdraw your offer? The job goes back to other riders.')) return;
      await api(`rider/jobs/${b.dataset.id}/${b.dataset.act}`, { body });
      toast({ accept: 'Rate sent! Waiting for the customer to accept.', withdraw: 'Offer withdrawn', pickup: 'Marked as picked up', deliver: 'Delivered. Thank you!' }[b.dataset.act]);
      showJobs();
    }));
    // Keep a half-typed rate from being wiped by the 15-second refresh.
    $$('.fee-in').forEach((i) => { i.oninput = () => { S.typing = Date.now(); }; });
  }

  function jobCard(j, mine, baseRate) {
    const min = baseRate !== null && baseRate !== undefined ? baseRate : 1;
    const quote = `<div class="row" style="flex-wrap:nowrap;align-items:flex-end">
        <label class="field" style="flex:1;margin:0"><span>Your delivery fee (₱)</span><input id="fee${j.id}" class="fee-in mono" type="number" inputmode="decimal" required min="${min}" max="3000" step="1" placeholder="${baseRate ? Math.round(baseRate) : 'e.g. 60'}"></label>
        <button class="btn clay" data-act="accept" data-id="${j.id}" style="min-height:46px">Send rate</button></div>
        <div class="small muted" style="margin-top:6px">The customer accepts or declines your rate. You get their name and number once they accept.</div>`;
    const action = !mine ? quote
      : j.quote_status === 'pending' ? `<div class="notice small" style="margin:0 0 8px">Rate sent: <b>${peso(j.rider_fee)}</b>. Waiting for the customer to accept.</div><button class="btn sm ghost" data-act="withdraw" data-id="${j.id}">Withdraw offer</button>`
      : j.status === 'ready' ? `<button class="btn clay block" data-act="pickup" data-id="${j.id}">I picked it up</button>`
      : j.status === 'out_for_delivery' ? `<button class="btn block" data-act="deliver" data-id="${j.id}">Delivered</button>`
      : ['confirmed', 'preparing'].includes(j.status) ? '<div class="notice small" style="margin:0">Being prepared. We will notify you when it is ready.</div>' : '';
    return `<div class="panel ${mine ? 'shadow' : ''}">
      <div class="row" style="justify-content:space-between"><b class="mono">${esc(j.code)}</b><span class="tag ${j.status === 'ready' ? 'dark' : j.status === 'completed' ? 'ok' : ''}">${esc(j.status_label)}</span></div>
      <div class="small" style="margin:6px 0">${j.items.map((i) => `${i.qty}× ${esc(i.name)}${i.variant_name ? ' (' + esc(i.variant_name) + ')' : ''}`).join(', ')}</div>
      <div class="row small" style="justify-content:space-between;margin-bottom:10px">
        <span>${j.collect_from_customer ? `<span class="tag clay">Pay café ${peso(j.total)} at pickup</span>` : '<span class="tag ok">Prepaid via GCash</span>'}</span>
        <span class="muted">${j.pickup_time ? 'Ready at ' + fmtDate(j.pickup_time) : 'ASAP'}</span></div>
      ${mine && j.customer_name ? `<div class="notice small"><b>${esc(j.customer_name)}</b> · <a href="tel:${esc(j.customer_mobile)}">${esc(j.customer_mobile)}</a><br>${esc(j.delivery_address || '')}${j.note ? `<br>“${esc(j.note)}”` : ''}
        ${j.delivery_lat !== null ? `<div style="margin-top:8px"><a class="btn sm" href="${CF.dirLink(j.delivery_lat, j.delivery_lng)}" target="_blank" rel="noopener">📍 Directions to GPS pin</a></div>` : ''}
        ${j.rider_fee !== null ? `<div style="margin-top:6px">Agreed delivery fee: <b>${peso(j.rider_fee)}</b> (collect from customer)</div>` : ''}</div>` : ''}
      ${!j.customer_name && j.delivery_address ? `<div class="small" style="margin-bottom:10px"><span class="label">Drop-off</span><br>${esc(j.delivery_address)}${j.distance_km !== null ? ` <span class="tag">≈ ${j.distance_km} km from café</span>` : ''}</div>` : ''}
      ${['completed', 'cancelled', 'rejected'].includes(j.status) ? '' : action}</div>`;
  }

  function start(user) {
    S.user = user;
    nav();
    clearInterval(S.timer);
    if (!user) return location.hash === '#register' ? showRegister() : showLogin();
    if (user.role !== 'rider') {
      view.innerHTML = `<div class="wrap"><div class="auth"><div class="notice">You are logged in as a ${esc(user.role)}. Rider partners need a separate rider account.</div><a class="btn" href="./">Back to shop</a></div></div>`;
      return;
    }
    if (!user.rider || user.rider.approval !== 'approved') return showPending();
    showJobs();
    S.timer = setInterval(() => { if (Date.now() - (S.typing || 0) > 20000 || !$('.fee-in')) showJobs(); }, 15000);
  }

  window.addEventListener('hashchange', () => { if (!S.user) start(null); });
  api('auth/me').then((r) => start(r.user)).catch((e) => { view.innerHTML = `<div class="wrap"><div class="notice bad">${esc(e.message)}</div></div>`; });
})();
