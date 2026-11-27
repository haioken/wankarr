// Poster grids (library, search, series volumes): reading progress, broken
// covers, poster size, multi-select with bulk actions, and refreshing when a
// background task finishes.
import { readProgress } from './progress.js';
import { api, esc, modal, buttons, toast } from './ui.js';
import { openRename } from './rename.js';

/** Fraction read across a set of volumes (0–1), and whether all are finished. */
export function progressOf(ids) {
  if (!ids.length) return null;
  let sum = 0, done = 0, any = false;
  for (const id of ids) {
    const p = readProgress(id);
    if (!p || !p.total) continue;
    any = true;
    const f = Math.min(1, (p.page + 1) / p.total);
    sum += f;
    if (f >= 1) done++;
  }
  return any ? { fraction: sum / ids.length, finished: done === ids.length } : null;
}

/** Progress bars and broken-cover fallbacks for posters inside `root`. */
export function decoratePosters(root = document) {
  for (const poster of root.querySelectorAll('[data-books]')) {
    const p = progressOf(poster.dataset.books.split(',').filter(Boolean));
    const bar = poster.querySelector('.poster-progress');
    if (!p || !bar) continue;
    bar.firstElementChild.style.width = `${Math.round(p.fraction * 100)}%`;
    bar.classList.toggle('is-done', p.finished);
    bar.title = p.finished ? 'Finished' : `${Math.round(p.fraction * 100)}% read`;
  }
  for (const img of root.querySelectorAll('.poster-art img')) {
    const broken = () => img.classList.add('is-broken');
    if (img.complete && img.naturalWidth === 0) broken();
    img.addEventListener('error', broken);
  }
  applyPosterSize();
}

// --- poster size (continuous) ------------------------------------------------------

const SIZE_KEY = 'wk:posterPx';
let posterPx = 172;
try { posterPx = Math.min(320, Math.max(90, +localStorage.getItem(SIZE_KEY) || 172)); } catch {}

function applyPosterSize() {
  for (const g of document.querySelectorAll('.poster-grid:not(.book-grid)')) g.style.setProperty('--poster', `${posterPx}px`);
  for (const g of document.querySelectorAll('.book-grid')) g.style.setProperty('--poster', `${Math.round(posterPx * 0.82)}px`);
}

const slider = document.querySelector('.js-poster-size');
if (slider) {
  slider.value = posterPx;
  slider.addEventListener('input', () => {
    posterPx = +slider.value;
    applyPosterSize();
    try { localStorage.setItem(SIZE_KEY, String(posterPx)); } catch {}
  });
}

decoratePosters();

// --- selection ---------------------------------------------------------------------------

const grid = document.querySelector('.js-selectable');
const toolbar = document.querySelector('.js-toolbar');
const hiddenView = document.querySelector('.library-page')?.dataset.hiddenView === '1';

if (grid && toolbar) {
  const boxes = () => [...grid.querySelectorAll('.js-select')];
  const selected = () => boxes().filter((b) => b.checked).map((b) => +b.value);
  let selectMode = false;
  let lastIndex = null;

  const sync = () => {
    const ids = selected();
    const on = selectMode || ids.length > 0;
    grid.classList.toggle('is-selecting', on);
    toolbar.querySelector('.toolbar-default').hidden = on;
    toolbar.querySelector('.toolbar-selection').hidden = !on;
    toolbar.querySelector('.js-selection-count').textContent = `${ids.length} selected`;
    for (const b of toolbar.querySelectorAll('.js-bulk')) b.disabled = ids.length === 0;
    for (const b of boxes()) b.closest('.poster-cell').classList.toggle('is-selected', b.checked);
  };

  const toggleAt = (index, shift) => {
    const all = boxes();
    if (shift && lastIndex !== null) {
      const [a, b] = [Math.min(lastIndex, index), Math.max(lastIndex, index)];
      const state = !all[index].checked;
      for (let i = a; i <= b; i++) all[i].checked = state;
    } else {
      all[index].checked = !all[index].checked;
    }
    lastIndex = index;
    sync();
  };

  grid.addEventListener('click', (e) => {
    const cell = e.target.closest('.poster-cell');
    if (!cell) return;
    const index = boxes().indexOf(cell.querySelector('.js-select'));
    if (e.target.closest('.poster-check')) {
      e.preventDefault();
      toggleAt(index, e.shiftKey);
      return;
    }
    // While selecting, clicking a poster selects it instead of opening it.
    if (grid.classList.contains('is-selecting') && e.target.closest('.poster')) {
      e.preventDefault();
      toggleAt(index, e.shiftKey);
    }
  });

  toolbar.querySelector('.js-select-mode')?.addEventListener('click', () => { selectMode = true; sync(); });
  toolbar.querySelector('.js-select-all').addEventListener('click', () => { for (const b of boxes()) b.checked = true; sync(); });
  toolbar.querySelector('.js-select-clear').addEventListener('click', () => {
    for (const b of boxes()) b.checked = false;
    selectMode = false;
    lastIndex = null;
    sync();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && grid.classList.contains('is-selecting') && !document.querySelector('.modal')) {
      toolbar.querySelector('.js-select-clear').click();
    }
  });

  const titleOf = (id) => grid.querySelector(`.poster-cell[data-series="${id}"]`)?.dataset.title ?? `#${id}`;
  const listOf = (ids) => {
    const names = ids.slice(0, 8).map((id) => `<li>${esc(titleOf(id))}</li>`).join('');
    return `<ul class="modal-list">${names}${ids.length > 8 ? `<li class="muted">…and ${ids.length - 8} more</li>` : ''}</ul>`;
  };

  for (const btn of toolbar.querySelectorAll('.js-bulk')) {
    btn.addEventListener('click', async () => {
      const ids = selected();
      if (!ids.length) return;
      const action = btn.dataset.action;
      if (action === 'rename') return openRename(ids, `Auto rename ${ids.length} series`);
      if (action === 'delete') return openDelete(ids, listOf);
      btn.classList.add('is-spinning');
      try {
        const r = await api('api/series.php', { action, ids });
        toast(action === 'rescan'
          ? `Rescanned: ${r.added} added, ${r.updated} updated, ${r.removed} removed`
          : `${ids.length} series unhidden`);
        setTimeout(() => location.reload(), 600);
      } catch (e) {
        toast(e.message, 'error');
        btn.classList.remove('is-spinning');
      }
    });
  }
  sync();
}

function openDelete(ids, listOf) {
  const body = document.createElement('div');
  body.innerHTML = `
    <p>${hiddenView ? 'Delete' : 'Hide or delete'} these ${ids.length === 1 ? 'series' : `${ids.length} series`}:</p>
    ${listOf(ids)}
    <label class="check-row"><input type="checkbox" class="js-files"${hiddenView ? ' checked disabled' : ''}> <span><strong>Also delete the files from disk</strong><br>
      <span class="muted">${hiddenView
        ? 'Hidden series are already out of the library, so deleting them means deleting their files. Use Unhide to bring them back instead.'
        : 'Unticked, the series are only hidden from the library and can be restored from Hidden.'}</span></span></label>
    <div class="js-file-details" hidden></div>`;
  const m = modal({ title: ids.length === 1 ? 'Delete series' : `Delete ${ids.length} series`, body });
  const files = body.querySelector('.js-files');
  const details = body.querySelector('.js-file-details');
  let preview = null;
  let confirmBtn;

  const update = async () => {
    confirmBtn.textContent = files.checked ? `Delete ${ids.length === 1 ? 'series and files' : `${ids.length} series and their files`}` : `Hide ${ids.length === 1 ? 'series' : `${ids.length} series`}`;
    confirmBtn.className = `button ${files.checked ? 'danger' : 'primary'}`;
    details.hidden = !files.checked;
    if (!files.checked) return;
    if (!preview) {
      details.innerHTML = '<div class="spinner"></div>';
      try { preview = await api('api/series.php', { action: 'delete-preview', ids }); } catch (e) { details.innerHTML = `<p class="error">${esc(e.message)}</p>`; return; }
    }
    const blocked = preview.filter((p) => p.errors.length);
    details.innerHTML = `
      <div class="alert alert-danger"><div><strong>This permanently deletes from disk:</strong>
        <ul class="modal-list">${preview.flatMap((p) => (p.folders.length ? p.folders.map((f) => `<li><code>${esc(f)}/</code> <span class="muted">(whole folder)</span></li>`) : p.paths.map((f) => `<li><code>${esc(f)}</code></li>`))).slice(0, 30).join('')}</ul></div></div>
      ${blocked.length ? `<div class="alert"><div><strong>Can't delete (nothing will be removed for these):</strong><ul>${blocked.map((p) => `<li>${esc(p.title)}: ${esc(p.errors.join('; '))}</li>`).join('')}</ul></div></div>` : ''}`;
  };

  buttons(m, [
    { label: 'Cancel' },
    { label: 'Hide', primary: true, onClick: async () => {
      m.setBusy(true);
      try {
        const r = await api('api/series.php', { action: files.checked ? 'delete' : 'hide', ids });
        if (r.errors?.length) toast(r.errors.join(' · '), 'error');
        else toast(files.checked ? `${r.deleted} series deleted` : `${r.hidden} series hidden`);
        setTimeout(() => location.reload(), r.errors?.length ? 2500 : 500);
      } catch (e) { toast(e.message, 'error'); m.setBusy(false); }
    } },
  ]);
  confirmBtn = m.footer.lastElementChild;
  files.addEventListener('change', update);
  update();
}

// The library listing reflects scan/metadata results, so refresh when they land.
if (document.querySelector('.library-page')) {
  document.addEventListener('wk:task-done', () => location.reload());
}
