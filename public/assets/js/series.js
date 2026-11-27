// Series page: continue reading, metadata matching, rename, merge, move book.
import { api, esc, modal, buttons, toast, seriesPicker } from './ui.js';
import { readProgress } from './progress.js';
import { openRename } from './rename.js';
import './library.js';

const page = document.querySelector('.series-page');
const seriesId = +page.dataset.series;
const library = +page.dataset.library;
const seriesTitle = page.dataset.title;

// --- continue reading ---------------------------------------------------------------

const books = [...document.querySelectorAll('.book[data-book]')].map((el) => +el.dataset.book);
const cont = document.querySelector('.js-continue');
if (cont && books.length) {
  const started = books.find((id) => { const p = readProgress(id); return p && p.page + 1 < p.total; });
  const unread = books.find((id) => { const p = readProgress(id); return !p || p.page + 1 < p.total; });
  const target = started ?? unread ?? books[0];
  cont.href = `read.php?id=${target}`;
  const label = cont.querySelector('span');
  if (started || (unread && unread !== books[0])) label.textContent = 'Continue';
}

// Long synopses collapse; click to expand.
const synopsis = document.querySelector('.js-synopsis');
if (synopsis && synopsis.scrollHeight > synopsis.clientHeight + 4) {
  synopsis.classList.add('is-collapsible');
  synopsis.addEventListener('click', () => synopsis.classList.toggle('is-expanded'));
}

const act = (action, extra = {}) => api('api/series.php', { action, id: seriesId, ...extra });

async function busy(btn, fn) {
  btn?.classList.add('is-spinning');
  try { await fn(); } catch (e) { toast(e.message, 'error'); btn?.classList.remove('is-spinning'); }
}

// --- refresh / unmatch -----------------------------------------------------------------

document.querySelector('.js-refresh')?.addEventListener('click', (e) => busy(e.currentTarget, async () => {
  const r = await act('refresh');
  if (r.status === 'unmatched') toast('Still no confident match. Try Match… to pick one manually.', 'error');
  location.reload();
}));

document.querySelector('.js-unmatch')?.addEventListener('click', () => {
  const m = modal({ title: 'Remove metadata', body: `<p>Detach metadata from <strong>${esc(seriesTitle)}</strong>? Automatic matching will be turned off for this series until you match it again.</p>` });
  buttons(m, [
    { label: 'Cancel' },
    { label: 'Remove', danger: true, onClick: async () => { m.setBusy(true); try { await act('unmatch'); location.reload(); } catch (e) { toast(e.message, 'error'); m.setBusy(false); } } },
  ]);
});

// --- manual match --------------------------------------------------------------------------

function openMatch() {
  const body = document.createElement('div');
  body.innerHTML = `
    <form class="match-search"><input type="search" class="input" value="${esc(seriesTitle)}" autofocus aria-label="Search MyAnimeList"><button class="button primary">Search</button></form>
    <div class="match-results"><div class="spinner"></div></div>`;
  const m = modal({ title: `Match “${seriesTitle}”`, body, wide: true });
  const input = body.querySelector('input');
  const results = body.querySelector('.match-results');

  let seq = 0;
  const search = async () => {
    const mine = ++seq;
    results.innerHTML = '<div class="spinner"></div>';
    try {
      const data = await api(`api/metadata-search.php?series=${seriesId}&q=${encodeURIComponent(input.value)}`);
      if (mine !== seq) return; // a newer search superseded this one
      if (!data.results.length) {
        results.innerHTML = '<p class="muted match-empty">No results. Try the Japanese/romaji title, or fewer words.</p>';
        return;
      }
      results.innerHTML = data.results.map((c) => `
        <div class="match-row${c.current ? ' is-current' : ''}">
          ${c.cover ? `<img src="${esc(c.cover)}" alt="" loading="lazy" referrerpolicy="no-referrer">` : '<div class="match-noimg"></div>'}
          <div class="match-info">
            <div class="match-title">${esc(c.title)}${c.title_english && c.title_english !== c.title ? ` <span class="muted">· ${esc(c.title_english)}</span>` : ''}</div>
            <div class="match-meta">${[c.type, c.year, c.status, c.volumes ? `${c.volumes} vols` : null, c.authors.slice(0, 2).join(', ')].filter(Boolean).map(esc).join(' · ')}
              ${c.score ? ` · ★ ${esc(c.score)}` : ''} · <a href="${esc(c.url)}" target="_blank" rel="noopener noreferrer">MAL</a></div>
            ${c.synopsis ? `<div class="match-synopsis">${esc(c.synopsis)}</div>` : ''}
          </div>
          <div class="match-side">
            <span class="match-score ${c.match >= data.threshold ? 'is-good' : c.match >= 0.6 ? 'is-ok' : 'is-poor'}" title="How closely this matches the series name">${Math.round(c.match * 100)}%</span>
            ${c.current ? '<span class="chip">Current</span>' : `<button type="button" class="button primary" data-pick="${esc(c.id)}" data-score="${c.match}">Select</button>`}
          </div>
        </div>`).join('');
    } catch (e) {
      if (mine === seq) results.innerHTML = `<p class="match-empty error">${esc(e.message)}</p>`;
    }
  };

  body.querySelector('form').addEventListener('submit', (e) => { e.preventDefault(); search(); });
  results.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-pick]');
    if (!b) return;
    m.setBusy(true);
    b.textContent = 'Fetching…';
    try {
      await act('match', { provider: 'tenrai', provider_id: b.dataset.pick, score: +b.dataset.score });
      location.reload();
    } catch (err) {
      toast(err.message, 'error');
      m.setBusy(false);
      b.textContent = 'Select';
    }
  });
  search();
}
for (const b of document.querySelectorAll('.js-match')) b.addEventListener('click', openMatch);

// --- rename (manual only) ------------------------------------------------------------------

document.querySelector('.js-rename')?.addEventListener('click', () => openRename([seriesId], `Rename “${seriesTitle}”`));

// --- edit -------------------------------------------------------------------------------------

document.querySelector('.js-edit')?.addEventListener('click', () => {
  const body = document.createElement('form');
  body.className = 'form';
  body.innerHTML = `
    <label class="field"><span>Title</span><input class="input" name="title" value="${esc(seriesTitle)}" required autofocus></label>
    <label class="field"><span>Sort title <em class="muted">optional</em></span><input class="input" name="sort_title" placeholder="Derived from the title"></label>
    <p class="muted help">A custom title is kept even when metadata is refreshed. To change which metadata entry is used, use Match… instead.</p>`;
  const m = modal({ title: 'Edit series', body });
  const save = async () => {
    m.setBusy(true);
    try {
      await act('rename', { title: body.title.value, sort_title: body.sort_title.value });
      location.reload();
    } catch (e) { toast(e.message, 'error'); m.setBusy(false); }
  };
  body.addEventListener('submit', (e) => { e.preventDefault(); save(); });
  buttons(m, [{ label: 'Cancel' }, { label: 'Save', primary: true, onClick: save }]);
});

// --- merge -------------------------------------------------------------------------------------

document.querySelector('.js-merge')?.addEventListener('click', () => {
  let m;
  const picker = seriesPicker({
    library, exclude: seriesId, placeholder: 'Find the series to merge into…',
    onPick: (target) => {
      m.body.innerHTML = `<p>Move all volumes from <strong>${esc(seriesTitle)}</strong> into <strong>${esc(target.title)}</strong>?</p>
        <p class="muted">“${esc(seriesTitle)}” will be removed. Volumes scanned later from its folder will keep going to “${esc(target.title)}”.</p>`;
      m.footer.innerHTML = '';
      buttons(m, [
        { label: 'Back', onClick: () => { m.close(); document.querySelector('.js-merge').click(); } },
        { label: 'Merge', primary: true, onClick: async () => {
          m.setBusy(true);
          try { const r = await act('merge', { into: target.id }); location.href = r.redirect; } catch (e) { toast(e.message, 'error'); m.setBusy(false); }
        } },
      ]);
    },
  });
  m = modal({ title: `Merge “${seriesTitle}” into…`, body: picker });
});

// --- move a book -------------------------------------------------------------------------------

for (const btn of document.querySelectorAll('.js-move')) {
  btn.addEventListener('click', () => {
    const bookId = +btn.dataset.book;
    const wrap = document.createElement('div');
    const move = async (payload) => {
      m.setBusy(true);
      try {
        const r = await api('api/books.php', { action: 'move', id: bookId, ...payload });
        location.href = books.length > 1 ? location.href : `series.php?id=${r.series}`;
      } catch (e) { toast(e.message, 'error'); m.setBusy(false); }
    };
    wrap.append(seriesPicker({ library, exclude: seriesId, placeholder: 'Find the series this volume belongs to…', onPick: (t) => move({ series_id: t.id }) }));
    const split = document.createElement('form');
    split.className = 'split-form';
    split.innerHTML = `<div class="muted">…or split it into a new series:</div><div class="row"><input class="input" name="title" placeholder="New series title" required><button class="button">Create</button></div>`;
    split.addEventListener('submit', (e) => { e.preventDefault(); move({ new_title: split.title.value }); });
    wrap.append(split);
    const m = modal({ title: `Move “${btn.dataset.title}”`, body: wrap });
  });
}

