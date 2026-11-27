<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

/**
 * Sign-in and account recovery. No session required.
 * POST {action: setup,  username, password, email?}   first admin (only while no users exist)
 * POST {action: login,  username, password, remember, next}
 * POST {action: logout}
 * POST {action: signup, username, email, password}   when sign-ups are enabled
 * POST {action: forgot, email}                        emails a reset link
 * POST {action: reset,  token, password}
 */

wk_api(function () {
    $body = wk_require_post();
    $str = fn(string $k) => (string) ($body[$k] ?? '');

    switch ($body['action'] ?? '') {
        case 'setup':
            // Serialise so two browsers racing the setup page can't both win.
            $lock = fopen(wk_data_path('locks/setup.lock'), 'c');
            flock($lock, LOCK_EX);
            try {
                if (wk_val('SELECT 1 FROM users LIMIT 1')) {
                    throw new InvalidArgumentException('Setup is already complete. Sign in instead.');
                }
                $id = wk_user_create($str('username'), $str('password'), 'admin', $str('email') ?: null);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            wk_session_start($id, true);
            return ['ok' => true, 'next' => './'];

        case 'login':
            try {
                wk_login($str('username'), $str('password'), !empty($body['remember']));
            } catch (RuntimeException $e) {
                wk_fail(401, $e->getMessage());
            }
            return ['ok' => true, 'next' => wk_safe_next($str('next'))];

        case 'logout':
            wk_logout();
            return ['ok' => true, 'next' => 'login.php'];

        case 'signup':
            try {
                return ['ok' => true, 'message' => wk_signup($str('username'), $str('email'), $str('password'))];
            } catch (RuntimeException $e) {
                wk_fail(503, $e->getMessage());
            }

        case 'forgot':
            try {
                wk_password_reset_request($str('email'));
            } catch (RuntimeException $e) {
                wk_fail(503, $e->getMessage());
            }
            return ['ok' => true, 'message' => 'If that address belongs to an account, a reset link is on its way.'];

        case 'reset':
            wk_validate_password($str('password'));
            $uid = wk_token_consume($str('token'), 'reset') ?? throw new InvalidArgumentException('This reset link is invalid or has expired. Ask for a new one.');
            wk_user_set_password($uid, $str('password'));
            // Proving control of the address also confirms it.
            wk_q("UPDATE users SET status = 'active' WHERE id = ? AND status = 'unverified'", [$uid]);
            wk_log("auth: password reset for user #$uid");
            return ['ok' => true, 'next' => 'login.php?reset=1'];
    }
    throw new InvalidArgumentException('Unknown action');
});
