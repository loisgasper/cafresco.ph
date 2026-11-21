/* Cafresco customer app */
(function () {
  'use strict';
  const { api, esc, peso, $, $$, toast, modal, busy, formData, router, fmtDate, systemNotify } = CF;
  const view = $('#view');
  const S = { user: null, cfg: {}, menu: null, cat: 'all', notifSince: 0 };

  // ---------- Cart (kept on this device) ----------
  const Cart = {
    items: [],
    load() { try { this.items = JSON.parse(localStorage.getItem('cf_cart') || '[]'); } catch (e) { this.items = []; } },
    save() { try { localStorage.setItem('cf_cart', JSON.stringify(this.items)); } catch (e) { /* ignore */ } updateCartBadge(); },
    add(item) {
      const key = [item.product_id, item.variant_id || 0, (item.addon_ids || []).slice().sort().join('.'), item.note || ''].join('|');
      const ex = this.items.find((i) => i.key === key);
      if (ex) ex.qty = Math.min(50, ex.qty + item.qty); else this.items.push({ ...item, key });
      this.save();
    },
    count() { return this.items.reduce((s, i) => s + i.qty, 0); },
    total() { return this.items.reduce((s, i) => s + i.unit * i.qty, 0); },
    clear() { this.items = []; this.save(); },
  };

  function updateCartBadge() {
    const n = Cart.count();
    $$('[data-cartcount]').forEach((b) => { b.textContent = n; b.classList.toggle('hidden', n === 0); });
    $$('[data-carttotal]').forEach((b) => { b.textContent = peso(Cart.total()); });
    $('#cartFab').classList.toggle('hidden', n === 0 || /^#\/(cart|checkout)/.test(location.hash));
  }

  function setNav(name) {
    $$('[data-nav]').forEach((a) => a.classList.toggle('active', a.dataset.nav === name));
    updateCartBadge();
  }

  const verified = () => S.user && S.user.email_verified && (!S.cfg.require_mobile_verification || S.user.mobile_verified);

  // ---------- Menu ----------
  let rotTimer = null;
  async function showMenu() {
    setNav('menu');
    if (!S.menu) {
      view.innerHTML = '<div class="spinner"></div>';
      try { S.menu = await api('menu'); } catch (e) { view.innerHTML = `<div class="wrap"><div class="notice bad">${esc(e.message)}</div></div>`; return; }
    }
    const m = S.menu;
    const cats = [{ id: 'all', name: 'All' }, ...m.categories];
    const promo = m.promos[0];
    const catOrder = (p) => { const i = m.categories.findIndex((c) => Number(c.id) === p.category_id); return i < 0 ? 99 : i; };
    const favs = m.products.filter((p) => p.featured).sort((x, y) => catOrder(x) - catOrder(y)).map((p) => p.name);
    const words = favs.length ? favs : ['Spanish Latte', 'Matcha', 'Biscoff', 'Cappuccino'];
    const banner = (S.cfg.banner_text || '').split('·').map((t) => t.trim()).filter(Boolean);
    if (promo) banner.unshift(`${promo.name} · ${promo.type === 'percent' ? Number(promo.value) + '% off' : peso(promo.value) + ' off'}`);
    const bannerHtml = banner.length ? `<div class="marquee" aria-label="${esc(banner.join('. '))}"><div class="track" aria-hidden="true">${[...banner, ...banner, ...banner, ...banner].map((t) => `<span>${esc(t)}</span>`).join('')}</div></div>` : '';

    // When "All" is selected, group drinks under their category headings.
    const groups = S.cat === 'all'
      ? [...m.categories.map((c) => ({ name: c.name, items: m.products.filter((p) => p.category_id === Number(c.id)) })),
         { name: 'More', items: m.products.filter((p) => !p.category_id || !m.categories.some((c) => Number(c.id) === p.category_id)) }].filter((g) => g.items.length)
      : [{ name: null, items: m.products.filter((p) => p.category_id === Number(S.cat)) }];

    view.innerHTML = `
      ${bannerHtml}
      <section class="hero warm"><div class="wrap">
        <svg class="steam" viewBox="0 0 120 150" aria-hidden="true"><path d="M30 140 C10 110 50 90 30 60 S40 20 30 5"/><path d="M60 140 C40 110 80 90 60 60 S70 20 60 5"/><path d="M90 140 C70 110 110 90 90 60 S100 20 90 5"/></svg>
        <div>
          <div class="label">Pickup · Takeout · Partner riders</div>
          <h1>Your <span class="rotator" id="rot">${esc(words[0])}</span><br>is a tap away.</h1>
          <p style="max-width:36ch;color:var(--ink-2)">Order ahead from our home café, pay with GCash, and grab it when it's ready. Members earn points on every online order.</p>
          ${!S.cfg.store_open ? '<div class="notice bad"><b>We are closed for online orders right now.</b> You can still browse the menu.</div>' : ''}
        </div>
        <div class="stamp">
          <div class="label">Open</div>
          <div style="font-weight:800;font-size:1.1rem">${esc(S.cfg.store_hours || '')}</div>
          ${S.cfg.store_address ? `<div class="small muted">${esc(S.cfg.store_address)}</div>` : ''}
          ${S.user ? `<div style="margin-top:10px" class="mono small">${esc(S.user.name.split(' ')[0])} · <b>${S.user.points}</b> pts</div>` : '<a class="btn sm clay" style="margin-top:12px" href="#/register">Join &amp; earn points</a>'}
          <div class="small muted" style="margin-top:10px"><span style="color:var(--clay)">✿</span> = customer favorite</div>
        </div>
      </div></section>
      <div class="wrap">
        ${verifyBanner()}
        <div class="cats" role="tablist">${cats.map((c) => `<button data-cat="${c.id}" class="${String(S.cat) === String(c.id) ? 'on' : ''}">${esc(c.name)}</button>`).join('')}</div>
        ${groups.some((g) => g.items.length) ? groups.map((g) => `
          ${g.name ? `<div class="cat-title"><h2>${esc(g.name)}</h2><span class="label">${g.items.length} drinks</span></div>` : ''}
          <div class="menu-list">${g.items.map(drinkCard).join('')}</div>`).join('') : '<div class="empty" style="margin-top:20px">No items here yet.</div>'}
      </div>
      ${coffeeGuide()}
      ${footer()}`;
    $$('[data-cat]').forEach((b) => b.onclick = () => { S.cat = b.dataset.cat; showMenu(); });
    $$('[data-pid]').forEach((b) => b.onclick = () => openProduct(Number(b.dataset.pid)));
    $$('[data-resend]').forEach((b) => b.onclick = () => resendEmail(b));
    clearInterval(rotTimer);
    let i = 0;
    rotTimer = setInterval(() => {
      const el = $('#rot');
      if (!el) { clearInterval(rotTimer); return; }
      i = (i + 1) % words.length;
      el.textContent = words[i];
      el.classList.toggle('swap', false);
      void el.offsetWidth;
      el.classList.toggle('swap', true);
    }, 2600);
  }

  function drinkCard(p) {
    const prices = p.variants.length
      ? p.variants.map((v) => `<span><small>${esc(v.name)}</small><b>${peso(v.price).replace('.00', '')}</b></span>`).join('')
      : `<span><b>${peso(p.price).replace('.00', '')}</b></span>`;
    return `<button class="drink${p.sold_out ? ' soldout' : ''}" data-pid="${p.id}" ${p.sold_out ? 'disabled' : ''}>
      <div class="info">
        <div class="name">${p.featured ? '<span class="fav" title="Customer favorite">✿</span>' : ''}${esc(p.name)} ${p.sold_out ? '<span class="tag">Sold out</span>' : ''}</div>
        ${p.description ? `<div class="desc">${esc(p.description)}</div>` : ''}
        <div class="prices">${prices}</div>
      </div>
      ${p.sold_out ? '' : '<span class="plus" aria-hidden="true">+</span>'}
    </button>`;
  }

  function coffeeGuide() {
    const items = [
      ['Americano', 'Espresso softened with hot or iced water. Clean, bold, and quietly strong.'],
      ['Latte', 'Espresso blended with steamed or iced milk. Smooth, creamy, and comforting in every sip.'],
      ['Cappuccino', 'Espresso with steamed or iced milk and a cloud of foam. Rich, airy, and perfectly balanced.'],
      ['Flavored Lattes', 'Espresso, milk, and a touch of your chosen flavor. Familiar, comforting, and easy to enjoy.'],
    ];
    return `<section class="guide"><div class="wrap">
      <div class="row" style="justify-content:space-between;align-items:flex-end">
        <h2 class="script">A Little Coffee Guide</h2><span class="note">You can ask for sugar! ♡</span>
      </div>
      <div class="guide-grid">${items.map(([t, d]) => `<div class="guide-card"><h3>${t}</h3><p>${d}</p></div>`).join('')}</div>
    </div></section>`;
  }

  function verifyBanner() {
    if (!S.user || S.user.role !== 'customer') return '';
    if (!S.user.email_verified) return `<div class="notice clay" style="margin-top:16px"><b>Verify your email to start ordering.</b> We sent a link to ${esc(S.user.email)}. <button class="btn sm ghost" data-resend style="margin-left:6px">Resend</button></div>`;
    if (S.cfg.require_mobile_verification && !S.user.mobile_verified) return '<div class="notice clay" style="margin-top:16px"><b>Verify your mobile number to order.</b> <a href="#/account">Verify now →</a></div>';
    return '';
  }

  function footer() {
    return `<footer class="footer"><div class="wrap row" style="justify-content:space-between">
      <div><b>CAFRESCO</b><div class="muted small">${esc(S.cfg.store_address || '')}</div></div>
      <div class="row small">${S.cfg.facebook_url ? `<a href="${esc(S.cfg.facebook_url)}" target="_blank" rel="noopener">Facebook</a>` : ''}<a href="rider/">Rider partners</a></div>
    </div></footer>`;
  }

  function openProduct(id) {
    const p = S.menu.products.find((x) => x.id === id);
    if (!p) return;
    let qty = 1;
    const html = `
      ${p.description ? `<p class="muted">${esc(p.description)}</p>` : ''}
      <form id="pform">
        ${p.variants.length ? `<div class="label" style="margin:8px 0">Choose one</div>
          <div style="margin-bottom:16px">${p.variants.map((v, i) => `<label class="check"><input type="radio" name="variant" value="${v.id}" ${i === 0 ? 'checked' : ''}> ${esc(v.name)} <span class="price">${peso(v.price)}</span></label>`).join('')}</div>` : ''}
        ${p.addon_groups.map((g) => `<div class="label" style="margin:8px 0">${esc(g.name)} ${g.min ? `<span class="tag">Required</span>` : ''} <span class="muted">${g.max > 1 ? `· up to ${g.max}` : ''}</span></div>
          <div style="margin-bottom:16px" data-group="${g.id}" data-min="${g.min}" data-max="${g.max}">${g.addons.map((a) => `<label class="check"><input type="${g.max === 1 ? 'radio' : 'checkbox'}" name="g${g.id}" value="${a.id}" data-price="${a.price}"> ${esc(a.name)} <span class="price">${a.price ? '+' + peso(a.price) : 'free'}</span></label>`).join('')}</div>`).join('')}
        <label class="field"><span>Note for this item (optional)</span><input name="note" maxlength="200" placeholder="e.g. less ice, less sugar"></label>
      </form>`;
    const foot = `<div class="qty"><button type="button" data-q="-1" aria-label="Less">−</button><span data-qv>1</span><button type="button" data-q="1" aria-label="More">+</button></div>
      <button class="btn clay" style="flex:1" data-add>Add · <span data-sum></span></button>`;
    const m = modal(p.name, html, { footer: foot });
    const form = $('#pform', m.el);
    const calc = () => {
      const v = p.variants.find((x) => x.id === Number(form.variant?.value));
      let unit = v ? v.price : p.price;
      $$('input[data-price]:checked', form).forEach((c) => { unit += Number(c.dataset.price); });
      $('[data-sum]', m.el).textContent = peso(unit * qty);
      return unit;
    };
    form.addEventListener('change', (e) => {
      const g = e.target.closest('[data-group]');
      if (g && e.target.type === 'checkbox') {
        const max = Number(g.dataset.max);
        if ($$('input:checked', g).length > max) { e.target.checked = false; toast(`You can pick up to ${max}.`); }
      }
      calc();
    });
    $$('[data-q]', m.el).forEach((b) => b.onclick = () => { qty = Math.max(1, Math.min(50, qty + Number(b.dataset.q))); $('[data-qv]', m.el).textContent = qty; calc(); });
    calc();
    $('[data-add]', m.el).onclick = () => {
      for (const g of $$('[data-group]', form)) {
        if ($$('input:checked', g).length < Number(g.dataset.min)) { toast('Please complete the required choices.', true); return; }
      }
      const v = p.variants.find((x) => x.id === Number(form.variant?.value));
      const addons = $$('input[data-price]:checked', form);
      Cart.add({
        product_id: p.id, variant_id: v ? v.id : null, addon_ids: addons.map((c) => Number(c.value)), qty,
        note: form.note.value.trim(), name: p.name, variant_name: v ? v.name : null,
        addon_names: addons.map((c) => c.closest('label').textContent.replace(/\s+/g, ' ').replace(/\+?₱[\d,.]+|free/, '').trim()), unit: calc(),
      });
      m.close();
      toast(`Added ${qty}× ${p.name}`);
    };
  }

  // ---------- Cart & checkout ----------
  const co = { fulfillment: 'pickup', payment: 'gcash', code: '', points: 0, quote: null };

  function showCart() {
    setNav('cart');
    if (!Cart.items.length) {
      view.innerHTML = `<div class="wrap" style="padding-top:24px"><h1>Cart</h1><div class="empty"><p>Your cart is empty.</p><a class="btn" href="#/">Browse menu</a></div></div>`;
      return;
    }
    const lines = Cart.items.map((i, idx) => `<div class="line">
      <div class="grow"><b>${esc(i.name)}</b>${i.variant_name ? ` <span class="muted">· ${esc(i.variant_name)}</span>` : ''}
        ${i.addon_names?.length ? `<div class="small muted">+ ${esc(i.addon_names.join(', '))}</div>` : ''}
        ${i.note ? `<div class="small muted">“${esc(i.note)}”</div>` : ''}
        <div class="qty" style="margin-top:8px"><button data-dq="${idx}" data-d="-1" aria-label="Less">−</button><span>${i.qty}</span><button data-dq="${idx}" data-d="1" aria-label="More">+</button></div>
      </div><div class="amt">${peso(i.unit * i.qty)}</div></div>`).join('');

    const minTime = new Date(Date.now() + (S.cfg.min_prep_minutes || 15) * 60000);
    const local = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);

    view.innerHTML = `<div class="wrap" style="padding-top:24px">
      <h1>Checkout</h1>
      <div class="grid2" style="grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);align-items:start" id="cogrid">
        <div>
          <div class="panel"><h2>Your order</h2><div class="lines">${lines}</div>
            <a href="#/" class="btn sm ghost" style="margin-top:12px">+ Add more</a></div>
          <div class="panel">
            <h2>How will you get it?</h2>
            <div class="seg" style="margin-bottom:12px">
              <button data-ff="pickup" class="${co.fulfillment === 'pickup' ? 'on' : ''}">I'll pick up</button>
              <button data-ff="own_rider" class="${co.fulfillment === 'own_rider' ? 'on' : ''}">My rider</button>
              <button data-ff="partner_rider" class="${co.fulfillment === 'partner_rider' ? 'on' : ''}">Find a rider</button>
            </div>
            <div id="ffHelp" class="small muted" style="margin-bottom:12px"></div>
            <label class="field hidden" data-show="own_rider"><span>Rider name / company / plate (optional)</span><input id="ownRider" maxlength="200" placeholder="e.g. Juan, ABC Riders, plate 123"></label>
            <label class="field hidden" data-show="partner_rider"><span>Delivery address</span><textarea id="addr" maxlength="500" placeholder="House no., street, barangay, landmark"></textarea></label>
            <label class="field"><span>Ready time</span>
              <select id="when"><option value="">As soon as possible (~${S.cfg.min_prep_minutes || 15} min)</option><option value="later">Schedule for later…</option></select></label>
            <input type="datetime-local" id="whenAt" class="hidden" min="${local(minTime)}" value="${local(minTime)}" style="margin:-4px 0 14px">
            <label class="field"><span>Note for the café (optional)</span><input id="note" maxlength="500"></label>
          </div>
          <div class="panel">
            <h2>Payment</h2>
            <div class="seg" style="margin-bottom:12px">
              <button data-pm="gcash" class="${co.payment === 'gcash' ? 'on' : ''}">GCash now</button>
              ${S.cfg.allow_pay_on_pickup ? `<button data-pm="pay_on_pickup" class="${co.payment === 'pay_on_pickup' ? 'on' : ''}">Pay on pickup</button>` : ''}
            </div>
            <div id="pmHelp" class="small muted"></div>
          </div>
        </div>
        <div style="position:sticky;top:76px">
          <div class="panel shadow">
            <h2>Summary</h2>
            <div class="row" style="flex-wrap:nowrap;margin-bottom:12px"><input id="code" placeholder="Promo code" value="${esc(co.code)}" style="text-transform:uppercase"><button class="btn ghost sm" id="applyCode" style="min-height:46px">Apply</button></div>
            ${S.user ? `<div id="pointsBox"></div>` : ''}
            <div class="totals" id="totals"><div><span>Subtotal</span><span>${peso(Cart.total())}</span></div></div>
            <div id="coMsg"></div>
            <button class="btn clay block" id="place" style="margin-top:14px">${S.user ? 'Place order' : 'Log in to order'}</button>
            <p class="small muted" style="margin:10px 0 0">Rider fees are paid directly to your rider.</p>
          </div>
        </div>
      </div></div>`;
    if (window.innerWidth < 760) $('#cogrid').style.gridTemplateColumns = '1fr';

    $$('[data-dq]').forEach((b) => b.onclick = () => {
      const it = Cart.items[Number(b.dataset.dq)];
      it.qty += Number(b.dataset.d);
      if (it.qty <= 0) Cart.items.splice(Number(b.dataset.dq), 1);
      Cart.save(); co.points = 0; showCart();
    });
    $$('[data-ff]').forEach((b) => b.onclick = () => { co.fulfillment = b.dataset.ff; $$('[data-ff]').forEach((x) => x.classList.toggle('on', x === b)); syncCheckout(); });
    $$('[data-pm]').forEach((b) => b.onclick = () => { co.payment = b.dataset.pm; $$('[data-pm]').forEach((x) => x.classList.toggle('on', x === b)); syncCheckout(); });
    $('#when').onchange = () => $('#whenAt').classList.toggle('hidden', $('#when').value !== 'later');
    $('#applyCode').onclick = () => { co.code = $('#code').value.trim().toUpperCase(); refreshQuote(); };
    $('#place').onclick = (e) => placeOrder(e.currentTarget);
    syncCheckout();
    refreshQuote();
  }

  function syncCheckout() {
    $$('[data-show]').forEach((el) => el.classList.toggle('hidden', el.dataset.show !== co.fulfillment));
    $('#ffHelp').textContent = {
      pickup: 'Pick up at the café when we mark your order ready.',
      own_rider: 'Book your own rider (Grab, Move It, local riders…). Show them your order code at pickup.',
      partner_rider: 'We will alert our online partner riders. You will see who accepted your order.',
    }[co.fulfillment];
    $('#pmHelp').innerHTML = co.payment === 'gcash'
      ? `After placing your order, send the total to our GCash and upload the screenshot within ${S.cfg.payment_window_minutes} minutes. We start preparing once we confirm it.`
      : 'You or your rider pay in cash or GCash when picking up. Available after your first completed GCash order.';
  }

  let quoteTimer = null;
  function refreshQuote() {
    clearTimeout(quoteTimer);
    if (!S.user) return;
    quoteTimer = setTimeout(async () => {
      try {
        const q = await api('cart/quote', { body: { items: cartPayload(), discount_code: co.code, points: co.points } });
        co.quote = q;
        renderTotals(q);
        $('#coMsg').innerHTML = '';
      } catch (e) {
        if (co.code && /promo|code/i.test(e.message)) { co.code = ''; toast(e.message, true); refreshQuote(); return; }
        if (co.points && /point/i.test(e.message)) { co.points = 0; toast(e.message, true); refreshQuote(); return; }
        $('#coMsg').innerHTML = `<div class="notice bad small" style="margin-top:10px">${esc(e.message)}</div>`;
      }
    }, 150);
  }

  function renderTotals(q) {
    const pb = $('#pointsBox');
    if (pb) {
      const canUse = S.user.points >= S.cfg.min_points_redeem && q.max_points_usable >= S.cfg.min_points_redeem;
      pb.innerHTML = `<div class="notice small" style="margin-bottom:12px"><div class="row" style="justify-content:space-between">
        <span><b>${S.user.points}</b> points (${peso(S.user.points * S.cfg.peso_per_point)})</span>
        ${canUse ? `<label class="row small" style="gap:6px"><input type="checkbox" id="usePts" ${co.points ? 'checked' : ''}> Use ${q.max_points_usable} pts</label>` : `<span class="muted">min ${S.cfg.min_points_redeem} to redeem</span>`}
      </div></div>`;
      const up = $('#usePts');
      if (up) up.onchange = () => { co.points = up.checked ? q.max_points_usable : 0; refreshQuote(); };
    }
    $('#totals').innerHTML = `<div><span>Subtotal</span><span>${peso(q.subtotal)}</span></div>
      ${q.discount_total ? `<div><span>${esc(q.discount?.name || 'Discount')}</span><span>−${peso(q.discount_total)}</span></div>` : ''}
      ${q.points_value ? `<div><span>Points (${q.points})</span><span>−${peso(q.points_value)}</span></div>` : ''}
      <div class="grand"><span>Total</span><span>${peso(q.total)}</span></div>
      <div class="small muted" style="font-family:var(--font)">You'll earn ${q.points_to_earn} points when this order is completed.</div>`;
  }

  const cartPayload = () => Cart.items.map((i) => ({ product_id: i.product_id, variant_id: i.variant_id, addon_ids: i.addon_ids, qty: i.qty, note: i.note }));

  async function placeOrder(btn) {
    if (!S.user) { sessionStorage.setItem('cf_after_login', '#/cart'); location.hash = '#/login'; return; }
    if (!verified()) { toast('Please verify your account first (see Account).', true); location.hash = '#/account'; return; }
    const body = {
      items: cartPayload(), fulfillment: co.fulfillment, payment_method: co.payment, discount_code: co.code, points: co.points,
      note: $('#note').value, delivery_address: $('#addr').value, own_rider_info: $('#ownRider').value,
      pickup_time: $('#when').value === 'later' ? $('#whenAt').value : '',
    };
    await busy(btn, async () => {
      const r = await api('orders', { body });
      Cart.clear();
      co.points = 0; co.code = '';
      S.user.points -= r.order.points_redeemed;
      if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
      location.hash = '#/orders/' + r.order.id;
    });
  }

  // ---------- Orders ----------
  async function showOrders() {
    setNav('orders');
    if (!S.user) return needLogin('#/orders');
    view.innerHTML = '<div class="spinner"></div>';
    const { orders } = await api('orders');
    view.innerHTML = `<div class="wrap" style="padding-top:24px"><h1>Orders</h1>
      ${orders.length ? `<div class="tablewrap"><table class="t"><thead><tr><th>Order</th><th>Status</th><th>Total</th></tr></thead><tbody>
        ${orders.map((o) => `<tr class="click" data-oid="${o.id}"><td><b class="mono">${esc(o.code)}</b><div class="small muted">${fmtDate(o.created_at)}</div></td>
          <td>${statusTag(o.status, o.status_label)}</td><td class="mono">${peso(o.total)}</td></tr>`).join('')}
      </tbody></table></div>` : '<div class="empty"><p>No orders yet.</p><a class="btn" href="#/">Order now</a></div>'}</div>`;
    $$('[data-oid]').forEach((r) => r.onclick = () => { location.hash = '#/orders/' + r.dataset.oid; });
  }

  function statusTag(status, label) {
    const cls = { completed: 'ok', cancelled: 'bad', rejected: 'bad', pending_payment: 'clay', ready: 'dark' }[status] || '';
    return `<span class="tag ${cls}">${esc(label)}</span>`;
  }

  let orderPoll = null;
  async function showOrder(id) {
    setNav('orders');
    if (!S.user) return needLogin('#/orders/' + id);
    clearInterval(orderPoll);
    const render = async (first) => {
      let o;
      try { o = (await api('orders/' + id)).order; } catch (e) { if (first) view.innerHTML = `<div class="wrap"><div class="notice bad" style="margin-top:24px">${esc(e.message)}</div></div>`; return; }
      if (location.hash !== '#/orders/' + id) { clearInterval(orderPoll); return; }
      if (!first && $('#proofForm') && $('#proofForm').dataset.dirty) return; // don't wipe a half-filled form
      const stepIdx = { pending_payment: 0, payment_submitted: 0, pending_confirmation: 0, confirmed: 1, preparing: 1, ready: 2, out_for_delivery: 2, completed: 3 }[o.status];
      const ffLabel = { pickup: 'Pickup at café', own_rider: 'Your own rider', partner_rider: 'Cafresco partner rider' }[o.fulfillment];
      view.innerHTML = `<div class="wrap" style="padding-top:24px;max-width:760px">
        <a href="#/orders" class="small">← All orders</a>
        <div class="row" style="justify-content:space-between;margin:8px 0 16px"><h1 style="margin:0" class="mono">${esc(o.code)}</h1>${statusTag(o.status, o.status_label)}</div>
        ${stepIdx !== undefined ? `<div class="steps">${['Placed', 'Confirmed', o.fulfillment === 'partner_rider' ? 'On the way' : 'Ready', 'Done'].map((s, i) => `<div class="${i <= stepIdx ? 'on' : ''}">${s}</div>`).join('')}</div>` : ''}
        ${o.status === 'pending_payment' ? gcashPanel(o) : ''}
        ${o.status === 'payment_submitted' ? `<div class="notice"><b>Thanks! We're checking your GCash payment</b> (ref ${esc(o.gcash_ref)}). You'll get a notification once it's confirmed.</div>` : ''}
        ${o.status === 'pending_confirmation' ? '<div class="notice"><b>Waiting for the café to confirm your order.</b> Payment is on pickup.</div>' : ''}
        ${o.status === 'ready' && o.fulfillment !== 'partner_rider' ? `<div class="notice clay"><b>Your order is ready!</b> Show code <b class="mono">${esc(o.code)}</b> at pickup.</div>` : ''}
        ${o.rider ? `<div class="panel"><div class="label">Your rider</div><b>${esc(o.rider.name)}</b> · ${esc(o.rider.company)}<div class="small">${esc(o.rider.vehicle || '')} ${o.rider.plate_no ? '· Plate ' + esc(o.rider.plate_no) : ''} · <a href="tel:${esc(o.rider.mobile)}">${esc(o.rider.mobile)}</a></div></div>` : ''}
        ${o.fulfillment === 'partner_rider' && !o.rider && ['confirmed', 'preparing', 'ready'].includes(o.status) ? '<div class="notice small">Looking for an online partner rider… We will notify you when one accepts.</div>' : ''}
        <div class="panel">
          <div class="label">${esc(ffLabel)}${o.pickup_time ? ' · ' + fmtDate(o.pickup_time) : ' · ASAP'} · ${o.payment_method === 'gcash' ? 'GCash' : 'Pay on pickup'}</div>
          ${o.delivery_address ? `<div class="small" style="margin-top:4px">Deliver to: ${esc(o.delivery_address)}</div>` : ''}
          <div class="lines" style="margin-top:12px">${o.items.map((i) => `<div class="line"><div class="grow"><b>${i.qty}× ${esc(i.name)}</b>${i.variant_name ? ` · ${esc(i.variant_name)}` : ''}
            ${i.addons.length ? `<div class="small muted">+ ${esc(i.addons.map((a) => a.name).join(', '))}</div>` : ''}${i.note ? `<div class="small muted">“${esc(i.note)}”</div>` : ''}</div><div class="amt">${peso(i.line_total)}</div></div>`).join('')}</div>
          <div class="totals" style="margin-top:10px"><div><span>Subtotal</span><span>${peso(o.subtotal)}</span></div>
            ${o.discount_total ? `<div><span>Discount</span><span>−${peso(o.discount_total)}</span></div>` : ''}
            ${o.points_value ? `<div><span>Points (${o.points_redeemed})</span><span>−${peso(o.points_value)}</span></div>` : ''}
            <div class="grand"><span>Total</span><span>${peso(o.total)}</span></div>
            ${o.points_earned ? `<div><span>Points earned</span><span>+${o.points_earned}</span></div>` : ''}</div>
        </div>
        <div class="panel"><div class="label" style="margin-bottom:10px">History</div><ul class="timeline">${o.events.map((e) => `<li><b>${esc(eventLabel(e.status))}</b> <span class="small muted">${fmtDate(e.created_at)}</span>${e.note ? `<div class="small muted">${esc(e.note)}</div>` : ''}</li>`).join('')}</ul></div>
        ${['pending_payment', 'pending_confirmation'].includes(o.status) ? '<button class="btn danger" id="cancelOrder">Cancel order</button>' : ''}
      </div>`;
      const pf = $('#proofForm');
      if (pf) {
        pf.addEventListener('input', () => { pf.dataset.dirty = '1'; });
        pf.onsubmit = async (e) => {
          e.preventDefault();
          await busy(pf.querySelector('button[type=submit]'), async () => {
            await api('orders/' + id + '/payment', { body: new FormData(pf) });
            toast('Payment proof sent. Thank you!');
            render(true);
          });
        };
        $$('[data-copy]').forEach((b) => b.onclick = () => { navigator.clipboard?.writeText(b.dataset.copy); toast('Copied'); });
        startCountdown(o.pay_before);
      }
      const cb = $('#cancelOrder');
      if (cb) cb.onclick = () => { if (confirm('Cancel this order?')) busy(cb, async () => { await api('orders/' + id + '/cancel', { body: {} }); toast('Order cancelled'); render(true); }); };
      if (['completed', 'cancelled', 'rejected'].includes(o.status)) clearInterval(orderPoll);
    };
    view.innerHTML = '<div class="spinner"></div>';
    await render(true);
    orderPoll = setInterval(() => render(false), 15000);
  }

  const eventLabel = (s) => ({ rider_assigned: 'Rider assigned', pending_payment: 'Order placed · waiting for payment', pending_confirmation: 'Order placed' }[s]
    || { payment_submitted: 'Payment submitted', confirmed: 'Confirmed', preparing: 'Preparing', ready: 'Ready', out_for_delivery: 'On the way', completed: 'Completed', cancelled: 'Cancelled', rejected: 'Rejected' }[s] || s);

  function gcashPanel(o) {
    const c = S.cfg;
    return `<div class="panel shadow" style="border-color:var(--clay)">
      <div class="row" style="justify-content:space-between"><h2 style="margin:0">Pay with GCash</h2><span class="tag clay mono" id="countdown"></span></div>
      <p class="small muted" style="margin-top:6px">Send exactly <b>${peso(o.total)}</b>, then upload your receipt screenshot. Unpaid orders cancel automatically.</p>
      <div class="grid2" style="align-items:center">
        <div>
          <div class="label">GCash name</div><div style="font-weight:800;margin-bottom:8px">${esc(c.gcash_name || 'Ask us on Facebook')}</div>
          <div class="label">GCash number</div><div class="row" style="margin-bottom:8px"><b class="mono" style="font-size:1.2rem">${esc(c.gcash_number || '—')}</b>${c.gcash_number ? `<button class="btn sm ghost" data-copy="${esc(c.gcash_number)}">Copy</button>` : ''}</div>
          <div class="label">Amount</div><div class="row"><b class="mono" style="font-size:1.2rem">${peso(o.total)}</b><button class="btn sm ghost" data-copy="${o.total.toFixed(2)}">Copy</button></div>
          <div class="small muted" style="margin-top:8px">Put <b class="mono">${esc(o.code)}</b> in the GCash message.</div>
        </div>
        ${c.gcash_qr ? `<img src="${esc(c.gcash_qr)}" alt="GCash QR code" style="border:2px solid var(--ink);max-width:220px;justify-self:center">` : ''}
      </div>
      <form id="proofForm" style="margin-top:16px;border-top:2px solid var(--ink);padding-top:16px">
        <label class="field"><span>GCash reference no.</span><input name="gcash_ref" inputmode="numeric" required minlength="10" maxlength="20" placeholder="13-digit reference" class="mono"></label>
        <label class="field"><span>Sender name (as shown in GCash)</span><input name="gcash_sender" maxlength="120"></label>
        <label class="field"><span>Receipt screenshot</span><input name="proof" type="file" accept="image/png,image/jpeg,image/webp" required></label>
        <button class="btn clay block" type="submit">Submit payment proof</button>
        <p class="small muted" style="margin:10px 0 0">⚠︎ Cafresco will never ask for your GCash MPIN or OTP.</p>
      </form></div>`;
  }

  let cdTimer = null;
  function startCountdown(until) {
    clearInterval(cdTimer);
    if (!until) return;
    const end = new Date(until).getTime();
    const tick = () => {
      const el = $('#countdown');
      if (!el) { clearInterval(cdTimer); return; }
      const s = Math.max(0, Math.round((end - Date.now()) / 1000));
      el.textContent = s ? `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')} left` : 'time is up';
    };
    tick();
    cdTimer = setInterval(tick, 1000);
  }

  // ---------- Auth ----------
  function needLogin(back) {
    sessionStorage.setItem('cf_after_login', back);
    location.hash = '#/login';
  }

  function afterLogin() {
    const back = sessionStorage.getItem('cf_after_login') || '#/';
    sessionStorage.removeItem('cf_after_login');
    location.hash = back;
  }

  function authShell(title, inner) {
    view.innerHTML = `<div class="wrap"><div class="auth"><h1>${title}</h1>${inner}</div></div>`;
  }

  function showLogin() {
    setNav('account');
    if (S.user) { location.hash = '#/account'; return; }
    authShell('Log in', `<form id="f" class="panel shadow">
      <label class="field"><span>Email or mobile</span><input name="login" autocomplete="username" required></label>
      <label class="field"><span>Password</span><input name="password" type="password" autocomplete="current-password" required></label>
      <button class="btn block" type="submit">Log in</button>
      <div class="row small" style="justify-content:space-between;margin-top:14px"><a href="#/register">Create account</a><a href="#/forgot">Forgot password?</a></div>
    </form>`);
    $('#f').onsubmit = (e) => { e.preventDefault(); busy(e.submitter, async () => { const r = await api('auth/login', { body: formData(e.target) }); await onUser(r.user); afterLogin(); }); };
  }

  function showRegister() {
    setNav('account');
    if (S.user) { location.hash = '#/account'; return; }
    authShell('Join Cafresco', `<p class="muted">Order online, track your order and earn points on every online purchase.</p>
      <form id="f" class="panel shadow">
      <label class="field"><span>Full name</span><input name="name" autocomplete="name" required maxlength="120"></label>
      <label class="field"><span>Email</span><input name="email" type="email" autocomplete="email" required></label>
      <label class="field"><span>Mobile number</span><input name="mobile" type="tel" inputmode="tel" autocomplete="tel" placeholder="09XX XXX XXXX" required></label>
      <label class="field"><span>Password (8+ characters)</span><input name="password" type="password" autocomplete="new-password" minlength="8" required></label>
      <label class="row small" style="margin-bottom:14px;flex-wrap:nowrap"><input type="checkbox" required> I agree that Cafresco stores my name, email and mobile number to process my orders.</label>
      <button class="btn block clay" type="submit">Create account</button>
      <div class="small" style="margin-top:14px">Already a member? <a href="#/login">Log in</a></div>
    </form>`);
    $('#f').onsubmit = (e) => { e.preventDefault(); busy(e.submitter, async () => { const r = await api('auth/register', { body: formData(e.target) }); await onUser(r.user); toast('Account created! Check your email for the verification link.'); afterLogin(); }); };
  }

  function showForgot() {
    authShell('Reset password', `<form id="f" class="panel shadow"><label class="field"><span>Email</span><input name="email" type="email" required></label>
      <button class="btn block" type="submit">Send reset link</button></form>`);
    $('#f').onsubmit = (e) => { e.preventDefault(); busy(e.submitter, async () => { await api('auth/forgot', { body: formData(e.target) }); toast('If that email has an account, a reset link is on its way.'); location.hash = '#/login'; }); };
  }

  function showReset(token) {
    authShell('New password', `<form id="f" class="panel shadow"><label class="field"><span>New password (8+ characters)</span><input name="password" type="password" minlength="8" required autocomplete="new-password"></label>
      <button class="btn block" type="submit">Save password</button></form>`);
    $('#f').onsubmit = (e) => { e.preventDefault(); busy(e.submitter, async () => { await api('auth/reset', { body: { token, password: e.target.password.value } }); toast('Password updated. Please log in.'); location.hash = '#/login'; }); };
  }

  async function verifyEmail(token) {
    view.innerHTML = '<div class="spinner"></div>';
    try {
      await api('auth/verify-email', { body: { token } });
      const me = await api('auth/me'); await onUser(me.user);
      authShell('Email verified ✓', `<div class="panel shadow"><p>Thanks! Your email is confirmed.</p><a class="btn block" href="#/">Start ordering</a></div>`);
    } catch (e) {
      authShell('Link expired', `<div class="notice bad">${esc(e.message)}</div><a class="btn" href="#/account">Go to account</a>`);
    }
  }

  async function resendEmail(btn) {
    await busy(btn, async () => { await api('auth/resend-email', { body: {} }); toast('Verification email sent.'); });
  }

  async function showAccount() {
    setNav('account');
    if (!S.user) return showLogin();
    const u = S.user;
    view.innerHTML = '<div class="spinner"></div>';
    const pts = await api('points').catch(() => ({ balance: u.points, history: [] }));
    view.innerHTML = `<div class="wrap" style="padding-top:24px;max-width:760px">
      <h1>Hi, ${esc(u.name.split(' ')[0])}</h1>
      <div class="kpis">
        <div class="kpi concrete"><span class="label">Points</span><b>${pts.balance}</b><span class="small muted">worth ${peso(pts.balance * S.cfg.peso_per_point)}</span></div>
        <div class="kpi"><span class="label">Earn rate</span><b>${Math.round(S.cfg.points_per_peso * 100)}</b><span class="small muted">points per ₱100 spent online</span></div>
      </div>
      <div class="panel"><h2>Verification</h2>
        <div class="line"><div class="grow"><b>Email</b><div class="small muted">${esc(u.email)}</div></div>
          ${u.email_verified ? '<span class="tag ok">Verified</span>' : '<button class="btn sm" data-resend>Resend link</button>'}</div>
        <div class="line"><div class="grow"><b>Mobile</b><div class="small muted">${esc(u.mobile)}</div></div>
          ${u.mobile_verified ? '<span class="tag ok">Verified</span>' : S.cfg.sms_enabled ? '<button class="btn sm" id="otpBtn">Send code</button>' : '<span class="tag">Verified by the café on your first order</span>'}</div>
        <form id="otpForm" class="row hidden" style="margin-top:12px;flex-wrap:nowrap"><input name="code" inputmode="numeric" maxlength="6" placeholder="6-digit code" class="mono"><button class="btn" type="submit">Verify</button></form>
      </div>
      <div class="panel"><h2>Points history</h2>${pts.history.length ? `<div class="lines">${pts.history.map((h) => `<div class="line"><div class="grow">${esc(h.reason)}<div class="small muted">${fmtDate(h.created_at)}</div></div><div class="amt">${h.delta > 0 ? '+' : ''}${h.delta}</div></div>`).join('')}</div>` : '<p class="muted">Complete an online order to start earning.</p>'}</div>
      <div class="panel"><h2>Profile</h2><form id="pf">
        <label class="field"><span>Name</span><input name="name" value="${esc(u.name)}" required></label>
        <div class="grid2"><label class="field"><span>Current password</span><input name="current_password" type="password" autocomplete="current-password"></label>
        <label class="field"><span>New password</span><input name="new_password" type="password" minlength="8" autocomplete="new-password"></label></div>
        <button class="btn ghost" type="submit">Save</button></form></div>
      <div class="row">${['staff', 'admin'].includes(u.role) ? '<a class="btn" href="admin/">Open admin</a>' : ''}<button class="btn danger" id="logout">Log out</button></div>
    </div>`;
    $$('[data-resend]').forEach((b) => b.onclick = () => resendEmail(b));
    const ob = $('#otpBtn');
    if (ob) ob.onclick = () => busy(ob, async () => { await api('auth/send-mobile-otp', { body: {} }); toast('Code sent by SMS.'); $('#otpForm').classList.remove('hidden'); });
    $('#otpForm').onsubmit = (e) => { e.preventDefault(); busy(e.submitter, async () => { const r = await api('auth/verify-mobile', { body: formData(e.target) }); await onUser(r.user); toast('Mobile verified!'); showAccount(); }); };
    $('#pf').onsubmit = (e) => { e.preventDefault(); busy(e.submitter, async () => { const r = await api('auth/profile', { body: formData(e.target) }); await onUser(r.user); toast('Saved'); }); };
    $('#logout').onclick = () => busy($('#logout'), async () => { await api('auth/logout', { body: {} }); S.user = null; location.hash = '#/'; });
  }

  // ---------- Notifications (polling; free, no push service needed) ----------
  let notifTimer = null;
  async function pollNotifications() {
    if (!S.user) return;
    try {
      const r = await api('notifications?since=' + S.notifSince);
      const fresh = r.items.filter((n) => n.id > S.notifSince);
      if (S.notifSince && fresh.length) {
        fresh.reverse().forEach((n) => { toast(n.title + (n.body ? ' — ' + n.body : '')); systemNotify(n.title, n.body || ''); });
        api('auth/me').then((m) => { S.user = m.user; }).catch(() => {});
      }
      if (r.items.length) S.notifSince = Math.max(S.notifSince, ...r.items.map((n) => n.id));
      else if (!S.notifSince) S.notifSince = 1;
    } catch (e) { /* offline */ }
  }

  async function onUser(u) {
    S.user = u;
    if (u && u.role === 'rider') { location.href = 'rider/'; return; }
    clearInterval(notifTimer);
    if (u) { S.notifSince = 0; await pollNotifications(); notifTimer = setInterval(pollNotifications, 20000); }
  }

  // ---------- Boot ----------
  async function boot() {
    Cart.load();
    try {
      const [cfg, me] = await Promise.all([api('config'), api('auth/me')]);
      S.cfg = cfg;
      await onUser(me.user);
    } catch (e) {
      view.innerHTML = `<div class="wrap"><div class="notice bad" style="margin-top:24px">${esc(e.message)}</div></div>`;
      return;
    }
    router([
      [/^#\/?$/, showMenu],
      [/^#\/cart$/, showCart],
      [/^#\/orders$/, showOrders],
      [/^#\/orders\/(\d+)$/, showOrder],
      [/^#\/account$/, showAccount],
      [/^#\/login$/, showLogin],
      [/^#\/register$/, showRegister],
      [/^#\/forgot$/, showForgot],
      [/^#\/reset\/([\w]+)$/, showReset],
      [/^#\/verify-email\/([\w]+)$/, verifyEmail],
    ], showMenu);
  }
  boot();
})();
