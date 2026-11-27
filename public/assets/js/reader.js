// Reader controller: loads a volume from the server, drives the FlipBook,
// and wires up the toolbars, settings, keyboard, wheel and progress.
import { FlipBook } from './flipbook.js';
import { saveProgress, savePrefs } from './progress.js';

const root = document.getElementById('reader');
const $ = (sel) => root.querySelector(sel);
let bookId = root.dataset.id || null;
let title = '';
let total = 0;
let book = null;
let nextBook = null;
let prevBook = null;

const ui = {
  stage: $('.js-stage'),
  slider: $('.js-slider'),
  count: $('.js-count'),
  total: $('.js-total'),
  settings: $('.js-settings'),
  settingsToggle: $('.js-settings-toggle'),
  coverAlone: $('.js-cover-alone'),
  toast: $('.js-toast'),
  loading: $('.js-loading'),
  end: $('.js-end'),
};

// --- preferences ---------------------------------------------------------------

const defaults = { direction: root.dataset.defaultDirection === 'ltr' ? 'ltr' : 'rtl', layout: 'auto', coverAlone: true };
let prefs = { ...defaults };
let serverProgress = null; // { page, total, cover_alone } for this volume
let serverPrefs = {};       // { direction, layout } for this user

function applyPrefsUI() {
  for (const group of root.querySelectorAll('.segmented')) {
    for (const b of group.children) {
      const on = b.dataset.value === prefs[group.dataset.pref];
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-checked', on);
    }
  }
  ui.coverAlone.checked = prefs.coverAlone;
  ui.slider.dir = prefs.direction === 'rtl' ? 'rtl' : 'ltr';
  root.classList.toggle('is-rtl', prefs.direction === 'rtl');
}

function setPref(key, value) {
  prefs = { ...prefs, [key]: value };
  savePrefs(bookId, prefs);
  applyPrefsUI();
  book?.configure(prefs);
}

for (const group of root.querySelectorAll('.segmented')) {
  group.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-value]');
    if (b) setPref(group.dataset.pref, b.dataset.value);
  });
}
ui.coverAlone.addEventListener('change', () => setPref('coverAlone', ui.coverAlone.checked));

ui.settingsToggle.addEventListener('click', () => toggleSettings());
function toggleSettings(force) {
  const open = force ?? ui.settings.hidden;
  ui.settings.hidden = !open;
  ui.settingsToggle.setAttribute('aria-expanded', open);
  ui.settingsToggle.classList.toggle('is-active', open);
  if (open) showUI(true);
}

// --- chrome visibility -------------------------------------------------------------

function showUI(on = !root.classList.contains('ui-visible')) {
  root.classList.toggle('ui-visible', on);
  if (!on) toggleSettings(false);
}

let toastTimer;
function toast(msg) {
  ui.toast.textContent = msg;
  ui.toast.classList.add('is-visible');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => ui.toast.classList.remove('is-visible'), 1800);
}

// Desktop: reveal bars when the mouse nears the top or bottom edge.
root.addEventListener('pointermove', (e) => {
  if (e.pointerType !== 'mouse' || e.buttons) return;
  const edge = e.clientY < 70 || e.clientY > window.innerHeight - 70;
  if (edge && !root.classList.contains('ui-visible')) showUI(true);
});

// --- fullscreen -------------------------------------------------------------------

const fsBtn = $('.js-fullscreen');
if (!document.fullscreenEnabled) fsBtn.hidden = true;
function toggleFullscreen() {
  if (document.fullscreenElement) document.exitFullscreen();
  else document.documentElement.requestFullscreen?.().catch(() => {});
}
fsBtn.addEventListener('click', toggleFullscreen);
document.addEventListener('fullscreenchange', () => root.classList.toggle('is-fullscreen', !!document.fullscreenElement));

// --- page indicator + slider ------------------------------------------------------------

function onChange({ pages, view, views }) {
  const first = Math.min(...pages), last = Math.max(...pages);
  ui.count.textContent = pages.length ? (first === last ? `${first + 1}` : `${first + 1}–${last + 1}`) : '';
  ui.slider.value = pages.length ? first : 0;
  if (bookId && pages.length) {
    saveProgress(bookId, last, total);
    history.replaceState(null, '', `${location.pathname}${location.search}#p=${first + 1}`);
  }
  if (view > 0 && view < views - 1) ui.end.hidden = true;
}

ui.slider.addEventListener('input', () => {
  const p = +ui.slider.value;
  ui.count.textContent = `${p + 1}`;
});
ui.slider.addEventListener('change', () => book?.goToPage(+ui.slider.value));

// --- end / start of book ---------------------------------------------------------------

function onEdge(where) {
  if (where === 'end') {
    $('.js-end-title').textContent = title;
    const next = $('.js-end-next');
    next.hidden = !nextBook;
    if (nextBook) next.href = `read.php?id=${encodeURIComponent(nextBook.id)}`;
    $('.js-end-next-label').textContent = nextBook ? `Up next: ${nextBook.title}` : 'You have reached the last page.';
    ui.end.hidden = false;
    (nextBook ? next : $('.js-end-close')).focus();
  } else {
    toast(prevBook ? `First page · previous: ${prevBook.title}` : 'First page');
  }
}
$('.js-end-close').addEventListener('click', () => { ui.end.hidden = true; });

// --- input ---------------------------------------------------------------------------

function onTap() {
  if (!ui.settings.hidden) return toggleSettings(false);
  showUI();
}

for (const b of root.querySelectorAll('.js-side')) {
  b.addEventListener('click', () => book?.flip(b.dataset.side));
}

document.addEventListener('keydown', (e) => {
  const target = e.target instanceof Element ? e.target : document.body;
  if (!book || (target.closest('input, textarea, select') && target.type !== 'range')) return;
  if (e.altKey || e.ctrlKey || e.metaKey) return;
  if ((e.key === ' ' || e.key === 'Enter') && target.closest('button, a, label')) return;
  const handled = {
    ArrowLeft: () => book.flip('L'),
    ArrowRight: () => book.flip('R'),
    ' ': () => (e.shiftKey ? book.prev() : book.next()),
    PageDown: () => book.next(),
    PageUp: () => book.prev(),
    ArrowDown: () => book.next(),
    ArrowUp: () => book.prev(),
    Home: () => book.goToView(0),
    End: () => book.goToView(book.views.length - 1),
    f: toggleFullscreen,
    m: () => showUI(),
    Escape: () => (!ui.end.hidden ? (ui.end.hidden = true) : !ui.settings.hidden ? toggleSettings(false) : showUI(false)),
  }[e.key];
  if (!handled) return;
  if (target.type === 'range' && e.key.startsWith('Arrow')) return; // let the slider handle its own arrows
  e.preventDefault();
  handled();
});

let wheelLock = 0;
ui.stage.addEventListener('wheel', (e) => {
  if (!book || Math.abs(e.deltaY) < 4) return;
  e.preventDefault();
  const now = performance.now();
  if (now < wheelLock) return;
  wheelLock = now + 350;
  e.deltaY > 0 ? book.next() : book.prev();
}, { passive: false });

// --- loading ----------------------------------------------------------------------------

function startPageFor() {
  const hash = /#p=(\d+)/.exec(location.hash);
  if (hash) return Math.max(0, +hash[1] - 1);
  const saved = serverProgress;
  // Reopening a finished book starts again from the beginning.
  if (saved && saved.page + 1 < saved.total) return saved.page;
  return 0;
}

function start(pages, startPage) {
  total = pages.length;
  ui.slider.max = Math.max(0, total - 1);
  ui.total.textContent = total;
  prefs = { ...defaults, ...serverPrefs };
  if (serverProgress && serverProgress.cover_alone !== null && serverProgress.cover_alone !== undefined) prefs.coverAlone = !!serverProgress.cover_alone;
  applyPrefsUI();
  if (!book) book = new FlipBook(ui.stage, { onChange, onEdge, onTap });
  book.options = { ...book.options, ...prefs };
  book.setPages(pages, Math.min(startPage, total - 1));
  ui.loading.hidden = true;
  setTimeout(() => { if (ui.settings.hidden) showUI(false); }, 2500);
}

function fail(message) {
  ui.loading.hidden = true;
  $('.js-error-text').textContent = message;
  $('.js-error').hidden = false;
}

function setLink(sel, target, label) {
  const a = $(sel);
  if (!target) return;
  a.href = `read.php?id=${encodeURIComponent(target.id)}`;
  a.title = `${label}: ${target.title}`;
  a.hidden = false;
}

async function loadServer() {
  let data;
  try {
    const res = await fetch(`api/book.php?id=${encodeURIComponent(bookId)}`);
    data = await res.json();
    if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
  } catch (err) {
    return fail(err.message || 'The server could not read this volume.');
  }
  title = `${data.series.title} – ${data.title}`;
  document.title = `${title} · wankarr`;
  prevBook = data.prev;
  nextBook = data.next;
  setLink('.js-prev-book', data.prev, 'Previous');
  setLink('.js-next-book', data.next, 'Next');
  const v = encodeURIComponent(data.version);
  const id = encodeURIComponent(data.id);
  const pages = data.pages.map(([w, h], i) => ({ src: `api/page.php?id=${id}&p=${i}&v=${v}`, w, h }));
  serverProgress = data.progress;
  serverPrefs = data.prefs || {};
  start(pages, startPageFor());
}

applyPrefsUI();
loadServer();
