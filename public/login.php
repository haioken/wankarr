<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if (!wk_users_exist()) {
    wk_redirect('setup.php');
}
$next = wk_safe_next($_GET['next'] ?? null);
if (wk_auth_user()) {
    wk_redirect($next);
}
$notice = match (true) {
    isset($_GET['reset']) => 'Your password was changed. Sign in with the new one.',
    isset($_GET['verified']) => 'Email confirmed. You can sign in now.',
    default => null,
};

wk_auth_start('Sign in');
?>
    <h1>Sign in</h1>
    <?php if ($notice): ?><p class="auth-notice"><?= h($notice) ?></p><?php endif ?>
    <form class="form js-auth" data-action="login">
      <input type="hidden" name="next" value="<?= h($next) ?>">
      <label class="field"><span>Username or email</span><input class="input" name="username" autocomplete="username" required autofocus></label>
      <label class="field"><span>Password</span><input class="input" type="password" name="password" autocomplete="current-password" required></label>
      <label class="check-row"><input type="checkbox" name="remember" checked> <span>Keep me signed in</span></label>
      <p class="auth-error js-error" hidden></p>
      <button class="button primary auth-submit">Sign in</button>
    </form>
    <div class="auth-links">
      <?php if (wk_email_ready()): ?><a href="reset.php">Forgot your password?</a><?php endif ?>
      <?php if (wk_signup_enabled()): ?><a href="signup.php">Create an account</a><?php endif ?>
    </div>
<?php wk_auth_end();
