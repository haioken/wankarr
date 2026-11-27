// Users page (admins): add, edit, approve and delete accounts.
import { api, esc, modal, buttons, toast } from './ui.js';

const page = document.querySelector('.users-page');
const users = JSON.parse(page.dataset.users);
const ratings = JSON.parse(page.dataset.ratings);
const meId = +page.dataset.me;

function form(u = null) {
  const f = document.createElement('form');
  f.className = 'form';
  const opt = (v, label, cur) => `<option value="${esc(v)}"${v === cur ? ' selected' : ''}>${esc(label)}</option>`;
  f.innerHTML = `
    ${u ? '' : '<label class="field"><span>Username</span><input class="input" name="username" required autofocus pattern="[A-Za-z0-9._\\-]{3,32}"></label>'}
    <label class="field"><span>Email <em class="muted">optional</em></span><input class="input" type="email" name="email" value="${esc(u?.email ?? '')}"></label>
    <label class="field"><span>${u ? 'New password <em class="muted">leave blank to keep</em>' : 'Password <em class="muted">at least 8 characters</em>'}</span>
      <input class="input" type="password" name="password" minlength="8" autocomplete="new-password"${u ? '' : ' required'}></label>
    <div class="field-row">
      <label class="field"><span>Role</span><select name="role"${u?.id === meId ? ' disabled' : ''}>${opt('user', 'User: read and hide', u?.role ?? 'user')}${opt('admin', 'Admin: manage everything', u?.role)}</select></label>
      ${u ? `<label class="field"><span>Status</span><select name="status"${u.id === meId ? ' disabled' : ''}>
        ${opt('active', 'Active', u.status)}${opt('disabled', 'Disabled', u.status)}${u.status === 'pending' ? opt('pending', 'Awaiting approval', u.status) : ''}${u.status === 'unverified' ? opt('unverified', 'Email not verified', u.status) : ''}</select></label>` : ''}
    </div>
    <label class="field"><span>Content shown</span><select name="max_rating">
      ${opt('', 'Everything', u?.max_rating ?? '')}
      ${Object.entries(ratings).map(([k, label]) => opt(k, `Up to ${label}`, u?.max_rating)).join('')}
    </select></label>
    <label class="check-row"><input type="checkbox" name="show_unrated"${(u?.show_unrated ?? true) ? ' checked' : ''}>
      <span>Show series without a content rating<br><span class="muted">Only applies when content is limited. Series that haven't been matched to metadata have no rating.</span></span></label>`;
  return f;
}

function values(f) {
  const d = Object.fromEntries(new FormData(f));
  d.show_unrated = f.elements.show_unrated.checked;
  return d;
}

async function submit(m, payload) {
  m.setBusy(true);
  try {
    await api('api/users.php', payload);
    location.reload();
  } catch (e) { toast(e.message, 'error'); m.setBusy(false); }
}

document.querySelector('.js-add-user').addEventListener('click', () => {
  const f = form();
  const m = modal({ title: 'Add user', body: f });
  const save = () => f.reportValidity() && submit(m, { action: 'create', ...values(f) });
  f.addEventListener('submit', (e) => { e.preventDefault(); save(); });
  buttons(m, [{ label: 'Cancel' }, { label: 'Add user', primary: true, onClick: save }]);
});

for (const row of document.querySelectorAll('tr[data-user]')) {
  const u = users.find((x) => x.id === +row.dataset.user);
  row.querySelector('.js-approve')?.addEventListener('click', async () => {
    try { await api('api/users.php', { action: 'update', id: u.id, status: 'active' }); location.reload(); } catch (e) { toast(e.message, 'error'); }
  });
  row.querySelector('.js-edit-user').addEventListener('click', () => {
    const f = form(u);
    const m = modal({ title: `Edit ${u.username}`, body: f });
    const save = () => f.reportValidity() && submit(m, { action: 'update', id: u.id, ...values(f) });
    f.addEventListener('submit', (e) => { e.preventDefault(); save(); });
    buttons(m, [
      ...(u.id === meId ? [] : [{ label: 'Delete user', danger: true, onClick: () => {
        m.body.innerHTML = `<p>Delete <strong>${esc(u.username)}</strong>? Their reading progress and hidden series go too. Library files aren't touched.</p>`;
        m.footer.innerHTML = '';
        buttons(m, [{ label: 'Cancel' }, { label: 'Delete', danger: true, onClick: () => submit(m, { action: 'delete', id: u.id }) }]);
      } }]),
      { label: 'Cancel' },
      { label: 'Save', primary: true, onClick: save },
    ]);
  });
}
