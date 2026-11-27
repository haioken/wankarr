<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$token = (string) ($_GET['token'] ?? '');
$valid = $token !== '' && wk_token_consume($token, 'reset', false) !== null;

wk_auth_start('Reset password');
if ($token !== '' && $valid): ?>
    <h1>Choose a new password</h1>
    <form class="form js-auth" data-action="reset">
      <input type="hidden" name="token" value="<?= h($token) ?>">
      <label class="field"><span>New password <em class="muted">at least 8 characters</em></span><input class="input" type="password" name="password" autocomplete="new-password" minlength="8" required autofocus></label>
      <label class="field"><span>Confirm password</span><input class="input" type="password" name="confirm" autocomplete="new-password" minlength="8" required></label>
      <p class="auth-error js-error" hidden></p>
      <button class="button primary auth-submit">Set password</button>
    </form>
<?php elseif ($token !== ''): ?>
    <h1>That link didn't work</h1>
    <p class="muted">Reset links last an hour and work once.</p>
    <div class="auth-links"><a href="reset.php">Send a new link</a><a href="login.php">Sign in</a></div>
<?php elseif (!wk_email_ready()): ?>
    <h1>Forgot your password?</h1>
    <p class="muted">Password reset by email isn't set up here. Ask an admin to reset it for you.</p>
    <div class="auth-links"><a href="login.php">Back to sign in</a></div>
<?php else: ?>
    <h1>Forgot your password?</h1>
    <p class="muted">Enter your account's email address and we'll send you a reset link.</p>
    <form class="form js-auth" data-action="forgot">
      <label class="field"><span>Email</span><input class="input" type="email" name="email" autocomplete="email" required autofocus></label>
      <p class="auth-error js-error" hidden></p>
      <button class="button primary auth-submit">Send reset link</button>
    </form>
    <div class="auth-links"><a href="login.php">Back to sign in</a></div>
<?php endif;
wk_auth_end();
