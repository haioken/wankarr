// Per-device reading progress and per-book reader preferences (localStorage).

const safe = (fn, fallback = null) => { try { return fn(); } catch { return fallback; } };

export function readProgress(id) {
  return safe(() => JSON.parse(localStorage.getItem('wk:progress:' + id)));
}

export function saveProgress(id, title, page, total) {
  safe(() => {
    const entry = { page, total, at: Date.now() };
    localStorage.setItem('wk:progress:' + id, JSON.stringify(entry));
    localStorage.setItem('wk:last', JSON.stringify({ id, title, page, total }));
  });
}

export function lastRead() {
  return safe(() => JSON.parse(localStorage.getItem('wk:last')));
}

export function loadPrefs(id) {
  const global = safe(() => JSON.parse(localStorage.getItem('wk:prefs')), {}) || {};
  const book = (id && safe(() => JSON.parse(localStorage.getItem('wk:prefs:' + id)), {})) || {};
  return { ...global, ...book };
}

/** Direction and layout are remembered globally; cover offset is per book. */
export function savePrefs(id, prefs) {
  safe(() => {
    const { coverAlone, ...global } = prefs;
    localStorage.setItem('wk:prefs', JSON.stringify(global));
    if (id) localStorage.setItem('wk:prefs:' + id, JSON.stringify({ coverAlone }));
  });
}
