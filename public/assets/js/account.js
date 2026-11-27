// Account page: email and password forms, sign out other sessions.
import { api, toast } from './ui.js';

for (const form of document.querySelectorAll('form.js-account')) {
  const msg = form.querySelector('.js-message');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(form));
    if (form.elements.confirm && data.password !== data.confirm) {
      msg.textContent = 'The passwords don\'t match.';
      msg.className = 'form-message error';
      msg.hidden = false;
      return;
    }
    delete data.confirm;
    try {
      const r = await api('api/account.php', { action: form.dataset.action, ...data });
      msg.textContent = r.message;
      msg.className = 'form-message ok';
      form.querySelectorAll('input[type=password]').forEach((i) => { i.value = ''; });
    } catch (err) {
      msg.textContent = err.message;
      msg.className = 'form-message error';
    }
    msg.hidden = false;
  });
}

document.querySelector('.js-signout-others')?.addEventListener('click', async () => {
  try {
    await api('api/account.php', { action: 'signout-others' });
    toast('Signed out everywhere else');
    setTimeout(() => location.reload(), 600);
  } catch (e) { toast(e.message, 'error'); }
});
