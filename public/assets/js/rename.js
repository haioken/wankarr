// Rename preview + commit modal. Only ever opened by a person clicking a
// button; it shows the current and the resulting folder structure, every
// problem found while validating, and applies exactly what was previewed.
import { api, esc, modal, buttons, toast } from './ui.js';

const FOLDER = '<svg class="icon icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M3 6.5A1.5 1.5 0 0 1 4.5 5h4.2l2 2.2h8.8A1.5 1.5 0 0 1 21 8.7v9.8a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18.5z"/></svg>';
const FILE = '<svg class="icon icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/></svg>';

/** Nested tree from [{path, note, isDir}] → HTML. */
function tree(entries) {
  const root = { dirs: new Map(), files: [] };
  for (const e of entries) {
    const parts = e.path.split('/');
    const leaf = e.isDir ? null : parts.pop();
    let node = root;
    for (const p of parts) {
      if (!node.dirs.has(p)) node.dirs.set(p, { dirs: new Map(), files: [], note: null });
      node = node.dirs.get(p);
    }
    if (leaf === null) node.note = e.note; else node.files.push({ name: leaf, note: e.note });
  }
  const collator = new Intl.Collator(undefined, { numeric: true });
  const render = (node) => {
    const dirs = [...node.dirs.entries()].sort((a, b) => collator.compare(a[0], b[0]));
    const files = node.files.sort((a, b) => collator.compare(a.name, b.name));
    return `<ul>${dirs.map(([name, d]) => `<li class="tree-dir">${FOLDER}<span>${esc(name)}</span>${d.note ? `<em>${esc(d.note)}</em>` : ''}${render(d)}</li>`).join('')}
      ${files.map((f) => `<li class="tree-file">${FILE}<span>${esc(f.name)}</span>${f.note ? `<em>${esc(f.note)}</em>` : ''}</li>`).join('')}</ul>`;
  };
  return entries.length ? render(root) : '<p class="muted">Nothing</p>';
}

const pagesNote = (i) => (i.page_count ? `${i.page_count} page${i.page_count === 1 ? '' : 's'} renamed` : null);

function renderPlan(plan, body) {
  const changes = plan.items.filter((i) => i.status === 'ok' && i.changed);
  const problems = [...plan.items.filter((i) => i.status !== 'ok'), ...plan.extras.filter((e) => e.status !== 'ok')];
  const extras = plan.extras.filter((e) => e.status === 'ok');
  const c = plan.counts;
  const warnings = plan.series.filter((s) => s.error || s.warning);

  const from = [...changes.map((i) => ({ path: i.from, isDir: i.kind === 'dir', note: i.kind === 'dir' ? 'image folder' : null })), ...extras.map((e) => ({ path: e.from }))];
  const to = [...changes.map((i) => ({ path: i.to, isDir: i.kind === 'dir', note: pagesNote(i) })), ...extras.map((e) => ({ path: e.to }))];

  body.innerHTML = `
    <div class="rename-summary">
      <span class="chip chip-ok">${c.rename} to rename</span>
      ${c.pages ? `<span class="chip">${c.pages} pages renumbered</span>` : ''}
      ${c.extras ? `<span class="chip">${c.extras} other files move along</span>` : ''}
      <span class="chip">${c.unchanged} already named correctly</span>
      ${c.problems ? `<span class="chip chip-warning">${c.problems} can't be renamed</span>` : ''}
    </div>
    ${warnings.map((s) => `<div class="alert">${esc(s.title)}: ${esc(s.error || s.warning)}</div>`).join('')}
    ${changes.length || extras.length ? `
      <div class="rename-trees">
        <section><h3>Now</h3><div class="tree">${tree(from)}</div></section>
        <section><h3>After renaming</h3><div class="tree">${tree(to)}</div></section>
      </div>
      <details class="rename-details"><summary>Every change (${changes.length + extras.length})</summary>
        <table class="table rename-table"><thead><tr><th>Volume</th><th>From</th><th>To</th></tr></thead><tbody>
          ${changes.map((i) => `<tr><td>${esc(i.title)}</td><td><code>${esc(i.from)}</code></td><td><code>${esc(i.to)}</code>${i.pages.length ? `<div class="muted pages-sample">${i.pages.map((p) => `${esc(p.from)} → ${esc(p.to)}`).join('<br>')}${i.page_count > i.pages.length ? `<br>…and ${i.page_count - i.pages.length} more` : ''}</div>` : ''}</td></tr>`).join('')}
          ${extras.map((e) => `<tr><td class="muted">Other file</td><td><code>${esc(e.from)}</code></td><td><code>${esc(e.to)}</code></td></tr>`).join('')}
        </tbody></table>
      </details>` : '<p class="rename-none">Everything already matches the naming convention.</p>'}
    ${problems.length ? `
      <h3 class="rename-problems-title">Won't be renamed</h3>
      <ul class="rename-problems">${problems.map((p) => `<li><span class="chip ${p.status === 'conflict' ? 'chip-warning' : 'chip-danger'}">${p.status === 'conflict' ? 'Conflict' : 'Error'}</span>
        <div><strong>${esc(p.title || p.from)}</strong> <code>${esc(p.from)}</code><div class="muted">${esc(p.message || '')}</div></div></li>`).join('')}</ul>` : ''}
    <p class="muted help">Naming is set in <a href="settings.php#naming">Settings → Media Management</a>. Files are only moved when you press Rename, never automatically.</p>`;
  return changes.length + extras.length;
}

/** Open the rename preview for one or more series. Resolves after closing. */
export function openRename(seriesIds, title = 'Rename preview') {
  const m = modal({ title, body: '<div class="spinner"></div>', wide: true });
  const ids = seriesIds.join(',');
  api(`api/rename.php?series=${ids}`).then((plan) => {
    const n = renderPlan(plan, m.body);
    m.footer.innerHTML = '';
    buttons(m, [
      { label: 'Cancel' },
      ...(n ? [{ label: `Rename ${plan.counts.rename} volume${plan.counts.rename === 1 ? '' : 's'}${plan.counts.extras && !plan.counts.rename ? ' files' : ''}`, primary: true, onClick: async () => {
        m.setBusy(true);
        try {
          const r = await api('api/rename.php', { series: seriesIds, token: plan.token });
          const msg = `${r.renamed} renamed` + (r.skipped ? `, ${r.skipped} skipped` : '') + (r.failed ? `, ${r.failed} failed` : '');
          toast(msg, r.failed ? 'error' : 'info');
          if (r.errors?.length) {
            m.body.innerHTML = `<div class="alert"><div><strong>Some renames failed and were rolled back:</strong><ul>${r.errors.map((e) => `<li>${esc(e)}</li>`).join('')}</ul></div></div>`;
            m.footer.innerHTML = '';
            buttons(m, [{ label: 'Close', onClick: () => location.reload() }]);
          } else {
            location.reload();
          }
        } catch (e) {
          toast(e.message, 'error');
          m.setBusy(false);
        }
      } }] : []),
    ]);
  }).catch((e) => { m.body.innerHTML = `<p class="error">${esc(e.message)}</p>`; });
  return m;
}
