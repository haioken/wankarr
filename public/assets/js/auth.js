// Sign-in, setup, sign-up and password reset forms.
const HEADERS = { 'Content-Type': 'application/json', 'X-Requested-With': 'wankarr' };

for (const form of document.querySelectorAll('form.js-auth')) {
  const error = form.querySelector('.js-error');
  const button = form.querySelector('button');
  const show = (msg, ok = false) => {
    error.textContent = msg;
    error.classList.toggle('is-ok', ok);
    error.hidden = !msg;
  };
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(form));
    data.remember = form.elements.remember?.checked ?? false;
    if (form.elements.confirm && data.password !== data.confirm) return show('The passwords don\'t match.');
    delete data.confirm;
    button.disabled = true;
    show('');
    try {
      const res = await fetch('api/auth.php', { method: 'POST', headers: HEADERS, body: JSON.stringify({ action: form.dataset.action, ...data }) });
      const body = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(body.error || `Something went wrong (HTTP ${res.status})`);
      if (body.next) { location.href = body.next; return; }
      if (body.message) {
        show(body.message, true);
        form.querySelectorAll('input:not([type=hidden])').forEach((i) => { i.value = ''; i.disabled = true; });
        return; // leave the button disabled: the form is done
      }
    } catch (err) {
      show(err.message);
    }
    button.disabled = false;
  });
}
