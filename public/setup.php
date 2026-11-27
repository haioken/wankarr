<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

// First start: create the initial admin. Only reachable while no users exist.
if (wk_users_exist()) {
    wk_redirect('login.php');
}

wk_auth_start('Welcome');
?>
    <h1>Welcome to wankarr</h1>
    <p class="muted">This looks like a first start. Create the admin account: it manages the library, settings and other users.</p>
    <form class="form js-auth" data-action="setup">
      <label class="field"><span>Username</span><input class="input" name="username" autocomplete="username" required autofocus pattern="[A-Za-z0-9._\-]{3,32}" title="3–32 letters, digits, dots, dashes or underscores"></label>
      <label class="field"><span>Email <em class="muted">optional, for password resets</em></span><input class="input" type="email" name="email" autocomplete="email"></label>
      <label class="field"><span>Password <em class="muted">at least 8 characters</em></span><input class="input" type="password" name="password" autocomplete="new-password" minlength="8" required></label>
      <label class="field"><span>Confirm password</span><input class="input" type="password" name="confirm" autocomplete="new-password" minlength="8" required></label>
      <p class="auth-error js-error" hidden></p>
      <button class="button primary auth-submit">Create admin account</button>
    </form>
<?php wk_auth_end();
