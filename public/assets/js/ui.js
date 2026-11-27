// Shared UI helpers (no side effects on import).

export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

export function debounce(fn, ms) {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

/** JSON API call. POSTs carry the anti-CSRF header the server requires. */
export async function api(url, body) {
  const opts = body === undefined
    ? { headers: { Accept: 'application/json' } }
    : { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'wankarr' }, body: JSON.stringify(body) };
  const res = await fetch(url, opts);
  let data = null;
  try { data = await res.json(); } catch {}
  if (!res.ok) throw new Error(data?.error || `Request failed (HTTP ${res.status})`);
  return data;
}

const ICON_CLOSE = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';

/** Sonarr-style modal. Returns { el, body, footer, close, setBusy }. */
export function modal({ title, body = '', wide = false, onClose } = {}) {
  const back = document.createElement('div');
  back.className = 'modal-backdrop';
  back.innerHTML = `
    <div class="modal${wide ? ' modal-wide' : ''}" role="dialog" aria-modal="true" aria-label="${esc(title)}">
      <header class="modal-header"><h2>${esc(title)}</h2><button type="button" class="modal-close" aria-label="Close">${ICON_CLOSE}</button></header>
      <div class="modal-body"></div>
      <footer class="modal-footer"></footer>
    </div>`;
  const el = back.firstElementChild;
  const bodyEl = el.querySelector('.modal-body');
  const footer = el.querySelector('.modal-footer');
  if (typeof body === 'string') bodyEl.innerHTML = body; else bodyEl.append(body);

  const previous = document.activeElement;
  const close = () => {
    back.remove();
    document.removeEventListener('keydown', onKey);
    previous?.focus?.();
    onClose?.();
  };
  const onKey = (e) => { if (e.key === 'Escape') close(); };
  back.addEventListener('mousedown', (e) => { if (e.target === back) close(); });
  el.querySelector('.modal-close').addEventListener('click', close);
  document.addEventListener('keydown', onKey);
  document.body.append(back);
  (el.querySelector('[autofocus]') || el.querySelector('input, button.primary') || el.querySelector('.modal-close')).focus();

  return {
    el, body: bodyEl, footer, close,
    setBusy(on) { el.classList.toggle('is-busy', on); for (const b of el.querySelectorAll('.modal-footer button')) b.disabled = on; },
  };
}

/** Add footer buttons: [{ label, primary?, danger?, onClick }]. */
export function buttons(m, defs) {
  for (const d of defs) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = `button${d.primary ? ' primary' : ''}${d.danger ? ' danger' : ''}`;
    b.textContent = d.label;
    b.addEventListener('click', d.onClick || m.close);
    m.footer.append(b);
  }
}

let toastEl;
export function toast(message, kind = 'info') {
  if (!toastEl) {
    toastEl = document.createElement('div');
    toastEl.className = 'app-toast';
    toastEl.setAttribute('role', 'status');
    document.body.append(toastEl);
  }
  toastEl.textContent = message;
  toastEl.dataset.kind = kind;
  toastEl.classList.add('is-visible');
  clearTimeout(toastEl._t);
  toastEl._t = setTimeout(() => toastEl.classList.remove('is-visible'), 3500);
}

/** Series picker for merge/move: search box + result list. Resolves on pick. */
export function seriesPicker({ library, exclude, placeholder = 'Search series…', onPick }) {
  const wrap = document.createElement('div');
  wrap.className = 'picker';
  wrap.innerHTML = `<input type="search" class="input" placeholder="${esc(placeholder)}" autofocus><ul class="picker-list" role="listbox"></ul>`;
  const input = wrap.querySelector('input');
  const list = wrap.querySelector('ul');
  const load = debounce(async () => {
    const rows = await api(`api/series.php?library=${library}&q=${encodeURIComponent(input.value)}`).catch(() => []);
    list.innerHTML = rows.filter((r) => r.id !== exclude).map((r) => `
      <li><button type="button" data-id="${r.id}" data-title="${esc(r.title)}">
        <img src="api/cover.php?series=${r.id}" alt="" loading="lazy">
        <span><strong>${esc(r.title)}</strong><span class="muted">${[r.year, `${r.volumes} volume${r.volumes === 1 ? '' : 's'}`].filter(Boolean).join(' · ')}</span></span>
      </button></li>`).join('') || '<li class="muted picker-empty">No series found</li>';
  }, 180);
  input.addEventListener('input', load);
  list.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-id]');
    if (b) onPick({ id: +b.dataset.id, title: b.dataset.title });
  });
  load();
  return wrap;
}
