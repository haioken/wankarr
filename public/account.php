<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$me = wk_require_user();

$sessions = wk_all('SELECT token_hash, created_at, last_seen_at, user_agent, ip FROM sessions WHERE user_id = ? AND expires_at > ? ORDER BY last_seen_at DESC', [$me['id'], time()]);
$limit = $me['max_rating'] ? WK_CONTENT_RATINGS[$me['max_rating']] : null;

wk_page_start('Account', ['system', 'settings'], 'account');
?>
<div class="content-body system">
  <section class="card">
    <h2>Account</h2>
    <dl class="info-list">
      <div><dt>Username</dt><dd><?= h($me['username']) ?></dd></div>
      <div><dt>Role</dt><dd><?= h(WK_ROLES[$me['role']] ?? $me['role']) ?></dd></div>
      <div><dt>Content shown</dt><dd><?= $limit ? 'Up to ' . h($limit) . ($me['show_unrated'] ? ', plus unrated series' : '') : 'Everything' ?> <span class="muted">(set by an admin)</span></dd></div>
      <div><dt>Hidden series</dt><dd><?= (int) wk_val('SELECT COUNT(*) FROM user_hidden WHERE user_id = ?', [$me['id']]) ?> · <a href="./?hidden=1">manage</a></dd></div>
    </dl>
  </section>

  <section class="card">
    <h2>Email</h2>
    <form class="form narrow-form js-account" data-action="email">
      <label class="field"><span>Email address</span><input class="input" type="email" name="email" value="<?= h($me['email'] ?? '') ?>" autocomplete="email"></label>
      <label class="field"><span>Current password</span><input class="input" type="password" name="current" autocomplete="current-password" required></label>
      <p class="form-message js-message" hidden></p>
      <button class="button primary">Save email</button>
    </form>
  </section>

  <section class="card">
    <h2>Password</h2>
    <form class="form narrow-form js-account" data-action="password">
      <label class="field"><span>Current password</span><input class="input" type="password" name="current" autocomplete="current-password" required></label>
      <label class="field"><span>New password <em class="muted">at least 8 characters</em></span><input class="input" type="password" name="password" autocomplete="new-password" minlength="8" required></label>
      <label class="field"><span>Confirm new password</span><input class="input" type="password" name="confirm" autocomplete="new-password" minlength="8" required></label>
      <p class="form-message js-message" hidden></p>
      <button class="button primary">Change password</button>
    </form>
    <p class="muted help">Changing your password signs out your other sessions.</p>
  </section>

  <section class="card">
    <h2>Sessions</h2>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Device</th><th>Address</th><th>Signed in</th><th>Last active</th></tr></thead>
        <tbody>
          <?php $current = hash('sha256', (string) ($_COOKIE[WK_SESSION_COOKIE] ?? ''));
          foreach ($sessions as $s): ?>
            <tr>
              <td><?= h(wk_user_agent_label((string) $s['user_agent'])) ?><?= hash_equals($s['token_hash'], $current) ? ' <span class="chip chip-ok">this one</span>' : '' ?></td>
              <td><?= h((string) $s['ip']) ?></td>
              <td><?= h(wk_ago((int) $s['created_at'])) ?></td>
              <td><?= h(wk_ago((int) $s['last_seen_at'])) ?></td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <?php if (count($sessions) > 1): ?>
      <button type="button" class="button js-signout-others">Sign out everywhere else</button>
    <?php endif ?>
  </section>
</div>
<?php wk_page_end(['account']);
