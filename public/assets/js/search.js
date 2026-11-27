// Search page: a single box that suggests facets as you type (genres,
// authors, status, type, rating, year, series). Picked facets and free text
// become pills; results refresh live.
import { api, esc, debounce } from './ui.js';
import { decoratePosters } from './library.js';

const box = document.querySelector('.js-facet-search');
const input = box.querySelector('.js-facet-input');
const pillsEl = box.querySelector('.js-pills');
const suggestEl = box.querySelector('.js-facet-suggest');
const results = document.querySelector('.js-results');

let pills = JSON.parse(box.dataset.pills || '[]');
let items = [];
let active = -1;

const key = (p) => `${p.kind}:${p.value}`;

function renderPills() {
  pillsEl.innerHTML = pills.map((p, i) => `
    <span class="pill pill-${esc(p.kind)}"><span class="pill-group">${esc(p.group)}:</span> ${esc(p.label)}
      <button type="button" data-remove="${i}" aria-label="Remove ${esc(p.group)} ${esc(p.label)}">×</button></span>`).join('');
  input.placeholder = pills.length ? 'Add more…' : 'Title, author, genre, status, year…';
}

function params(includeTyped) {
  const sp = new URLSearchParams();
  for (const p of pills) sp.append(`${p.kind}[]`, p.value);
  const typed = input.value.trim();
  if (includeTyped && typed) sp.append('q[]', typed);
  return sp;
}

let resultsSeq = 0;
async function refresh(includeTyped = true) {
  const sp = params(includeTyped);
  history.replaceState(null, '', `search.php${pills.length ? `?${params(false)}` : ''}`);
  const mine = ++resultsSeq;
  results.classList.add('is-loading');
  try {
    const html = await (await fetch(`search.php?partial=1&${sp}`)).text();
    if (mine !== resultsSeq) return;
    results.innerHTML = html;
    decoratePosters(results);
  } finally {
    if (mine === resultsSeq) results.classList.remove('is-loading');
  }
}
const refreshSoon = debounce(() => refresh(true), 350);

function addPill(p) {
  if (!pills.some((x) => key(x) === key(p))) pills.push(p);
  input.value = '';
  hideSuggest();
  renderPills();
  refresh(false);
  input.focus();
}

function removePill(i) {
  pills.splice(i, 1);
  renderPills();
  refresh(true);
  input.focus();
}

// --- suggestions ---------------------------------------------------------------------

function hideSuggest() {
  suggestEl.hidden = true;
  input.setAttribute('aria-expanded', 'false');
  active = -1;
}

function highlight(i) {
  active = i;
  [...suggestEl.querySelectorAll('.suggest-item')].forEach((el, k) => el.classList.toggle('is-active', k === i));
  suggestEl.querySelectorAll('.suggest-item')[i]?.scrollIntoView({ block: 'nearest' });
}

function renderSuggest(q, list) {
  const taken = new Set(pills.map(key));
  items = [
    ...list.filter((s) => s.kind === 'series' || !taken.has(key(s))),
    { kind: 'q', value: q, group: 'Text', label: q },
  ];
  suggestEl.innerHTML = items.map((s, i) => `
    <div class="suggest-item${s.kind === 'q' ? ' suggest-text' : ''}" role="option" data-i="${i}">
      ${s.kind === 'series' ? `<img src="api/cover.php?series=${esc(s.value)}" alt="" loading="lazy">` : ''}
      <span class="suggest-group">${esc(s.kind === 'q' ? 'Search for' : s.group)}</span>
      <span class="suggest-label">${s.kind === 'q' ? `“${esc(s.label)}”` : esc(s.label)}${s.hint ? ` <span class="muted">(${esc(s.hint)})</span>` : ''}</span>
      ${s.count !== undefined && s.kind !== 'q' ? `<span class="suggest-count">${s.kind === 'series' ? `${s.count} vol` : s.count}</span>` : ''}
    </div>`).join('');
  suggestEl.hidden = false;
  input.setAttribute('aria-expanded', 'true');
  highlight(items.length > 1 ? 0 : -1);
}

let suggestSeq = 0;
const suggest = debounce(async () => {
  const q = input.value.trim();
  if (!q) return hideSuggest();
  const mine = ++suggestSeq;
  try {
    const data = await api(`api/search.php?q=${encodeURIComponent(q)}`);
    if (mine === suggestSeq && input.value.trim() === q) renderSuggest(q, data.items);
  } catch { hideSuggest(); }
}, 150);

function choose(i) {
  const s = items[i];
  if (!s) return;
  if (s.kind === 'series') {
    location.href = `series.php?id=${s.value}`;
    return;
  }
  addPill({ kind: s.kind, value: s.value, group: s.group, label: s.label });
}

input.addEventListener('input', () => { suggest(); refreshSoon(); });
input.addEventListener('focus', () => { if (input.value.trim()) suggest(); });
input.addEventListener('keydown', (e) => {
  if (e.key === 'ArrowDown' && !suggestEl.hidden) { e.preventDefault(); highlight(Math.min(items.length - 1, active + 1)); }
  else if (e.key === 'ArrowUp' && !suggestEl.hidden) { e.preventDefault(); highlight(Math.max(0, active - 1)); }
  else if (e.key === 'Enter') {
    e.preventDefault();
    const q = input.value.trim();
    if (!suggestEl.hidden && active >= 0) choose(active);
    else if (q) addPill({ kind: 'q', value: q, group: 'Text', label: q });
  } else if (e.key === 'Escape') hideSuggest();
  else if (e.key === 'Backspace' && input.value === '' && pills.length) removePill(pills.length - 1);
});
suggestEl.addEventListener('mousedown', (e) => {
  const el = e.target.closest('.suggest-item');
  if (!el) return;
  e.preventDefault(); // keep focus in the input
  choose(+el.dataset.i);
});
pillsEl.addEventListener('click', (e) => {
  const b = e.target.closest('[data-remove]');
  if (b) removePill(+b.dataset.remove);
});
box.querySelector('.facet-box').addEventListener('click', (e) => { if (e.target === e.currentTarget) input.focus(); });
document.addEventListener('click', (e) => { if (!box.contains(e.target)) hideSuggest(); });

renderPills();
