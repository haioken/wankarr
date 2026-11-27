<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if (!wk_users_exist()) {
    wk_redirect('setup.php');
}
if (wk_auth_user()) {
    wk_redirect('./');
}

wk_auth_start('Create an account');
if (!wk_signup_enabled()): ?>
    <h1>Sign-ups are closed</h1>
    <p class="muted">Ask an admin to create an account for you.</p>
    <div class="auth-links"><a href="login.php">Back to sign in</a></div>
<?php else: ?>
    <h1>Create an account</h1>
    <p class="muted"><?= wk_email_ready() ? 'We\'ll email you a link to confirm your address.' : 'An admin will approve your account before you can sign in.' ?></p>
    <form class="form js-auth" data-action="signup">
      <label class="field"><span>Username</span><input class="input" name="username" autocomplete="username" required autofocus pattern="[A-Za-z0-9._\-]{3,32}" title="3–32 letters, digits, dots, dashes or underscores"></label>
      <label class="field"><span>Email</span><input class="input" type="email" name="email" autocomplete="email" required></label>
      <label class="field"><span>Password <em class="muted">at least 8 characters</em></span><input class="input" type="password" name="password" autocomplete="new-password" minlength="8" required></label>
      <label class="field"><span>Confirm password</span><input class="input" type="password" name="confirm" autocomplete="new-password" minlength="8" required></label>
      <p class="auth-error js-error" hidden></p>
      <button class="button primary auth-submit">Create account</button>
    </form>
    <div class="auth-links"><a href="login.php">Already have an account? Sign in</a></div>
<?php endif;
wk_auth_end();
