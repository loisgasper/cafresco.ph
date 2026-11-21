/* Shared helpers for the customer, admin and rider apps. No build step. */
(function () {
  'use strict';
  const BASE = document.querySelector('base')?.getAttribute('href') || '/';

  async function api(path, opts = {}) {
    const init = { method: opts.method || (opts.body ? 'POST' : 'GET'), headers: { 'X-Requested-With': 'cafresco' }, credentials: 'same-origin' };
    if (opts.body instanceof FormData) init.body = opts.body;
    else if (opts.body !== undefined) { init.body = JSON.stringify(opts.body); init.headers['Content-Type'] = 'application/json'; }
    let res;
    try { res = await fetch(BASE + 'api/' + path, init); }
    catch (e) { throw new Error('You seem to be offline. Check your connection.'); }
    let data = {};
    try { data = await res.json(); } catch (e) { /* non-JSON */ }
    if (!res.ok) { const err = new Error(data.error || 'Request failed (' + res.status + ')'); err.status = res.status; throw err; }
    return data;
  }

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const peso = (n) => '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const fmtDate = (s) => { if (!s) return ''; const d = new Date(String(s).replace(' ', 'T')); return d.toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); };
  const initials = (s) => String(s || '').split(/\s+/).map((w) => w[0]).join('').slice(0, 2).toUpperCase();

  function toast(msg, bad = false) {
    let box = $('#toasts');
    if (!box) { box = document.createElement('div'); box.id = 'toasts'; document.body.appendChild(box); }
    const t = document.createElement('div');
    t.className = 'toast' + (bad ? ' bad' : '');
    t.textContent = msg;
    box.appendChild(t);
    setTimeout(() => t.remove(), bad ? 6000 : 3500);
  }

  function modal(title, html, { wide = false, footer = '' } = {}) {
    const ov = document.createElement('div');
    ov.className = 'overlay';
    ov.innerHTML = `<div class="sheet${wide ? ' wide' : ''}" role="dialog" aria-modal="true" aria-label="${esc(title)}">
      <header><h2>${esc(title)}</h2><button class="iconbtn" data-close aria-label="Close">✕</button></header>
      <div class="content">${html}</div>${footer ? `<footer>${footer}</footer>` : ''}</div>`;
    const onKey = (e) => { if (e.key === 'Escape') close(); };
    const close = () => { ov.remove(); document.body.style.overflow = ''; document.removeEventListener('keydown', onKey); };
    ov.addEventListener('click', (e) => { if (e.target === ov || e.target.closest('[data-close]')) close(); });
    document.addEventListener('keydown', onKey);
    document.body.appendChild(ov);
    document.body.style.overflow = 'hidden';
    return { el: ov, close };
  }

  /** Wrap an async click/submit handler: disables the button and shows errors as toasts. */
  async function busy(btn, fn) {
    if (btn) btn.disabled = true;
    try { return await fn(); }
    catch (e) { toast(e.message, true); }
    finally { if (btn) btn.disabled = false; }
  }

  function formData(form) {
    const o = {};
    new FormData(form).forEach((v, k) => { o[k] = v; });
    $$('input[type=checkbox][name]', form).forEach((c) => { o[c.name] = c.checked; });
    return o;
  }

  /** Simple hash router: routes = [[/^#\/orders\/(\d+)$/, fn], ...] */
  function router(routes, fallback) {
    const run = () => {
      $$('.overlay').forEach((o) => o.remove());
      document.body.style.overflow = '';
      const h = location.hash || '#/';
      for (const [re, fn] of routes) { const m = h.match(re); if (m) { fn(...m.slice(1)); window.scrollTo(0, 0); return; } }
      fallback();
    };
    window.addEventListener('hashchange', run);
    run();
  }

  /** Short beep for new-order alerts (no audio file needed). */
  function beep() {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      [0, 0.25].forEach((t) => {
        const o = ctx.createOscillator(); const g = ctx.createGain();
        o.frequency.value = 880; o.connect(g); g.connect(ctx.destination);
        g.gain.setValueAtTime(0.2, ctx.currentTime + t); g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + t + 0.2);
        o.start(ctx.currentTime + t); o.stop(ctx.currentTime + t + 0.2);
      });
    } catch (e) { /* ignore */ }
  }

  /** Show a system notification while the app is open (or installed). */
  async function systemNotify(title, body) {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    try {
      const reg = await navigator.serviceWorker?.getRegistration();
      if (reg) reg.showNotification(title, { body, icon: BASE + 'assets/icons/icon-192.png', badge: BASE + 'assets/icons/icon-192.png' });
      else new Notification(title, { body });
    } catch (e) { /* ignore */ }
  }

  // ---- PWA: service worker + install prompt ----
  let deferredPrompt = null;
  const isStandalone = () => window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  const isIOS = () => /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const isMobile = () => /android|iphone|ipad|ipod|mobile/i.test(navigator.userAgent) || isIOS();

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register(BASE + 'sw.js').catch(() => {}));
  }
  window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferredPrompt = e; showInstall(); });

  function showInstall() {
    if (isStandalone() || !isMobile()) return;
    try { if (Date.now() - Number(localStorage.getItem('cf_install_dismissed') || 0) < 3 * 86400000) return; } catch (e) { /* ignore */ }
    if ($('#installBox')) return;
    const ios = isIOS() && !deferredPrompt;
    if (!ios && !deferredPrompt) return;
    const box = document.createElement('div');
    box.id = 'installBox';
    box.className = 'install panel shadow';
    box.innerHTML = `<div class="row" style="align-items:flex-start;flex-wrap:nowrap">
      <img src="${BASE}assets/icons/icon-192.png" width="48" height="48" alt="" style="border:0">
      <div style="flex:1"><b>Install the Cafresco app</b><div class="small muted">${ios
        ? 'Tap the Share button <b>⬆︎</b> in Safari, then <b>Add to Home Screen</b>.'
        : 'Order faster from your home screen. Free, no app store needed.'}</div></div>
      <button class="iconbtn" data-x aria-label="Dismiss">✕</button></div>
      ${ios ? '' : '<button class="btn block" data-install style="margin-top:12px">Install app</button>'}`;
    document.body.appendChild(box);
    box.querySelector('[data-x]').onclick = () => { box.remove(); try { localStorage.setItem('cf_install_dismissed', String(Date.now())); } catch (e) { /* ignore */ } };
    const ib = box.querySelector('[data-install]');
    if (ib) ib.onclick = async () => { deferredPrompt.prompt(); await deferredPrompt.userChoice; deferredPrompt = null; box.remove(); };
  }
  window.addEventListener('load', () => setTimeout(showInstall, 2500));

  window.CF = { api, esc, peso, $, $$, toast, modal, busy, formData, router, beep, systemNotify, fmtDate, initials, BASE, isMobile, isStandalone };
})();
