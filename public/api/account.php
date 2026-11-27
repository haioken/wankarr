<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

/**
 * The signed-in user's own account.
 * POST {action: email, email, current}
 * POST {action: password, current, password}
 * POST {action: signout-others}
 */

$me = wk_require_user(true);

wk_api(function () use ($me) {
    $body = wk_require_post();
    $uid = (int) $me['id'];
    $token = (string) ($_COOKIE[WK_SESSION_COOKIE] ?? '');
    $checkCurrent = function () use ($body, $uid) {
        $hash = (string) wk_val('SELECT password_hash FROM users WHERE id = ?', [$uid]);
        if (!password_verify((string) ($body['current'] ?? ''), $hash)) {
            throw new InvalidArgumentException('Your current password is wrong');
        }
    };

    switch ($body['action'] ?? '') {
        case 'email':
            $checkCurrent();
            wk_user_update($uid, ['email' => (string) ($body['email'] ?? '')]);
            return ['ok' => true, 'message' => 'Email saved'];
        case 'password':
            $checkCurrent();
            wk_user_set_password($uid, (string) ($body['password'] ?? ''), $token);
            wk_log("auth: {$me['username']} changed their password");
            return ['ok' => true, 'message' => 'Password changed; other sessions were signed out'];
        case 'signout-others':
            wk_q('DELETE FROM sessions WHERE user_id = ? AND token_hash <> ?', [$uid, hash('sha256', $token)]);
            return ['ok' => true];
    }
    throw new InvalidArgumentException('Unknown action');
});
