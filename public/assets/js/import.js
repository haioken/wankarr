// Import: upload volumes (chunked), let the server group and match them,
// review/adjust matches and volume numbers, preview destinations, import.
import { Zip, ZipPassThrough } from '../vendor/fflate.js';
import { api, esc, debounce, modal, toast, seriesPicker } from './ui.js';

const root = document.querySelector('.js-import');
const $ = (s) => root.querySelector(s);
const CHUNK = 8 * 1024 * 1024;
const ARCHIVE = /\.(cbz|zip|cbr|rar|cb7|7z|cbt|tar)$/i;
const IMAGE = /\.(jpe?g|png|gif|webp|avif|bmp)$/i;
const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });

const libraries = JSON.parse(root.dataset.libraries || '[]');
const ui = {
  drop: $('.js-drop'), uploads: $('.js-uploads'), status: $('.js-status'), groups: $('.js-groups'),
  results: $('.js-results'), footer: $('.js-footer'), summary: $('.js-summary'), commit: $('.js-commit'),
  library: $('.js-library'),
};

const state = { batch: null, files: [], nextId: 0, uploading: false, analysis: null, plan: null, busy: false };
const libraryId = () => +(ui.library?.value || libraries[0]?.id || 0);

// --- picking files ---------------------------------------------------------------------

const pickFiles = $('.js-pick-files');
const pickFolder = $('.js-pick-folder');
$('.js-pick-files-btn')?.addEventListener('click', () => pickFiles.click());
$('.js-pick-folder-btn')?.addEventListener('click', () => pickFolder.click());
for (const input of [pickFiles, pickFolder]) {
  input?.addEventListener('change', () => { addFiles([...input.files]); input.value = ''; });
}

ui.library?.addEventListener('change', () => {
  root.querySelector('.js-library-name').textContent = ui.library.selectedOptions[0].textContent;
  if (state.analysis) analyse();
});

addEventListener('dragover', (e) => { e.preventDefault(); ui.drop?.classList.add('is-over'); });
addEventListener('dragleave', (e) => { if (!e.relatedTarget) ui.drop?.classList.remove('is-over'); });
addEventListener('drop', async (e) => {
  e.preventDefault();
  ui.drop?.classList.remove('is-over');
  addFiles(await filesFromDrop(e.dataTransfer));
});

async function filesFromDrop(dt) {
  const entries = [...(dt.items || [])].map((i) => i.webkitGetAsEntry?.()).filter(Boolean);
  if (!entries.length) return [...dt.files];
  const out = [];
  const walk = async (entry, path) => {
    if (entry.isFile) {
      const file = await new Promise((res, rej) => entry.file(res, rej));
      out.push(Object.assign(file, { relPath: path + file.name }));
    } else if (entry.isDirectory) {
      const reader = entry.createReader();
      let batch;
      do {
        batch = await new Promise((res, rej) => reader.readEntries(res, rej));
        for (const child of batch) await walk(child, `${path}${entry.name}/`);
      } while (batch.length);
    }
  };
  for (const entry of entries) await walk(entry, '');
  return out;
}

/** Archives upload as they are; folders of images are packed into one CBZ each. */
async function addFiles(list) {
  const rel = (f) => f.relPath || f.webkitRelativePath || f.name;
  const archives = list.filter((f) => ARCHIVE.test(f.name));
  const folders = new Map();
  for (const f of list) {
    if (!IMAGE.test(f.name) || rel(f).split('/').some((p) => p.startsWith('.') || p === '__MACOSX')) continue;
    const dir = rel(f).includes('/') ? rel(f).slice(0, rel(f).lastIndexOf('/')) : '';
    if (!folders.has(dir)) folders.set(dir, []);
    folders.get(dir).push(f);
  }
  const skipped = list.length - archives.length - [...folders.values()].reduce((n, a) => n + a.length, 0);
  if (skipped) toast(`${skipped} file${skipped === 1 ? '' : 's'} skipped (not an archive or image)`);

  for (const f of archives) queue(f.name, f.size, () => f);
  for (const [dir, images] of folders) {
    const name = `${(dir.split('/').pop() || 'Imported images')}.cbz`;
    queue(name, null, () => packCbz(images.sort((a, b) => collator.compare(rel(a), rel(b)))));
  }
  pump();
}

/** Store-only ZIP (images are already compressed) built in the browser. */
function packCbz(images) {
  return new Promise((resolve, reject) => {
    const parts = [];
    const zip = new Zip((err, chunk, final) => {
      if (err) return reject(err);
      parts.push(chunk);
      if (final) resolve(new Blob(parts, { type: 'application/zip' }));
    });
    (async () => {
      const width = String(images.length).length;
      for (const [i, img] of images.entries()) {
        const ext = img.name.split('.').pop().toLowerCase();
        const entry = new ZipPassThrough(`${String(i + 1).padStart(Math.max(3, width), '0')}.${ext}`);
        zip.add(entry);
        entry.push(new Uint8Array(await img.arrayBuffer()), true);
      }
      zip.end();
    })().catch(reject);
  });
}

// --- uploading -------------------------------------------------------------------------------

function queue(name, size, source) {
  const f = { id: state.nextId++, name, size, source, received: 0, status: 'queued', error: null, el: null };
  state.files.push(f);
  state.analysis = null;
  renderUpload(f);
}

const fmt = (n) => (n == null ? '' : n > 1e9 ? `${(n / 1e9).toFixed(2)} GB` : n > 1e6 ? `${(n / 1e6).toFixed(1)} MB` : `${Math.ceil(n / 1e3)} KB`);

function renderUpload(f) {
  ui.uploads.hidden = false;
  ui.drop?.classList.add('is-compact');
  if (!f.el) {
    f.el = document.createElement('li');
    ui.uploads.append(f.el);
  }
  const pct = f.size ? Math.round((f.received / f.size) * 100) : 0;
  f.el.className = `upload is-${f.status}`;
  f.el.innerHTML = `
    <span class="upload-name" title="${esc(f.name)}">${esc(f.name)}</span>
    <span class="upload-size">${fmt(f.size)}</span>
    <span class="upload-bar"><span style="width:${f.status === 'done' ? 100 : pct}%"></span></span>
    <span class="upload-state">${f.status === 'error' ? esc(f.error) : f.status === 'done' ? 'Uploaded' : f.status === 'packing' ? 'Packing…' : f.status === 'uploading' ? `${pct}%` : 'Waiting'}</span>`;
}

async function pump() {
  if (state.uploading) return;
  state.uploading = true;
  try {
    if (!state.batch) state.batch = (await api('api/import.php', { action: 'create' })).batch;
    for (let f; (f = state.files.find((x) => x.status === 'queued'));) await upload(f);
  } catch (e) {
    toast(e.message, 'error');
  } finally {
    state.uploading = false;
  }
  if (state.files.some((f) => f.status === 'done')) analyse();
}

async function upload(f) {
  try {
    if (f.size == null) { f.status = 'packing'; renderUpload(f); }
    const blob = await f.source();
    f.size = blob.size;
    f.status = 'uploading';
    renderUpload(f);
    let offset = 0;
    let attempts = 0;
    while (offset < f.size) {
      const chunk = blob.slice(offset, offset + CHUNK);
      const url = `api/import.php?batch=${state.batch}&file=${f.id}&name=${encodeURIComponent(f.name)}&offset=${offset}&total=${f.size}`;
      let r;
      try {
        const res = await fetch(url, { method: 'PUT', body: chunk, headers: { 'X-Requested-With': 'wankarr', 'Content-Type': 'application/octet-stream' } });
        r = await res.json();
        if (!res.ok) throw new Error(r.error || `HTTP ${res.status}`);
      } catch (e) {
        if (++attempts > 3 || /supported|reused/.test(e.message)) throw e;
        await new Promise((ok) => setTimeout(ok, 1000 * attempts));
        continue; // the server reports where to resume on the next attempt
      }
      attempts = 0;
      offset = r.received;
      f.received = offset;
      if (r.error) throw new Error(r.error);
      renderUpload(f);
    }
    f.status = 'done';
  } catch (e) {
    f.status = 'error';
    f.error = e.message;
  }
  f.source = null;
  renderUpload(f);
}

// --- analysis + decisions ---------------------------------------------------------------------

function setStatus(html) {
  ui.status.hidden = !html;
  ui.status.innerHTML = html || '';
}

async function analyse() {
  setStatus('<span class="spinner-sm"></span> Matching volumes to series…');
  try {
    state.analysis = await api('api/import.php', { action: 'analyse', batch: state.batch, library: libraryId() });
    // Groups that need a decision start excluded until one is made.
    for (const g of state.analysis.groups) for (const f of g.files) f.include = !g.target.review;
    setStatus('');
    await plan();
  } catch (e) {
    setStatus(`<span class="error">${esc(e.message)}</span>`);
  }
}

function decisions() {
  return state.analysis.groups.map((g) => ({
    target: g.target,
    files: g.files.map((f) => ({ id: f.id, volume: f.volume, chapter: f.chapter, include: f.include && !g.target.review })),
  }));
}

let planSeq = 0;
async function plan() {
  const mine = ++planSeq;
  try {
    const p = await api('api/import.php', { action: 'plan', batch: state.batch, groups: decisions() });
    if (mine !== planSeq) return;
    state.plan = p;
    render();
  } catch (e) {
    setStatus(`<span class="error">${esc(e.message)}</span>`);
  }
}
const planSoon = debounce(plan, 300);

function targetSummary(t) {
  if (t.type === 'existing') {
    return `<span class="target-kind">Add to existing series</span><strong>${esc(t.title)}</strong>${t.year ? ` <span class="muted">(${esc(t.year)})</span>` : ''}${t.hidden ? ' <span class="chip">hidden</span>' : ''}
      ${t.via === 'myanimelist' ? '<span class="muted"> · matched via MyAnimeList</span>' : ''}`;
  }
  if (t.review) {
    return `<span class="target-kind warn">No confident match. Choose one to import</span><strong>${esc(t.title)}</strong>
      ${t.suggestion ? `<button type="button" class="link-button js-use-suggestion">Use closest result: ${esc(t.suggestion.title)}${t.suggestion.year ? ` (${esc(t.suggestion.year)})` : ''}</button>` : ''}
      <button type="button" class="link-button js-title-only">Import as “${esc(t.title)}” without metadata</button>`;
  }
  if (t.provider_id) {
    return `<span class="target-kind">New series from MyAnimeList</span><strong>${esc(t.title)}</strong>${t.year ? ` <span class="muted">(${esc(t.year)})</span>` : ''}
      ${t.score != null ? `<span class="chip ${t.score >= 0.86 ? 'chip-ok' : ''}">${Math.round(t.score * 100)}%</span>` : ''}`;
  }
  return `<span class="target-kind">New series without metadata</span><strong>${esc(t.title)}</strong>${t.year ? ` <span class="muted">(${esc(t.year)})</span>` : ''}`;
}

function render() {
  const p = state.plan;
  ui.groups.innerHTML = state.analysis.groups.map((g, gi) => {
    const pg = p.groups[gi] || { files: [] };
    const cover = g.target.cover || (g.target.type === 'existing' ? `api/cover.php?series=${g.target.series_id}` : null);
    return `
      <section class="import-group card${g.target.review ? ' needs-review' : ''}" data-group="${gi}">
        <header class="import-group-head">
          ${cover ? `<img src="${esc(cover)}" alt="" referrerpolicy="no-referrer">` : '<div class="import-noimg"></div>'}
          <div class="import-target">${targetSummary(g.target)}
            <div class="muted import-parsed">From file names: “${esc(g.title)}”${g.year ? ` (${esc(g.year)})` : ''} · ${pg.folder ? `into <code>${esc(pg.folder)}/</code>` : ''}</div>
          </div>
          <button type="button" class="button js-change">Change…</button>
        </header>
        <div class="table-wrap"><table class="table import-files">
          <thead><tr><th></th><th>File</th><th>Vol.</th><th>Ch.</th><th>Destination</th></tr></thead>
          <tbody>${g.files.map((f, fi) => {
            const pf = pg.files.find((x) => x.id === f.id) || {};
            const problem = f.include && !g.target.review && pf.status && pf.status !== 'ok';
            return `<tr data-file="${fi}" class="${f.include && !g.target.review ? '' : 'is-excluded'}">
              <td><input type="checkbox" class="js-include" ${f.include ? 'checked' : ''} ${g.target.review ? 'disabled' : ''} aria-label="Import ${esc(f.name)}"></td>
              <td class="import-name" title="${esc(f.name)}">${esc(f.name)}<div class="muted">${f.pages ?? '?'} pages · ${fmt(f.size)}</div></td>
              <td><input class="input input-num js-vol" value="${f.volume ?? ''}" inputmode="decimal" aria-label="Volume number"></td>
              <td><input class="input input-num js-ch" value="${f.chapter ?? ''}" inputmode="decimal" aria-label="Chapter number"></td>
              <td class="import-dest">${pf.to ? `<code>${esc(pf.to)}</code>` : ''}
                ${problem ? `<div class="import-problem">${esc(pf.message)}</div>` : ''}
                ${pf.warning && f.include ? `<div class="import-warning">${esc(pf.warning)}</div>` : ''}</td>
            </tr>`;
          }).join('')}</tbody>
        </table></div>
      </section>`;
  }).join('');

  const c = p.counts;
  ui.footer.hidden = false;
  ui.summary.innerHTML = `<strong>${c.import}</strong> volume${c.import === 1 ? '' : 's'} ready` +
    (c.problems ? ` · <span class="error">${c.problems} with problems</span>` : '') +
    (state.analysis.groups.some((g) => g.target.review) ? ' · <span class="warn">some series need a match</span>' : '');
  ui.commit.disabled = c.import === 0 || state.busy;
  ui.commit.textContent = c.import ? `Import ${c.import} volume${c.import === 1 ? '' : 's'}` : 'Import';
}

// Edits inside groups.
ui.groups.addEventListener('change', (e) => {
  const row = e.target.closest('tr[data-file]');
  const group = state.analysis?.groups[+e.target.closest('[data-group]')?.dataset.group];
  if (!row || !group) return;
  const f = group.files[+row.dataset.file];
  if (e.target.classList.contains('js-include')) f.include = e.target.checked;
  const num = (v) => (v.trim() === '' || Number.isNaN(+v) ? null : +v);
  if (e.target.classList.contains('js-vol')) f.volume = num(e.target.value);
  if (e.target.classList.contains('js-ch')) f.chapter = num(e.target.value);
  planSoon();
});

ui.groups.addEventListener('click', (e) => {
  const gi = +e.target.closest('[data-group]')?.dataset.group;
  const group = state.analysis?.groups[gi];
  if (!group) return;
  if (e.target.closest('.js-change')) return openChange(group);
  if (e.target.closest('.js-title-only')) return setTarget(group, { type: 'new', title: group.target.title, year: group.target.year, provider_id: null });
  if (e.target.closest('.js-use-suggestion')) {
    const s = group.target.suggestion;
    return setTarget(group, { type: 'new', title: s.title, year: s.year, provider_id: s.id, score: group.target.score });
  }
});

function setTarget(group, target) {
  const wasReview = group.target.review;
  group.target = { review: false, ...target };
  if (wasReview) for (const f of group.files) f.include = true;
  plan();
}

// --- change-match modal ---------------------------------------------------------------------

function openChange(group) {
  const body = document.createElement('div');
  body.innerHTML = `
    <div class="tabs" role="tablist">
      <button type="button" class="tab is-active" data-tab="mal">MyAnimeList</button>
      <button type="button" class="tab" data-tab="library">Existing series</button>
      <button type="button" class="tab" data-tab="title">Title only</button>
    </div>
    <div class="tab-panel" data-panel="mal">
      <form class="match-search"><input type="search" class="input" value="${esc(group.title)}" aria-label="Search MyAnimeList"><button class="button primary">Search</button></form>
      <div class="match-results"></div>
    </div>
    <div class="tab-panel" data-panel="library" hidden></div>
    <div class="tab-panel" data-panel="title" hidden>
      <form class="form title-form">
        <label class="field"><span>Series title</span><input class="input" name="title" value="${esc(group.target.title || group.title)}" required></label>
        <label class="field"><span>Year <em class="muted">optional</em></span><input class="input" name="year" value="${esc(group.target.year ?? group.year ?? '')}" inputmode="numeric"></label>
        <p class="muted help">Imports without metadata. The series is listed under Needs Review so you can match it later.</p>
        <button class="button primary">Use this title</button>
      </form>
    </div>`;
  const m = modal({ title: `Series for “${group.title}”`, body, wide: true });

  for (const tab of body.querySelectorAll('.tab')) {
    tab.addEventListener('click', () => {
      for (const t of body.querySelectorAll('.tab')) t.classList.toggle('is-active', t === tab);
      for (const p of body.querySelectorAll('.tab-panel')) p.hidden = p.dataset.panel !== tab.dataset.tab;
      if (tab.dataset.tab === 'library' && !body.querySelector('[data-panel=library] .picker')) {
        body.querySelector('[data-panel=library]').append(seriesPicker({
          library: libraryId(), placeholder: 'Find a series in your library…',
          onPick: (s) => { setTarget(group, { type: 'existing', series_id: s.id, title: s.title }); m.close(); },
        }));
        body.querySelector('[data-panel=library] input').focus();
      }
    });
  }

  const results = body.querySelector('.match-results');
  const input = body.querySelector('.match-search input');
  let seq = 0;
  let found = [];
  const search = async () => {
    const mine = ++seq;
    results.innerHTML = '<div class="spinner"></div>';
    try {
      const data = await api(`api/metadata-search.php?q=${encodeURIComponent(input.value)}`);
      if (mine !== seq) return;
      found = data.results;
      results.innerHTML = found.length ? found.map((c, i) => `
        <div class="match-row">
          ${c.cover ? `<img src="${esc(c.cover)}" alt="" loading="lazy" referrerpolicy="no-referrer">` : '<div class="match-noimg"></div>'}
          <div class="match-info">
            <div class="match-title">${esc(c.title)}${c.title_english && c.title_english !== c.title ? ` <span class="muted">· ${esc(c.title_english)}</span>` : ''}</div>
            <div class="match-meta">${[c.type, c.year, c.status, c.volumes ? `${c.volumes} vols` : null, (c.authors || []).slice(0, 2).join(', ')].filter(Boolean).map(esc).join(' · ')}</div>
            ${c.synopsis ? `<div class="match-synopsis">${esc(c.synopsis)}</div>` : ''}
          </div>
          <div class="match-side"><span class="match-score ${c.match >= data.threshold ? 'is-good' : c.match >= 0.6 ? 'is-ok' : 'is-poor'}">${Math.round(c.match * 100)}%</span>
            <button type="button" class="button primary" data-pick="${i}">Select</button></div>
        </div>`).join('') : '<p class="muted match-empty">No results. Try the Japanese/romaji title, or fewer words.</p>';
    } catch (e) {
      if (mine === seq) results.innerHTML = `<p class="match-empty error">${esc(e.message)}</p>`;
    }
  };
  body.querySelector('.match-search').addEventListener('submit', (e) => { e.preventDefault(); search(); });
  results.addEventListener('click', (e) => {
    const b = e.target.closest('[data-pick]');
    if (!b) return;
    const c = found[+b.dataset.pick];
    setTarget(group, { type: 'new', title: c.preferred_title || c.title, year: c.year, provider_id: c.id, cover: c.cover, score: c.match });
    m.close();
  });
  body.querySelector('.title-form').addEventListener('submit', (e) => {
    e.preventDefault();
    const f = e.target;
    setTarget(group, { type: 'new', title: f.title.value.trim(), year: f.year.value.trim() ? +f.year.value : null, provider_id: null });
    m.close();
  });
  search();
}

// --- commit / discard ---------------------------------------------------------------------------

ui.commit.addEventListener('click', async () => {
  if (!state.plan) return;
  state.busy = true;
  ui.commit.disabled = true;
  ui.commit.textContent = 'Importing…';
  try {
    const r = await api('api/import.php', { action: 'commit', batch: state.batch, groups: decisions(), token: state.plan.token });
    const errors = r.series.flatMap((s) => s.errors);
    ui.results.hidden = false;
    ui.results.innerHTML = `
      <div class="card">
        <h2>Imported</h2>
        <ul class="import-done">${r.series.map((s) => `<li>${s.series_id ? `<a href="series.php?id=${s.series_id}">${esc(s.title)}</a>` : esc(s.title)}: ${s.imported} volume${s.imported === 1 ? '' : 's'}</li>`).join('')}</ul>
        ${errors.length ? `<div class="alert alert-danger"><div><strong>Some files couldn't be imported:</strong><ul>${errors.map((e) => `<li>${esc(e)}</li>`).join('')}</ul></div></div>` : ''}
        <div class="end-actions"><a class="button primary" href="./">Go to library</a><button type="button" class="button js-again">Import more</button></div>
      </div>`;
    ui.results.querySelector('.js-again').addEventListener('click', reset);
    ui.groups.innerHTML = '';
    ui.footer.hidden = true;
    toast(errors.length ? 'Import finished with errors' : 'Import finished', errors.length ? 'error' : 'info');
    if (!r.batch_cleared) await api('api/import.php', { action: 'discard', batch: state.batch }).catch(() => {});
    state.batch = null;
  } catch (e) {
    toast(e.message, 'error');
    if (/changed since the preview/.test(e.message)) await plan();
  } finally {
    state.busy = false;
    if (state.plan && !ui.footer.hidden) render();
  }
});

async function reset() {
  if (state.batch) await api('api/import.php', { action: 'discard', batch: state.batch }).catch(() => {});
  Object.assign(state, { batch: null, files: [], analysis: null, plan: null });
  ui.uploads.innerHTML = '';
  ui.uploads.hidden = true;
  ui.groups.innerHTML = '';
  ui.results.hidden = true;
  ui.footer.hidden = true;
  ui.drop?.classList.remove('is-compact');
  setStatus('');
}
$('.js-discard').addEventListener('click', () => { if (!state.uploading) reset(); });
addEventListener('beforeunload', (e) => { if (state.uploading || state.analysis) e.preventDefault(); });
