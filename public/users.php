<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$me = wk_require_admin();

$users = wk_users();
$pending = array_filter($users, fn($u) => $u['status'] === 'pending');
$limitLabel = fn(?string $r) => $r ? 'Up to ' . WK_CONTENT_RATINGS[$r] : 'Everything';

wk_page_start('Users', ['system', 'settings'], 'settings-users');
?>
<div class="page-toolbar">
  <div class="toolbar-section">
    <?= wk_toolbar_button('open', 'Add User', ['class' => 'js-add-user']) ?>
  </div>
</div>
<div class="content-body system users-page"
     data-me="<?= (int) $me['id'] ?>"
     data-ratings="<?= h(json_encode(array_slice(WK_CONTENT_RATINGS, 0, 3))) ?>"
     data-users="<?= h(json_encode(array_map(fn($u) => [
         'id' => (int) $u['id'], 'username' => $u['username'], 'email' => $u['email'], 'role' => $u['role'],
         'status' => $u['status'], 'max_rating' => $u['max_rating'], 'show_unrated' => (bool) $u['show_unrated'],
     ], $users))) ?>">

  <?php if ($pending): ?>
    <div class="alert"><?= wk_icon('warning') ?><div><strong><?= count($pending) ?> sign-up<?= count($pending) === 1 ? '' : 's' ?> waiting for approval.</strong> Approve or delete them below.</div></div>
  <?php endif ?>

  <section class="card">
    <h2>Users</h2>
    <div class="table-wrap">
      <table class="table users-table">
        <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Content</th><th>Last sign-in</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <tr data-user="<?= (int) $u['id'] ?>">
              <td><strong><?= h($u['username']) ?></strong><?= (int) $u['id'] === (int) $me['id'] ? ' <span class="chip">you</span>' : '' ?><div class="muted"><?= h($u['email'] ?? '') ?></div></td>
              <td><?= h(WK_ROLES[$u['role']] ?? $u['role']) ?></td>
              <td><span class="state <?= $u['status'] === 'active' ? 'ok' : ($u['status'] === 'disabled' ? 'bad' : 'warn') ?>"><?= h(WK_USER_STATUSES[$u['status']] ?? $u['status']) ?></span></td>
              <td><?= h($limitLabel($u['max_rating'])) ?><?= $u['max_rating'] && !$u['show_unrated'] ? '<div class="muted">unrated hidden</div>' : '' ?></td>
              <td><?= h(wk_ago($u['last_login_at'] ? (int) $u['last_login_at'] : null)) ?></td>
              <td class="actions">
                <?php if ($u['status'] === 'pending'): ?><button type="button" class="button primary js-approve">Approve</button><?php endif ?>
                <button type="button" class="button js-edit-user">Edit</button>
              </td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card">
    <h2>Sign-ups</h2>
    <dl class="info-list">
      <div><dt>Self sign-up</dt><dd><?= wk_signup_enabled() ? '<span class="state ok">On</span>' : '<span class="state muted">Off</span>' ?> <span class="muted">· <code>WANKARR_SIGNUP</code></span></dd></div>
      <div><dt>New accounts see</dt><dd><?= h($limitLabel(wk_validate_rating(wk_config()['auth']['signup_max_rating'] ?? null))) ?> <span class="muted">· <code>WANKARR_SIGNUP_MAX_RATING</code></span></dd></div>
      <div><dt>Email</dt><dd>
        <?php if (wk_email_ready()): ?>
          <span class="state ok">Ready</span>: sign-ups confirm their address; password reset by email works.
        <?php elseif (wk_mail_configured()): ?>
          <span class="state warn">SMTP set, but <code>WANKARR_BASE_URL</code> isn't</span>. Emailed links need it, so sign-ups wait for approval instead.
        <?php else: ?>
          <span class="state muted">Not configured</span>: sign-ups wait for an admin to approve them. Set <code>WANKARR_SMTP_*</code> and <code>WANKARR_BASE_URL</code> to enable email.
        <?php endif ?>
      </dd></div>
    </dl>
  </section>
</div>
<?php wk_page_end(['users']);
