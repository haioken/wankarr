// Settings → Media Management: live naming examples, token insertion, save.
import { api, esc, debounce, toast } from './ui.js';

const form = document.querySelector('.js-naming');
const examples = document.querySelector('.js-examples');
const error = document.querySelector('.js-naming-error');
let lastField = form.querySelector('input.js-template');
let dirty = false;

const values = () => Object.fromEntries(new FormData(form));

function showExamples(data) {
  examples.innerHTML = Object.entries(data.examples).map(([k, v]) => `<div><dt>${esc(k)}</dt><dd><code>${esc(v)}</code></dd></div>`).join('');
}

const preview = debounce(async () => {
  try {
    showExamples(await api('api/naming.php', { action: 'preview', settings: values() }));
    error.hidden = true;
    examples.classList.remove('is-stale');
  } catch (e) {
    error.textContent = e.message;
    error.hidden = false;
    examples.classList.add('is-stale');
  }
}, 250);

for (const el of form.querySelectorAll('.js-template')) {
  const track = () => { if (el.tagName === 'INPUT') lastField = el; };
  el.addEventListener('input', () => { track(); dirty = true; preview(); });
  el.addEventListener('change', () => { dirty = true; preview(); });
  for (const ev of ['focus', 'click', 'keyup']) el.addEventListener(ev, track);
}

for (const btn of document.querySelectorAll('.js-token')) {
  btn.addEventListener('click', () => {
    const f = lastField;
    const start = f.selectionStart ?? f.value.length;
    const end = f.selectionEnd ?? f.value.length;
    f.value = f.value.slice(0, start) + btn.dataset.token + f.value.slice(end);
    f.focus();
    f.setSelectionRange(start + btn.dataset.token.length, start + btn.dataset.token.length);
    dirty = true;
    preview();
  });
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    showExamples(await api('api/naming.php', { action: 'save', settings: values() }));
    error.hidden = true;
    dirty = false;
    toast('Naming settings saved');
  } catch (err) {
    error.textContent = err.message;
    error.hidden = false;
    toast(err.message, 'error');
  }
});

document.querySelector('.js-reset').addEventListener('click', () => {
  const defaults = JSON.parse(form.dataset.defaults);
  for (const [k, v] of Object.entries(defaults)) form.elements[k].value = v;
  dirty = true;
  preview();
  toast('Defaults restored. Save to keep them.');
});

addEventListener('beforeunload', (e) => { if (dirty) e.preventDefault(); });
