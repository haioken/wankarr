// Reading progress and reader preferences, stored per user on the server.

const HEADERS = { 'Content-Type': 'application/json', 'X-Requested-With': 'wankarr' };
const post = (body, keepalive = false) => fetch('api/progress.php', { method: 'POST', headers: HEADERS, body: JSON.stringify(body), keepalive }).catch(() => {});

let pending = null;
let timer = 0;

function flush(keepalive = false) {
  clearTimeout(timer);
  if (!pending) return;
  const body = { action: 'progress', ...pending };
  pending = null;
  post(body, keepalive);
}

/** Debounced: page turns are cheap, saving every one isn't necessary. */
export function saveProgress(bookId, page, total) {
  pending = { book: +bookId, page, total };
  clearTimeout(timer);
  timer = setTimeout(() => flush(), 800);
}

// Leaving the page: send what's pending even as the page unloads.
addEventListener('pagehide', () => flush(true));
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flush(true); });

/** Direction/layout are per user; "cover on its own" is per volume. */
export function savePrefs(bookId, prefs) {
  post({ action: 'prefs', book: +bookId, prefs: { direction: prefs.direction, layout: prefs.layout }, cover_alone: !!prefs.coverAlone });
}

/**
 * One-time move of progress saved in this browser by earlier versions
 * (localStorage) to the signed-in account.
 */
export async function migrateLocalState() {
  let keys;
  try {
    if (localStorage.getItem('wk:migrated')) return;
    keys = Object.keys(localStorage).filter((k) => /^wk:progress:\d+$/.test(k));
  } catch { return; }
  const items = [];
  for (const k of keys) {
    try {
      const p = JSON.parse(localStorage.getItem(k));
      if (p && Number.isFinite(p.page) && Number.isFinite(p.total)) items.push({ book: +k.split(':')[2], page: p.page, total: p.total, at: p.at ? Math.round(p.at / 1000) : null });
    } catch {}
  }
  let prefs = null;
  try { prefs = JSON.parse(localStorage.getItem('wk:prefs')); } catch {}
  if (items.length || prefs) {
    const res = await fetch('api/progress.php', { method: 'POST', headers: HEADERS, body: JSON.stringify({ action: 'import', items, prefs }) }).catch(() => null);
    if (!res?.ok) return;
  }
  try {
    for (const k of Object.keys(localStorage)) if (/^wk:(progress:\d+|prefs(:\d+)?|last)$/.test(k)) localStorage.removeItem(k);
    localStorage.setItem('wk:migrated', '1');
  } catch {}
}
