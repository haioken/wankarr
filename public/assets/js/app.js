// Global behaviour on every page: theme, sidebar drawer, header search
// suggestions, and background-task activity.
import { api, toast } from './ui.js';
import { migrateLocalState } from './progress.js';

// --- theme ------------------------------------------------------------------------

const THEME_KEY = 'wk:theme';
const ORDER = ['auto', 'light', 'dark'];
const LABEL = { auto: 'Theme: follow system', light: 'Theme: light', dark: 'Theme: dark' };
const currentTheme = () => document.documentElement.dataset.theme || 'auto';

function setTheme(mode) {
  const root = document.documentElement;
  if (mode === 'auto') delete root.dataset.theme;
  else root.dataset.theme = mode;
  try { mode === 'auto' ? localStorage.removeItem(THEME_KEY) : localStorage.setItem(THEME_KEY, mode); } catch {}
  for (const b of document.querySelectorAll('.js-theme')) b.title = LABEL[mode];
}

for (const btn of document.querySelectorAll('.js-theme')) {
  btn.title = LABEL[currentTheme()];
  btn.addEventListener('click', () => setTheme(ORDER[(ORDER.indexOf(currentTheme()) + 1) % ORDER.length]));
}

for (const t of document.querySelectorAll('.js-sidebar-toggle')) {
  t.addEventListener('click', () => document.body.classList.toggle('sidebar-open'));
}

// Toolbar menus. The toolbar scrolls horizontally on small screens, which
// clips anything absolutely positioned inside it, so open menus are pinned
// to the viewport under their button instead.
function placeMenu(d) {
  const panel = d.querySelector('.menu-items');
  if (!panel) return;
  const r = d.querySelector('summary').getBoundingClientRect();
  panel.style.position = 'fixed';
  panel.style.top = `${Math.round(r.bottom)}px`;
  panel.style.right = 'auto';
  const width = panel.offsetWidth;
  panel.style.left = `${Math.round(Math.max(8, Math.min(r.right - width, innerWidth - width - 8)))}px`;
}
for (const d of document.querySelectorAll('details.menu')) {
  d.addEventListener('toggle', () => {
    if (!d.open) return;
    for (const other of document.querySelectorAll('details.menu[open]')) if (other !== d) other.open = false;
    placeMenu(d);
  });
}
const closeMenus = () => { for (const d of document.querySelectorAll('details.menu[open]')) d.open = false; };
document.addEventListener('click', (e) => {
  for (const d of document.querySelectorAll('details.menu[open]')) if (!d.contains(e.target)) d.open = false;
});
addEventListener('resize', closeMenus);
document.querySelector('.page-toolbar')?.addEventListener('scroll', closeMenus);
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeMenus(); });

// --- background tasks -------------------------------------------------------------------

const activity = document.querySelector('.js-activity');
let polling = false;
let lastState = {};

function applyTasks(tasks) {
  const busy = tasks.filter((t) => t.running || t.queued);
  for (const t of tasks) {
    for (const b of document.querySelectorAll(`.js-run-task[data-task="${t.name}"]`)) {
      b.classList.toggle('is-spinning', t.running || t.queued);
    }
    const was = lastState[t.name];
    if (was && (was.running || was.queued) && !t.running && !t.queued) {
      document.dispatchEvent(new CustomEvent('wk:task-done', { detail: t }));
    }
    lastState[t.name] = t;
  }
  if (activity) {
    const running = busy.find((t) => t.running) || busy[0];
    activity.hidden = !running;
    if (running) activity.querySelector('.js-activity-text').textContent = running.running ? (running.message || running.label) : `${running.label} queued`;
  }
  const msg = document.querySelector('.js-scan-message');
  const scan = tasks.find((t) => t.name === 'scan');
  if (msg && scan) msg.textContent = scan.message || '';
  return busy.length > 0;
}

async function pollTasks() {
  if (polling) return;
  polling = true;
  try {
    for (;;) {
      const busy = applyTasks(await api('api/tasks.php'));
      if (!busy) break;
      await new Promise((r) => setTimeout(r, 2000));
    }
  } catch {}
  polling = false;
}

document.addEventListener('click', async (e) => {
  const btn = e.target.closest('.js-run-task');
  if (!btn) return;
  btn.classList.add('is-spinning');
  try {
    applyTasks(await api('api/tasks.php', { name: btn.dataset.task }));
    toast(`${btn.textContent.trim() || btn.dataset.task} started`);
    pollTasks();
  } catch (err) {
    btn.classList.remove('is-spinning');
    toast(err.message, 'error');
  }
});

if (document.body.dataset.role && !document.body.classList.contains('reader-page')) pollTasks();

export { pollTasks };

// Filter dropdowns (search page): navigate with the chosen value applied.
for (const sel of document.querySelectorAll('select.js-filter-select')) {
  sel.addEventListener('change', () => {
    const url = new URL(location.href);
    if (sel.value) url.searchParams.set(sel.dataset.param, sel.value);
    else url.searchParams.delete(sel.dataset.param);
    location.href = url.toString();
  });
}

// --- account ------------------------------------------------------------------------------

document.querySelector('.js-logout')?.addEventListener('click', async () => {
  try { await api('api/auth.php', { action: 'logout' }); } catch {}
  location.href = 'login.php';
});

if (document.body.dataset.role) migrateLocalState();
