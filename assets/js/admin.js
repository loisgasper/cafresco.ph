/* Cafresco admin / POS-style back office */
(function () {
  'use strict';
  const { api, esc, peso, $, $$, toast, modal, busy, formData, router, beep, systemNotify, fmtDate } = CF;
  const root = $('#root');
  const S = { user: null, lastFeed: 0, catalog: null };

  const STATUS = {
    pending_payment: 'Waiting for GCash', payment_submitted: 'Check payment', pending_confirmation: 'Confirm order',
    confirmed: 'Confirmed', preparing: 'Preparing', ready: 'Ready', out_for_delivery: 'With rider', completed: 'Completed', cancelled: 'Cancelled', rejected: 'Rejected',
  };
  const ACTION_LABEL = {
    confirmed: 'Confirm', rejected: 'Reject', pending_payment: 'Ask to re-upload proof', cancelled: 'Cancel order',
    preparing: 'Start preparing', ready: 'Mark ready', out_for_delivery: 'Handed to rider', completed: 'Complete',
  };
  const FF = { pickup: 'Pickup', own_rider: 'Own rider', partner_rider: 'Partner rider' };
  const tag = (s) => `<span class="tag ${{ payment_submitted: 'clay', pending_confirmation: 'clay', completed: 'ok', cancelled: 'bad', rejected: 'bad', ready: 'dark' }[s] || ''}">${esc(STATUS[s] || s)}</span>`;

  // ---------- Shell ----------
  const NAV = [
    ['live', 'Live orders'], ['orders', 'Order history'], ['menu', 'Products'], ['addons', 'Add-ons'], ['discounts', 'Discounts'],
    ['customers', 'Customers'], ['riders', 'Riders'], ['reports', 'Reports'], ['settings', 'Settings'],
  ];
  function shell() {
    root.innerHTML = `<div class="shell">
      <aside class="side" id="side">
        <a class="brand" href="admin/#/">Cafresco<small>Back office</small></a>
        ${NAV.map(([k, l]) => `<a class="nav" href="admin/#/${k === 'live' ? '' : k}" data-nav="${k}">${l}${k === 'live' ? ' <span class="badge hidden" id="needBadge">0</span>' : ''}</a>`).join('')}
        <div style="padding:18px;border-top:2px solid var(--ink);margin-top:12px">
          <label class="row small" style="margin-bottom:12px"><input type="checkbox" id="storeOpen"> Accepting online orders</label>
          <div class="small muted">${esc(S.user.name)} · ${esc(S.user.role)}</div>
          <div class="row" style="margin-top:8px"><a class="btn sm ghost" href="./">Shop</a><button class="btn sm ghost" id="logout">Log out</button></div>
        </div>
      </aside>
      <div>
        <div class="adminbar"><button class="iconbtn" id="burger" aria-label="Menu">☰</button><b class="brand" style="font-size:.9rem">Cafresco</b><span class="badge hidden" id="needBadge2">0</span></div>
        <main id="main"></main>
      </div></div>`;
    $('#burger').onclick = () => $('#side').classList.toggle('open');
    $('#side').addEventListener('click', (e) => { if (e.target.closest('a.nav')) $('#side').classList.remove('open'); });
    $('#logout').onclick = async () => { await api('auth/logout', { body: {} }); location.reload(); };
    api('admin/dashboard').then((d) => { $('#storeOpen').checked = d.store_open; });
    $('#storeOpen').onchange = (e) => busy(null, async () => {
      await api('admin/settings', { body: { store_open: e.target.checked ? '1' : '0' } });
      toast(e.target.checked ? 'Store is open for online orders' : 'Online ordering paused');
    });
  }
  const main = () => $('#main');
  function setNav(k) { $$('[data-nav]').forEach((a) => a.classList.toggle('active', a.dataset.nav === k)); }

  // ---------- Live feed (polling) ----------
  async function pollFeed() {
    try {
      const f = await api('admin/feed?since=' + S.lastFeed);
      if (S.lastFeed && f.items.length) {
        beep();
        f.items.forEach((n) => { toast(n.title + (n.body ? ' — ' + n.body : '')); systemNotify(n.title, n.body || ''); });
        if (location.hash === '' || location.hash === '#/') showLive();
      }
      S.lastFeed = f.last_id;
      ['#needBadge', '#needBadge2'].forEach((id) => { const b = $(id); if (b) { b.textContent = f.needs_action; b.classList.toggle('hidden', !f.needs_action); } });
      document.title = (f.needs_action ? `(${f.needs_action}) ` : '') + 'Cafresco · Admin';
    } catch (e) { /* offline */ }
  }

  // ---------- Live orders board ----------
  async function showLive() {
    setNav('live');
    const { orders } = await api('admin/orders?status=open');
    const cols = [
      ['Needs action', ['payment_submitted', 'pending_confirmation']],
      ['Awaiting GCash', ['pending_payment']],
      ['In the kitchen', ['confirmed', 'preparing']],
      ['Ready / with rider', ['ready', 'out_for_delivery']],
    ];
    main().innerHTML = `<div class="toolbar"><h1>Live orders</h1>
      ${'Notification' in window && Notification.permission !== 'granted' ? '<button class="btn sm ghost" id="enableNotif">Enable alerts</button>' : ''}
      <button class="btn sm" id="refresh">Refresh</button></div>
      <div class="board">${cols.map(([title, sts]) => {
        const list = orders.filter((o) => sts.includes(o.status));
        return `<section><div class="label" style="margin-bottom:8px">${title} · ${list.length}</div>
          <div style="display:flex;flex-direction:column;gap:10px">${list.map(orderCard).join('') || '<div class="empty small">Nothing here</div>'}</div></section>`;
      }).join('')}</div>`;
    $('#refresh').onclick = showLive;
    const en = $('#enableNotif');
    if (en) en.onclick = async () => { await Notification.requestPermission(); beep(); showLive(); };
    $$('[data-oid]').forEach((c) => c.onclick = () => openOrder(Number(c.dataset.oid)));
  }

  function orderCard(o) {
    const alert = ['payment_submitted', 'pending_confirmation'].includes(o.status);
    return `<div class="ocard${alert ? ' alert' : ''}" data-oid="${o.id}">
      <div class="row" style="justify-content:space-between"><b class="mono">${esc(o.code)}</b>${tag(o.status)}</div>
      <div><b>${esc(o.customer_name)}</b> <span class="small muted">${esc(o.customer_mobile)}</span></div>
      <div class="small">${esc(o.summary || '')}</div>
      <div class="row small" style="justify-content:space-between"><span class="tag">${FF[o.fulfillment]}</span><span class="tag">${o.payment_method === 'gcash' ? 'GCash' : 'Pay on pickup'}</span><b class="mono">${peso(o.total)}</b></div>
      <div class="small muted">${fmtDate(o.created_at)}${o.pickup_time ? ' · for ' + fmtDate(o.pickup_time) : ''}${o.fulfillment === 'partner_rider' ? (o.rider_id ? ' · rider assigned' : ' · no rider yet') : ''}</div>
    </div>`;
  }

  async function openOrder(id) {
    const { order: o } = await api('admin/orders/' + id);
    const m = modal(`Order ${o.code}`, `
      <div class="row" style="justify-content:space-between;margin-bottom:12px">${tag(o.status)}<span class="small muted">${fmtDate(o.created_at)}</span></div>
      <div class="grid2">
        <div class="panel"><div class="label">Customer</div><b>${esc(o.customer_name)}</b>
          <div class="small"><a href="tel:${esc(o.customer_mobile)}">${esc(o.customer_mobile)}</a> ${o.customer_mobile_verified ? '<span class="tag ok">verified</span>' : '<span class="tag warn">mobile unverified</span>'}</div>
          <div class="small muted">${esc(o.customer_email)}</div>
          <div class="small" style="margin-top:6px">${o.customer_completed_orders ? `${o.customer_completed_orders} completed orders` : '<span class="tag clay">First order</span>'}</div></div>
        <div class="panel"><div class="label">Fulfilment</div><b>${FF[o.fulfillment]}</b>${o.pickup_time ? ` · ${fmtDate(o.pickup_time)}` : ' · ASAP'}
          ${o.delivery_address ? `<div class="small">${esc(o.delivery_address)}</div>` : ''}
          ${o.own_rider_info ? `<div class="small">Rider: ${esc(o.own_rider_info)}</div>` : ''}
          ${o.rider ? `<div class="small">Partner: <b>${esc(o.rider.name)}</b> (${esc(o.rider.company)}) ${esc(o.rider.plate_no || '')} · ${esc(o.rider.mobile)}</div>` : ''}
          ${o.customer_note ? `<div class="notice small" style="margin:8px 0 0">“${esc(o.customer_note)}”</div>` : ''}</div>
      </div>
      <div class="lines">${o.items.map((i) => `<div class="line"><div class="grow"><b>${i.qty}× ${esc(i.name)}</b>${i.variant_name ? ' · ' + esc(i.variant_name) : ''}
        ${i.addons.length ? `<div class="small muted">+ ${esc(i.addons.map((a) => a.name).join(', '))}</div>` : ''}${i.note ? `<div class="small">“${esc(i.note)}”</div>` : ''}</div><div class="amt">${peso(i.line_total)}</div></div>`).join('')}</div>
      <div class="totals" style="margin:10px 0 16px"><div><span>Subtotal</span><span>${peso(o.subtotal)}</span></div>
        ${o.discount_total ? `<div><span>Discount</span><span>−${peso(o.discount_total)}</span></div>` : ''}
        ${o.points_value ? `<div><span>Points (${o.points_redeemed})</span><span>−${peso(o.points_value)}</span></div>` : ''}
        <div class="grand"><span>${o.payment_method === 'gcash' ? 'GCash total' : 'Collect on pickup'}</span><span>${peso(o.total)}</span></div></div>
      ${o.payment_method === 'gcash' ? `<div class="panel"><div class="label">GCash payment</div>
        ${o.gcash_ref ? `<div>Ref <b class="mono">${esc(o.gcash_ref)}</b> ${o.duplicate_ref ? '<span class="tag bad">Ref used on another order!</span>' : ''}</div><div class="small">Sender: ${esc(o.gcash_sender || '—')}</div>` : '<div class="muted small">No proof uploaded yet.</div>'}
        ${o.has_proof ? `<a href="api/files/proof/${o.id}" target="_blank" rel="noopener"><img src="api/files/proof/${o.id}" alt="Payment proof" style="max-height:360px;border:2px solid var(--ink);margin-top:10px"></a>
          <p class="small muted" style="margin-top:8px">Check your GCash app: amount <b>${peso(o.total)}</b> and the reference number must match before confirming.</p>` : ''}</div>` : ''}
      <div class="panel"><div class="label">Staff note (private)</div><div class="row" style="flex-wrap:nowrap"><input id="snote" value="${esc(o.staff_note || '')}" maxlength="500"><button class="btn sm ghost" id="saveNote" style="min-height:46px">Save</button></div></div>
      <details><summary class="small">History</summary><ul class="timeline" style="margin-top:10px">${o.events.map((e) => `<li><b>${esc(STATUS[e.status] || e.status)}</b> <span class="small muted">${fmtDate(e.created_at)}</span>${e.note ? `<div class="small muted">${esc(e.note)}</div>` : ''}</li>`).join('')}</ul></details>
      ${o.next_statuses.length ? `<div style="margin-top:16px;border-top:2px solid var(--ink);padding-top:16px">
        ${o.next_statuses.includes('confirmed') && !o.customer_mobile_verified ? '<label class="row small" style="margin-bottom:10px"><input type="checkbox" id="vm"> I called/messaged the customer, mark their mobile as verified</label>' : ''}
        <input id="actNote" placeholder="Note to customer (optional, e.g. reason for rejecting)" style="margin-bottom:10px">
        <div class="row">${o.next_statuses.map((s) => `<button class="btn ${['rejected', 'cancelled'].includes(s) ? 'danger' : s === 'pending_payment' ? 'ghost' : 'clay'}" data-to="${s}">${ACTION_LABEL[s] || s}</button>`).join('')}</div></div>` : ''}
    `, { wide: true });
    $('#saveNote', m.el).onclick = (e) => busy(e.target, async () => { await api(`admin/orders/${id}/note`, { body: { staff_note: $('#snote', m.el).value } }); toast('Note saved'); });
    $$('[data-to]', m.el).forEach((b) => b.onclick = () => {
      const to = b.dataset.to;
      if (['rejected', 'cancelled'].includes(to) && !confirm(`${ACTION_LABEL[to]}? The customer will be notified and any points refunded.`)) return;
      busy(b, async () => {
        await api(`admin/orders/${id}/status`, { body: { status: to, note: $('#actNote', m.el).value, verify_mobile: $('#vm', m.el)?.checked || false } });
        toast(`${o.code}: ${STATUS[to]}`);
        m.close();
        route();
      });
    });
  }

  // ---------- Order history ----------
  async function showOrders() {
    setNav('orders');
    const p = new URLSearchParams(location.hash.split('?')[1] || '');
    const st = p.get('status') || 'all', q = p.get('q') || '', date = p.get('date') || '';
    const { orders } = await api(`admin/orders?status=${encodeURIComponent(st)}&q=${encodeURIComponent(q)}&date=${encodeURIComponent(date)}`);
    main().innerHTML = `<div class="toolbar"><h1>Orders</h1>
      <form id="of" class="row"><input name="q" placeholder="Code, name, mobile, GCash ref" value="${esc(q)}">
      <select name="status"><option value="all">All statuses</option><option value="open" ${st === 'open' ? 'selected' : ''}>Open</option>${Object.keys(STATUS).map((s) => `<option value="${s}" ${st === s ? 'selected' : ''}>${STATUS[s]}</option>`).join('')}</select>
      <input type="date" name="date" value="${esc(date)}"><button class="btn sm" style="min-height:46px">Filter</button></form></div>
      <div class="tablewrap"><table class="t"><thead><tr><th>Order</th><th>Customer</th><th>Items</th><th>Type</th><th>Status</th><th>Total</th></tr></thead><tbody>
      ${orders.map((o) => `<tr class="click" data-oid="${o.id}"><td><b class="mono">${esc(o.code)}</b><div class="small muted">${fmtDate(o.created_at)}</div></td>
        <td>${esc(o.customer_name)}<div class="small muted">${esc(o.customer_mobile)}</div></td><td class="small">${esc(o.summary || '')}</td>
        <td class="small">${FF[o.fulfillment]}<br>${o.payment_method === 'gcash' ? 'GCash' : 'On pickup'}</td><td>${tag(o.status)}</td><td class="mono">${peso(o.total)}</td></tr>`).join('') || '<tr><td colspan="6" class="muted">No orders found.</td></tr>'}
      </tbody></table></div>`;
    $('#of').onsubmit = (e) => { e.preventDefault(); const f = formData(e.target); location.hash = '#/orders?' + new URLSearchParams(f).toString(); };
    $$('[data-oid]').forEach((r) => r.onclick = () => openOrder(Number(r.dataset.oid)));
  }

  // ---------- Products ----------
  async function loadCatalog() { S.catalog = await api('admin/catalog'); return S.catalog; }

  async function showMenu() {
    setNav('menu');
    const c = await loadCatalog();
    const catName = (id) => c.categories.find((x) => Number(x.id) === Number(id))?.name || '—';
    main().innerHTML = `<div class="toolbar"><h1>Products</h1>
      <button class="btn sm ghost" id="cats">Categories</button>
      ${S.user.role === 'admin' ? '<button class="btn sm ghost" id="lv">Import from Loyverse</button>' : ''}
      <button class="btn sm clay" id="newP">+ New product</button></div>
      <input id="filter" placeholder="Search products" style="margin-bottom:14px;max-width:360px">
      <div class="tablewrap"><table class="t"><thead><tr><th>Product</th><th>Category</th><th>Price</th><th>Options</th><th>Status</th><th></th></tr></thead><tbody>
      ${c.products.map((p) => `<tr data-name="${esc(p.name.toLowerCase())}"><td><b>${esc(p.name)}</b>${p.loyverse_id ? ' <span class="tag">Loyverse</span>' : ''}</td><td>${esc(catName(p.category_id))}</td>
        <td class="mono">${p.variants.length ? p.variants.map((v) => `${esc(v.name)} ${peso(v.price)}`).join('<br>') : peso(p.base_price)}</td>
        <td class="small">${p.addon_group_ids.map((g) => esc(c.addon_groups.find((x) => Number(x.id) === g)?.name || '')).join(', ') || '—'}</td>
        <td><button class="btn sm ${Number(p.active) ? 'ghost' : 'danger'}" data-toggle="${p.id}" data-field="active">${Number(p.active) ? 'Visible' : 'Hidden'}</button>
          <button class="btn sm ${Number(p.sold_out) ? 'danger' : 'ghost'}" data-toggle="${p.id}" data-field="sold_out">${Number(p.sold_out) ? 'Sold out' : 'In stock'}</button></td>
        <td><button class="btn sm" data-edit="${p.id}">Edit</button></td></tr>`).join('') || '<tr><td colspan="6" class="muted">No products yet. Add one or import from Loyverse.</td></tr>'}
      </tbody></table></div>`;
    $('#filter').oninput = (e) => $$('tr[data-name]').forEach((r) => { r.style.display = r.dataset.name.includes(e.target.value.toLowerCase()) ? '' : 'none'; });
    $('#newP').onclick = () => editProduct(null);
    $('#cats').onclick = editCategories;
    const lv = $('#lv');
    if (lv) lv.onclick = () => { if (confirm('Import categories, items, variants and modifiers from Loyverse? Existing imported items are updated; your own products are kept.')) busy(lv, async () => { const r = await api('admin/loyverse/sync', { body: {} }); toast(`Imported ${r.stats.products} products, ${r.stats.variants} variants, ${r.stats.addon_groups} modifier groups.`); showMenu(); }); };
    $$('[data-edit]').forEach((b) => b.onclick = () => editProduct(c.products.find((p) => Number(p.id) === Number(b.dataset.edit))));
    $$('[data-toggle]').forEach((b) => b.onclick = () => busy(b, async () => { await api(`admin/products/${b.dataset.toggle}/toggle`, { body: { field: b.dataset.field } }); showMenu(); }));
  }

  function subRow(item = {}, i) {
    return `<div class="subrow" data-row><input placeholder="Name" value="${esc(item.name || '')}" data-k="name"><input type="number" step="0.01" min="0" placeholder="Price" value="${item.price ?? ''}" data-k="price">
      <label class="row small" style="gap:4px"><input type="checkbox" data-k="active" ${item.active === undefined || Number(item.active) ? 'checked' : ''}>On</label>
      <button type="button" class="iconbtn" data-del aria-label="Remove">✕</button><input type="hidden" data-k="id" value="${item.id || ''}"></div>`;
  }
  function wireRows(container, addBtn) {
    container.addEventListener('click', (e) => { if (e.target.closest('[data-del]')) e.target.closest('[data-row]').remove(); });
    addBtn.onclick = () => container.insertAdjacentHTML('beforeend', subRow());
  }
  const readRows = (container) => $$('[data-row]', container).map((r) => ({
    id: Number($('[data-k=id]', r).value) || null, name: $('[data-k=name]', r).value, price: Number($('[data-k=price]', r).value || 0), active: $('[data-k=active]', r).checked,
  })).filter((r) => r.name.trim());

  function editProduct(p) {
    const c = S.catalog;
    p = p || { name: '', base_price: '', variants: [], addon_group_ids: [], active: 1, sold_out: 0, sort: 0 };
    const m = modal(p.id ? 'Edit product' : 'New product', `<form id="pf">
      <div class="grid2"><label class="field"><span>Name</span><input name="name" value="${esc(p.name)}" required maxlength="120"></label>
      <label class="field"><span>Category</span><select name="category_id"><option value="">—</option>${c.categories.map((x) => `<option value="${x.id}" ${Number(x.id) === Number(p.category_id) ? 'selected' : ''}>${esc(x.name)}</option>`).join('')}</select></label></div>
      <label class="field"><span>Description</span><textarea name="description" maxlength="500">${esc(p.description || '')}</textarea></label>
      <div class="grid2"><label class="field"><span>Base price (used when there are no variants)</span><input name="base_price" type="number" step="0.01" min="0" value="${p.base_price}"></label>
      <label class="field"><span>Sort order</span><input name="sort" type="number" value="${p.sort || 0}"></label></div>
      <label class="field"><span>Photo</span><div class="row" style="flex-wrap:nowrap"><input name="image_url" value="${esc(p.image_url || '')}" placeholder="Image URL or upload →"><input type="file" id="img" accept="image/*" style="max-width:130px"></div></label>
      <div class="label" style="margin:16px 0 8px">Variants (sizes, temperature…) · customer must pick one</div>
      <div id="vars">${p.variants.map(subRow).join('')}</div><button type="button" class="btn sm ghost" id="addVar">+ Variant</button>
      <div class="label" style="margin:16px 0 8px">Add-on groups</div>
      ${c.addon_groups.length ? c.addon_groups.map((g) => `<label class="check"><input type="checkbox" name="g_${g.id}" ${p.addon_group_ids.includes(Number(g.id)) ? 'checked' : ''}> ${esc(g.name)} <span class="price small">${g.addons.length} options</span></label>`).join('') : '<p class="small muted">No add-on groups yet. Create them under Add-ons.</p>'}
      <div class="row" style="margin-top:16px"><label class="row small"><input type="checkbox" name="active" ${Number(p.active) ? 'checked' : ''}> Visible on menu</label>
      <label class="row small"><input type="checkbox" name="sold_out" ${Number(p.sold_out) ? 'checked' : ''}> Sold out</label></div>
    </form>`, { wide: true, footer: `${p.id ? '<button class="btn danger" id="delP">Delete</button>' : ''}<span style="flex:1"></span><button class="btn clay" id="saveP">Save product</button>` });
    wireRows($('#vars', m.el), $('#addVar', m.el));
    $('#img', m.el).onchange = (e) => busy(null, async () => {
      const fd = new FormData(); fd.append('file', e.target.files[0]);
      const r = await api('admin/upload', { body: fd });
      $('[name=image_url]', m.el).value = r.url; toast('Photo uploaded');
    });
    $('#saveP', m.el).onclick = (e) => busy(e.target, async () => {
      const f = $('#pf', m.el); if (!f.reportValidity()) return;
      const d = formData(f);
      const body = {
        id: p.id || null, name: d.name, category_id: d.category_id || null, description: d.description, base_price: Number(d.base_price || 0), sort: Number(d.sort || 0),
        image_url: d.image_url, active: d.active, sold_out: d.sold_out, variants: readRows($('#vars', m.el)),
        addon_group_ids: c.addon_groups.filter((g) => d['g_' + g.id]).map((g) => Number(g.id)),
      };
      await api('admin/products', { body }); toast('Saved'); m.close(); showMenu();
    });
    const del = $('#delP', m.el);
    if (del) del.onclick = () => { if (confirm('Delete this product? Past orders keep their item names.')) busy(del, async () => { await api(`admin/products/${p.id}/delete`, { body: {} }); m.close(); showMenu(); }); };
  }

  function editCategories() {
    const c = S.catalog;
    const m = modal('Categories', `<div id="catList">${c.categories.map((x) => `<form class="row" data-cid="${x.id}" style="margin-bottom:8px;flex-wrap:nowrap">
      <input name="name" value="${esc(x.name)}" required><input name="sort" type="number" value="${x.sort}" style="width:80px"><label class="row small"><input type="checkbox" name="active" ${Number(x.active) ? 'checked' : ''}>On</label>
      <button class="btn sm">Save</button><button type="button" class="iconbtn" data-cdel="${x.id}">✕</button></form>`).join('')}</div>
      <form class="row" id="newCat" style="flex-wrap:nowrap;margin-top:12px"><input name="name" placeholder="New category" required><button class="btn sm clay">Add</button></form>`);
    $$('[data-cid]', m.el).forEach((f) => f.onsubmit = (e) => { e.preventDefault(); busy(null, async () => { await api('admin/categories', { body: { id: Number(f.dataset.cid), ...formData(f) } }); toast('Saved'); await loadCatalog(); }); });
    $$('[data-cdel]', m.el).forEach((b) => b.onclick = () => { if (confirm('Delete category? Products stay, without a category.')) busy(b, async () => { await api(`admin/categories/${b.dataset.cdel}/delete`, { body: {} }); m.close(); await showMenu(); editCategories(); }); });
    $('#newCat', m.el).onsubmit = (e) => { e.preventDefault(); busy(null, async () => { await api('admin/categories', { body: { name: e.target.name.value, active: true } }); m.close(); await showMenu(); editCategories(); }); };
  }

  // ---------- Add-ons ----------
  async function showAddons() {
    setNav('addons');
    const c = await loadCatalog();
    main().innerHTML = `<div class="toolbar"><h1>Add-ons</h1><button class="btn sm clay" id="newG">+ New group</button></div>
      <p class="muted">Groups like “Milk”, “Extra shot” or “Sugar level”. Attach groups to products on the Products page.</p>
      <div class="board">${c.addon_groups.map((g) => `<div class="ocard" data-gid="${g.id}"><b>${esc(g.name)}</b>
        <div class="small muted">Pick ${g.min_select}–${g.max_select}</div>
        <div class="small">${g.addons.map((a) => `${esc(a.name)} ${Number(a.price) ? '+' + peso(a.price) : ''}`).join(' · ') || 'No options yet'}</div></div>`).join('') || '<div class="empty">No add-on groups yet.</div>'}</div>`;
    $('#newG').onclick = () => editGroup(null);
    $$('[data-gid]').forEach((el) => el.onclick = () => editGroup(c.addon_groups.find((g) => Number(g.id) === Number(el.dataset.gid))));
  }

  function editGroup(g) {
    g = g || { name: '', min_select: 0, max_select: 1, addons: [] };
    const m = modal(g.id ? 'Edit add-on group' : 'New add-on group', `<form id="gf">
      <label class="field"><span>Group name</span><input name="name" value="${esc(g.name)}" required placeholder="e.g. Milk options"></label>
      <div class="grid2"><label class="field"><span>Minimum picks (1 = required)</span><input name="min_select" type="number" min="0" value="${g.min_select}"></label>
      <label class="field"><span>Maximum picks (1 = choose one)</span><input name="max_select" type="number" min="1" value="${g.max_select}"></label></div>
      <div class="label" style="margin-bottom:8px">Options</div><div id="opts">${g.addons.map(subRow).join('')}</div><button type="button" class="btn sm ghost" id="addOpt">+ Option</button></form>`,
    { footer: `${g.id ? '<button class="btn danger" id="delG">Delete</button>' : ''}<span style="flex:1"></span><button class="btn clay" id="saveG">Save</button>` });
    wireRows($('#opts', m.el), $('#addOpt', m.el));
    $('#saveG', m.el).onclick = (e) => busy(e.target, async () => {
      const f = $('#gf', m.el); if (!f.reportValidity()) return;
      await api('admin/addon-groups', { body: { id: g.id || null, ...formData(f), addons: readRows($('#opts', m.el)) } });
      toast('Saved'); m.close(); showAddons();
    });
    const del = $('#delG', m.el);
    if (del) del.onclick = () => { if (confirm('Delete this add-on group?')) busy(del, async () => { await api(`admin/addon-groups/${g.id}/delete`, { body: {} }); m.close(); showAddons(); }); };
  }

  // ---------- Discounts ----------
  async function showDiscounts() {
    setNav('discounts');
    const { discounts } = await api('admin/discounts');
    main().innerHTML = `<div class="toolbar"><h1>Discounts</h1><button class="btn sm clay" id="newD">+ New discount</button></div>
      <p class="muted">With a code, customers type it at checkout. Without a code, it applies automatically (the best one wins).</p>
      <div class="tablewrap"><table class="t"><thead><tr><th>Name</th><th>Code</th><th>Value</th><th>Min order</th><th>Period</th><th>Used</th><th>Status</th></tr></thead><tbody>
      ${discounts.map((d) => `<tr class="click" data-did="${d.id}"><td><b>${esc(d.name)}</b></td><td class="mono">${esc(d.code || 'auto')}</td>
        <td>${d.type === 'percent' ? Number(d.value) + '%' : peso(d.value)}</td><td>${Number(d.min_subtotal) ? peso(d.min_subtotal) : '—'}</td>
        <td class="small">${d.starts_at ? fmtDate(d.starts_at) : 'now'} → ${d.ends_at ? fmtDate(d.ends_at) : 'no end'}</td><td>${d.uses}${d.max_uses ? ' / ' + d.max_uses : ''}</td>
        <td>${Number(d.active) ? '<span class="tag ok">Active</span>' : '<span class="tag">Off</span>'}</td></tr>`).join('') || '<tr><td colspan="7" class="muted">No discounts yet.</td></tr>'}</tbody></table></div>`;
    $('#newD').onclick = () => editDiscount(null);
    $$('[data-did]').forEach((r) => r.onclick = () => editDiscount(discounts.find((d) => Number(d.id) === Number(r.dataset.did))));
  }

  function editDiscount(d) {
    d = d || { name: '', code: '', type: 'percent', value: '', min_subtotal: 0, active: 1 };
    const dt = (s) => (s ? s.replace(' ', 'T').slice(0, 16) : '');
    const m = modal(d.id ? 'Edit discount' : 'New discount', `<form id="df">
      <label class="field"><span>Name (shown to customers)</span><input name="name" value="${esc(d.name)}" required placeholder="e.g. Grand opening 10% off"></label>
      <div class="grid2"><label class="field"><span>Code (blank = automatic)</span><input name="code" value="${esc(d.code || '')}" style="text-transform:uppercase"></label>
      <label class="field"><span>Type</span><select name="type"><option value="percent" ${d.type === 'percent' ? 'selected' : ''}>Percent %</option><option value="fixed" ${d.type === 'fixed' ? 'selected' : ''}>Fixed ₱</option></select></label>
      <label class="field"><span>Value</span><input name="value" type="number" step="0.01" min="0" value="${d.value}" required></label>
      <label class="field"><span>Minimum order ₱</span><input name="min_subtotal" type="number" step="0.01" min="0" value="${d.min_subtotal}"></label>
      <label class="field"><span>Starts</span><input name="starts_at" type="datetime-local" value="${dt(d.starts_at)}"></label>
      <label class="field"><span>Ends</span><input name="ends_at" type="datetime-local" value="${dt(d.ends_at)}"></label>
      <label class="field"><span>Max total uses</span><input name="max_uses" type="number" min="0" value="${d.max_uses ?? ''}"></label>
      <label class="field"><span>Max uses per customer</span><input name="per_user_limit" type="number" min="0" value="${d.per_user_limit ?? ''}"></label></div>
      <label class="row small"><input type="checkbox" name="active" ${Number(d.active) ? 'checked' : ''}> Active</label></form>`,
    { footer: `${d.id ? '<button class="btn danger" id="delD">Delete</button>' : ''}<span style="flex:1"></span><button class="btn clay" id="saveD">Save</button>` });
    $('#saveD', m.el).onclick = (e) => busy(e.target, async () => { const f = $('#df', m.el); if (!f.reportValidity()) return; await api('admin/discounts', { body: { id: d.id || null, ...formData(f) } }); m.close(); showDiscounts(); });
    const del = $('#delD', m.el);
    if (del) del.onclick = () => { if (confirm('Delete discount?')) busy(del, async () => { await api(`admin/discounts/${d.id}/delete`, { body: {} }); m.close(); showDiscounts(); }); };
  }

  // ---------- Customers ----------
  async function showCustomers() {
    setNav('customers');
    const q = new URLSearchParams(location.hash.split('?')[1] || '').get('q') || '';
    const { customers } = await api('admin/customers?q=' + encodeURIComponent(q));
    main().innerHTML = `<div class="toolbar"><h1>Customers</h1><form id="cf" class="row"><input name="q" value="${esc(q)}" placeholder="Name, email, mobile"><button class="btn sm" style="min-height:46px">Search</button></form></div>
      <div class="tablewrap"><table class="t"><thead><tr><th>Customer</th><th>Verified</th><th>Orders</th><th>Spend</th><th>Points</th><th>Status</th></tr></thead><tbody>
      ${customers.map((c) => `<tr class="click" data-cid="${c.id}"><td><b>${esc(c.name)}</b><div class="small muted">${esc(c.email)} · ${esc(c.mobile)}</div>${c.staff_note ? `<div class="small">📝 ${esc(c.staff_note)}</div>` : ''}</td>
        <td class="small">${c.email_verified_at ? '✓ email' : '✗ email'}<br>${c.mobile_verified_at ? '✓ mobile' : '✗ mobile'}</td><td>${c.completed_orders}</td><td class="mono">${peso(c.lifetime_spend)}</td>
        <td>${c.points_balance}</td><td>${c.status === 'blocked' ? '<span class="tag bad">Blocked</span>' : '<span class="tag ok">Active</span>'}</td></tr>`).join('') || '<tr><td colspan="6" class="muted">No customers yet.</td></tr>'}</tbody></table></div>`;
    $('#cf').onsubmit = (e) => { e.preventDefault(); location.hash = '#/customers?q=' + encodeURIComponent(e.target.q.value); };
    $$('[data-cid]').forEach((r) => r.onclick = () => editCustomer(customers.find((c) => Number(c.id) === Number(r.dataset.cid))));
  }

  function editCustomer(c) {
    const m = modal(c.name, `<p class="small muted">${esc(c.email)} · ${esc(c.mobile)} · joined ${fmtDate(c.created_at)}</p>
      <form id="cuf">
        <label class="field"><span>Staff note (private)</span><input name="staff_note" value="${esc(c.staff_note || '')}" placeholder="e.g. regular, always on time"></label>
        <div class="grid2"><label class="field"><span>Adjust points (+/−)</span><input name="points_adjust" type="number" value="0"></label>
        <label class="field"><span>Reason</span><input name="points_reason" placeholder="e.g. goodwill"></label></div>
        ${!c.mobile_verified_at ? '<label class="row small" style="margin-bottom:8px"><input type="checkbox" name="verify_mobile"> Mark mobile as verified</label>' : ''}
        ${!c.email_verified_at ? '<label class="row small" style="margin-bottom:8px"><input type="checkbox" name="verify_email"> Mark email as verified</label>' : ''}
        <label class="field"><span>Account status</span><select name="status"><option value="active">Active</option><option value="blocked" ${c.status === 'blocked' ? 'selected' : ''}>Blocked (cannot log in)</option></select></label>
      </form>`, { footer: '<span style="flex:1"></span><button class="btn clay" id="saveC">Save</button>' });
    $('#saveC', m.el).onclick = (e) => busy(e.target, async () => { await api(`admin/customers/${c.id}`, { body: formData($('#cuf', m.el)) }); toast('Saved'); m.close(); showCustomers(); });
  }

  // ---------- Riders ----------
  async function showRiders() {
    setNav('riders');
    const { companies, riders } = await api('admin/riders');
    const cname = (id) => companies.find((c) => Number(c.id) === Number(id))?.name || '—';
    main().innerHTML = `<div class="toolbar"><h1>Riders</h1><button class="btn sm clay" id="newCo">+ Rider company</button></div>
      <p class="muted">Riders sign up at <b class="mono">${location.origin}/rider/</b> and pick their company. Approve them here. Online riders get alerts for “Find a rider” orders.</p>
      <div class="board" style="margin-bottom:24px">${companies.map((c) => `<div class="ocard" data-coid="${c.id}"><b>${esc(c.name)}</b><div class="small muted">${esc(c.contact || '')}</div>
        <div class="small">${c.rider_count} approved riders ${Number(c.active) ? '' : '<span class="tag">inactive</span>'}</div></div>`).join('') || '<div class="empty">Add the rider companies in your city first.</div>'}</div>
      <div class="tablewrap"><table class="t"><thead><tr><th>Rider</th><th>Company</th><th>Vehicle</th><th>Status</th><th>Deliveries</th><th></th></tr></thead><tbody>
      ${riders.map((r) => `<tr><td><b>${esc(r.name)}</b><div class="small muted">${esc(r.mobile)} · ${esc(r.email)}</div></td><td>${esc(cname(r.company_id))}</td>
        <td class="small">${esc(r.vehicle || '')} ${esc(r.plate_no || '')}</td>
        <td>${r.approval === 'approved' ? (Number(r.is_online) ? '<span class="tag ok">● Online</span>' : '<span class="tag">Offline</span>') : `<span class="tag ${r.approval === 'pending' ? 'clay' : 'bad'}">${r.approval}</span>`}
          ${r.status === 'blocked' ? '<span class="tag bad">blocked</span>' : ''}</td><td>${r.deliveries}</td>
        <td class="row">${r.approval !== 'approved' ? `<button class="btn sm clay" data-ra="${r.id}" data-v="approved">Approve</button>` : ''}
          ${r.approval === 'pending' ? `<button class="btn sm danger" data-ra="${r.id}" data-v="rejected">Reject</button>` : ''}
          ${r.approval === 'approved' ? `<button class="btn sm ghost" data-ra="${r.id}" data-v="pending">Suspend</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="6" class="muted">No riders yet.</td></tr>'}</tbody></table></div>`;
    $('#newCo').onclick = () => editCompany(null);
    $$('[data-coid]').forEach((el) => el.onclick = () => editCompany(companies.find((c) => Number(c.id) === Number(el.dataset.coid))));
    $$('[data-ra]').forEach((b) => b.onclick = () => busy(b, async () => { await api(`admin/riders/${b.dataset.ra}`, { body: { approval: b.dataset.v } }); toast('Rider updated'); showRiders(); }));
  }

  function editCompany(c) {
    c = c || { name: '', contact: '', active: 1 };
    const m = modal(c.id ? 'Edit rider company' : 'New rider company', `<form id="cof">
      <label class="field"><span>Company name</span><input name="name" value="${esc(c.name)}" required></label>
      <label class="field"><span>Contact (dispatcher, FB page, number)</span><input name="contact" value="${esc(c.contact || '')}"></label>
      <label class="row small"><input type="checkbox" name="active" ${Number(c.active) ? 'checked' : ''}> Active (riders can choose it)</label></form>`,
    { footer: '<span style="flex:1"></span><button class="btn clay" id="saveCo">Save</button>' });
    $('#saveCo', m.el).onclick = (e) => busy(e.target, async () => { const f = $('#cof', m.el); if (!f.reportValidity()) return; await api('admin/rider-companies', { body: { id: c.id || null, ...formData(f) } }); m.close(); showRiders(); });
  }

  // ---------- Reports ----------
  async function showReports() {
    setNav('reports');
    const d = await api('admin/dashboard');
    const max = Math.max(1, ...d.week.map((w) => Number(w.s)));
    main().innerHTML = `<div class="toolbar"><h1>Reports</h1></div>
      <div class="kpis">
        <div class="kpi concrete"><span class="label">Sales today</span><b>${peso(d.today_sales)}</b><span class="small muted">${d.today_orders} completed orders</span></div>
        <div class="kpi"><span class="label">Open orders</span><b>${Object.values(d.open_counts).reduce((a, b) => a + b, 0)}</b></div>
        <div class="kpi"><span class="label">Riders online</span><b>${d.riders_online}</b>${d.pending_riders ? `<span class="small"><a href="admin/#/riders">${d.pending_riders} waiting approval</a></span>` : ''}</div>
      </div>
      <div class="grid2" style="align-items:start">
        <div class="panel"><h2>Last 7 days</h2>${d.week.length ? d.week.map((w) => `<div style="margin-bottom:8px"><div class="row small" style="justify-content:space-between"><span>${esc(w.d)}</span><span class="mono">${peso(w.s)} · ${w.n}</span></div>
          <div style="height:12px;border:2px solid var(--ink);margin-top:3px"><div style="height:100%;width:${(Number(w.s) / max) * 100}%;background:var(--ink)"></div></div></div>`).join('') : '<p class="muted">No completed orders yet.</p>'}</div>
        <div class="panel"><h2>Top items · 30 days</h2>${d.top_items.length ? `<div class="lines">${d.top_items.map((t, i) => `<div class="line"><div class="grow"><b>${i + 1}. ${esc(t.name)}</b></div><div class="amt">${t.q} sold</div></div>`).join('')}</div>` : '<p class="muted">No data yet.</p>'}</div>
      </div>`;
  }

  // ---------- Settings ----------
  async function showSettings() {
    setNav('settings');
    const { settings: s } = await api('admin/settings');
    const isAdmin = S.user.role === 'admin';
    const f = (k, label, type = 'text', extra = '') => `<label class="field"><span>${label}</span><input name="${k}" type="${type}" value="${esc(s[k] ?? '')}" ${extra} ${isAdmin ? '' : 'disabled'}></label>`;
    const yn = (k, label) => `<label class="field"><span>${label}</span><select name="${k}" ${isAdmin ? '' : 'disabled'}><option value="1" ${s[k] === '1' ? 'selected' : ''}>Yes</option><option value="0" ${s[k] !== '1' ? 'selected' : ''}>No</option></select></label>`;
    main().innerHTML = `<div class="toolbar"><h1>Settings</h1></div>
      ${isAdmin ? '' : '<div class="notice">Only the owner (admin) can change settings.</div>'}
      <form id="sf">
      <div class="panel"><h2>Store</h2><div class="grid2">${f('store_name', 'Store name')}${f('store_hours', 'Hours shown to customers')}${f('store_address', 'Address')}${f('store_phone', 'Phone')}${f('facebook_url', 'Facebook page URL', 'url')}${f('min_prep_minutes', 'Minimum prep time (minutes)', 'number')}</div></div>
      <div class="panel"><h2>GCash</h2><div class="grid2">${f('gcash_name', 'Account name')}${f('gcash_number', 'GCash number')}</div>
        <label class="field"><span>GCash QR image</span><div class="row" style="flex-wrap:nowrap">${f('gcash_qr', '').replace(/<label class="field"><span><\/span>|<\/label>/g, '')}${isAdmin ? '<input type="file" id="qrUp" accept="image/*" style="max-width:130px">' : ''}</div></label>
        ${s.gcash_qr ? `<img src="${esc(s.gcash_qr)}" alt="QR" style="max-width:160px;border:2px solid var(--ink)">` : ''}
        ${f('payment_window_minutes', 'Minutes allowed to upload GCash proof before auto-cancel', 'number', 'min="5"')}</div>
      <div class="panel"><h2>Loyalty points (online orders only)</h2><div class="grid2">
        ${f('points_per_peso', 'Points earned per ₱1 (0.02 = 2 pts per ₱100)', 'number', 'step="0.001" min="0"')}${f('peso_per_point', 'Peso value of 1 point', 'number', 'step="0.01" min="0"')}
        ${f('min_points_redeem', 'Minimum points to redeem', 'number')}${f('max_redeem_percent', 'Max % of order payable by points', 'number', 'min="0" max="100"')}</div></div>
      <div class="panel"><h2>Anti-scam rules</h2><div class="grid2">
        ${f('new_account_order_cap', 'First-order limit ₱ (0 = none)', 'number')}${f('max_open_orders', 'Max open orders per customer', 'number', 'min="1"')}
        ${yn('allow_pay_on_pickup', 'Allow pay on pickup')}${f('pay_on_pickup_min_completed', 'Completed orders needed for pay on pickup', 'number', 'min="0"')}
        ${yn('require_mobile_verification', 'Require SMS-verified mobile to order' + (s._sms_enabled ? '' : ' (needs SMS provider)'))}</div></div>
      <div class="panel"><h2>Loyverse (optional)</h2>${f('loyverse_token', 'Loyverse API access token', 'password', 'autocomplete="off" placeholder="Loyverse Back Office → Integrations → Access tokens"')}
        <p class="small muted">Used only to import your items into this app (Products → Import from Loyverse).</p></div>
      ${isAdmin ? '<button class="btn clay">Save settings</button>' : ''}
      </form>
      ${isAdmin ? '<div class="panel" style="margin-top:24px"><h2>Staff accounts</h2><div id="staff"></div></div>' : ''}`;
    const qr = $('#qrUp');
    if (qr) qr.onchange = (e) => busy(null, async () => { const fd = new FormData(); fd.append('file', e.target.files[0]); const r = await api('admin/upload', { body: fd }); $('[name=gcash_qr]').value = r.url; toast('QR uploaded. Click Save settings.'); });
    $('#sf').onsubmit = (e) => { e.preventDefault(); busy(e.submitter, async () => { await api('admin/settings', { body: formData(e.target) }); toast('Settings saved'); showSettings(); }); };
    if (isAdmin) renderStaff();
  }

  async function renderStaff() {
    const { staff } = await api('admin/staff');
    $('#staff').innerHTML = `<div class="lines">${staff.map((u) => `<div class="line"><div class="grow"><b>${esc(u.name)}</b> <span class="tag">${u.role}</span> ${u.status === 'blocked' ? '<span class="tag bad">blocked</span>' : ''}
      <div class="small muted">${esc(u.email)} · last login ${u.last_login_at ? fmtDate(u.last_login_at) : 'never'}</div></div>
      ${Number(u.id) !== S.user.id ? `<button class="btn sm ghost" data-sb="${u.id}" data-role="${u.role}" data-st="${u.status === 'blocked' ? 'active' : 'blocked'}">${u.status === 'blocked' ? 'Unblock' : 'Block'}</button>` : ''}</div>`).join('')}</div>
      <form id="nsf" class="grid2" style="margin-top:14px"><input name="name" placeholder="Name" required><input name="email" type="email" placeholder="Email" required>
      <input name="mobile" placeholder="Mobile" required><input name="password" type="password" placeholder="Temporary password (8+)" minlength="8" required>
      <select name="role"><option value="staff">Staff (orders, menu)</option><option value="admin">Admin (everything)</option></select><button class="btn sm clay" style="min-height:46px">Add staff</button></form>`;
    $$('[data-sb]').forEach((b) => b.onclick = () => busy(b, async () => { await api('admin/staff', { body: { id: Number(b.dataset.sb), role: b.dataset.role, status: b.dataset.st } }); renderStaff(); }));
    $('#nsf').onsubmit = (e) => { e.preventDefault(); busy(e.submitter, async () => { await api('admin/staff', { body: formData(e.target) }); toast('Staff added'); renderStaff(); }); };
  }

  // ---------- Login ----------
  function showLogin(msg) {
    root.innerHTML = `<div class="wrap"><div class="auth"><div class="brand" style="margin-bottom:24px">Cafresco<small>Back office</small></div>
      ${msg ? `<div class="notice bad">${esc(msg)}</div>` : ''}
      <form id="lf" class="panel shadow"><label class="field"><span>Email or mobile</span><input name="login" required autocomplete="username"></label>
      <label class="field"><span>Password</span><input name="password" type="password" required autocomplete="current-password"></label>
      <button class="btn block">Log in</button></form></div></div>`;
    $('#lf').onsubmit = (e) => { e.preventDefault(); busy(e.submitter, async () => { const r = await api('auth/login', { body: formData(e.target) }); start(r.user); }); };
  }

  let routeFn = () => {};
  const route = () => routeFn();
  function start(user) {
    if (!user) return showLogin();
    if (!['staff', 'admin'].includes(user.role)) return showLogin('This account is not a staff account.');
    S.user = user;
    shell();
    const wrap = (fn) => async (...a) => { try { await fn(...a); } catch (e) { main().innerHTML = `<div class="notice bad">${esc(e.message)}</div>`; } };
    const routes = [
      [/^#\/?$/, wrap(showLive)], [/^#\/orders/, wrap(showOrders)], [/^#\/menu$/, wrap(showMenu)], [/^#\/addons$/, wrap(showAddons)],
      [/^#\/discounts$/, wrap(showDiscounts)], [/^#\/customers/, wrap(showCustomers)], [/^#\/riders$/, wrap(showRiders)],
      [/^#\/reports$/, wrap(showReports)], [/^#\/settings$/, wrap(showSettings)],
    ];
    routeFn = () => { const h = location.hash || '#/'; const r = routes.find(([re]) => re.test(h)); (r ? r[1] : routes[0][1])(); };
    router(routes, wrap(showLive));
    pollFeed();
    setInterval(pollFeed, 10000);
  }

  api('auth/me').then((r) => start(r.user)).catch((e) => showLogin(e.message));
})();
