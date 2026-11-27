<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

// Link from the signup email: confirms the address and activates the account.
$uid = wk_token_consume((string) ($_GET['token'] ?? ''), 'verify');
if ($uid !== null) {
    wk_q("UPDATE users SET status = 'active' WHERE id = ? AND status = 'unverified'", [$uid]);
    wk_log("auth: email confirmed for user #$uid");
    wk_redirect('login.php?verified=1');
}

wk_auth_start('Confirm email');
?>
    <h1>That link didn't work</h1>
    <p class="muted">It may have expired (links last 24 hours) or been used already. Try signing in, or sign up again.</p>
    <div class="auth-links"><a href="login.php">Sign in</a><?php if (wk_signup_enabled()): ?><a href="signup.php">Sign up</a><?php endif ?></div>
<?php wk_auth_end();
