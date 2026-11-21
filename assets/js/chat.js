/* Cafresco support chat bubble (customers and riders). Polls the API; no paid services. */
(function () {
  'use strict';
  const { api, esc, $, toast, BASE } = CF;
  let user = null, box = null, open = false, lastId = 0, timer = null, fbUrl = '', loginHref = '#/login', sending = false;

  const time = (s) => {
    const d = new Date(String(s).replace(' ', 'T'));
    const today = new Date().toDateString() === d.toDateString();
    return d.toLocaleString([], today ? { hour: 'numeric', minute: '2-digit' } : { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  };
  const linkify = (t) => esc(t).replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>').replace(/\n/g, '<br>');

  function build() {
    box = document.createElement('div');
    box.className = 'chatw';
    box.innerHTML = `
      <button class="chatfab" type="button" aria-label="Chat with Cafresco" aria-expanded="false">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 5h16v11H9l-5 4z"/></svg>
        <span class="badge hidden" data-unread>0</span>
      </button>
      <section class="chatpanel" role="dialog" aria-label="Chat with Cafresco" hidden>
        <header>
          <div><b>Chat with Cafresco</b><small>We reply during store hours</small></div>
          <button class="iconbtn" type="button" data-close aria-label="Close chat">✕</button>
        </header>
        <div class="chatlog" aria-live="polite"></div>
        <div class="chatfoot"></div>
      </section>`;
    document.body.appendChild(box);
    $('.chatfab', box).onclick = () => (open ? close() : show());
    $('[data-close]', box).onclick = close;
  }

  function setUnread(n) {
    const b = $('[data-unread]', box);
    b.textContent = n > 9 ? '9+' : String(n);
    b.classList.toggle('hidden', !n || open);
  }

  function renderFoot() {
    const foot = $('.chatfoot', box);
    const fb = fbUrl ? `<a class="fblink" href="${esc(fbUrl)}" target="_blank" rel="noopener">Or message us on Facebook →</a>` : '';
    if (!user) {
      foot.innerHTML = `<p class="small" style="margin:0 0 10px">Log in to chat with us here. We'll keep the conversation in your account.</p>
        <a class="btn block clay" href="${loginHref}">Log in to chat</a>${fb}`;
      $('.chatlog', box).innerHTML = '<div class="chatempty">Hi! 👋 Questions about an order, a rider, or our bakes? Send us a message.</div>';
      foot.querySelector('a.btn').onclick = close;
      return;
    }
    foot.innerHTML = `<form class="chatform"><textarea rows="1" maxlength="2000" placeholder="Type a message…" aria-label="Message"></textarea>
      <button class="btn clay" type="submit" aria-label="Send">Send</button></form>${fb}`;
    const f = $('.chatform', foot), ta = $('textarea', f);
    ta.addEventListener('input', () => { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 120) + 'px'; });
    ta.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey && !CF.isMobile()) { e.preventDefault(); f.requestSubmit(); } });
    f.onsubmit = async (e) => {
      e.preventDefault();
      const body = ta.value.trim();
      if (!body || sending) return;
      sending = true;
      try {
        const r = await api('chat', { body: { body } });
        ta.value = ''; ta.style.height = 'auto';
        append(r.messages, true);
      } catch (err) { toast(err.message, true); } finally { sending = false; ta.focus(); }
    };
  }

  function append(msgs, reset) {
    const log = $('.chatlog', box);
    if (reset) { log.innerHTML = ''; lastId = 0; }
    const fresh = msgs.filter((m) => m.id > lastId);
    if (!fresh.length && !log.children.length) {
      log.innerHTML = '<div class="chatempty">Hi! 👋 Questions about an order, a rider, or our bakes? Send us a message and the Cafresco team will reply here.</div>';
      return;
    }
    const empty = $('.chatempty', log);
    if (empty && fresh.length) empty.parentNode.removeChild(empty);
    fresh.forEach((m) => {
      const el = document.createElement('div');
      el.className = 'msg ' + (m.from_staff ? 'them' : 'me');
      el.innerHTML = `<div class="bubble">${linkify(m.body)}</div><small>${m.from_staff ? esc(m.staff_name || 'Cafresco') + ' · ' : ''}${time(m.created_at)}</small>`;
      log.appendChild(el);
      lastId = Math.max(lastId, m.id);
    });
    if (fresh.length) log.scrollTop = log.scrollHeight;
  }

  async function load(reset) {
    if (!user) return;
    try {
      const r = await api('chat' + (reset ? '' : '?after=' + lastId));
      fbUrl = r.facebook_url || fbUrl;
      append(r.messages, reset);
      setUnread(0);
    } catch (e) { /* offline */ }
  }

  async function peek() {
    if (!user || open) return;
    try { const r = await api('chat?peek=1'); setUnread(r.unread); } catch (e) { /* offline */ }
  }

  function schedule() {
    clearInterval(timer);
    timer = setInterval(() => { if (document.hidden) return; open ? load(false) : peek(); }, open ? 6000 : 25000);
  }

  async function show() {
    open = true;
    const panel = $('.chatpanel', box);
    panel.hidden = false;
    requestAnimationFrame(() => panel.classList.add('on'));
    $('.chatfab', box).setAttribute('aria-expanded', 'true');
    box.classList.add('open');
    setUnread(0);
    renderFoot();
    await load(true);
    const ta = $('.chatform textarea', box);
    if (ta && !CF.isMobile()) ta.focus();
    schedule();
  }

  function close() {
    open = false;
    const panel = $('.chatpanel', box);
    panel.classList.remove('on');
    box.classList.remove('open');
    $('.chatfab', box).setAttribute('aria-expanded', 'false');
    setTimeout(() => { if (!open) panel.hidden = true; }, 250);
    schedule();
  }

  /** Call whenever the signed-in user changes. opts: { facebookUrl, loginHref } */
  CF.chat = {
    init(u, opts = {}) {
      if (u && !['customer', 'rider'].includes(u.role)) { if (box) box.style.display = 'none'; return; }
      if (!box) build();
      box.style.display = '';
      const changed = (user && user.id) !== (u && u.id);
      user = u;
      if (opts.facebookUrl) fbUrl = opts.facebookUrl;
      if (opts.loginHref) loginHref = opts.loginHref;
      if (changed) { lastId = 0; setUnread(0); if (open) { renderFoot(); load(true); } }
      peek();
      schedule();
    },
    open: () => { if (box) show(); },
  };
})();
