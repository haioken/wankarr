<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

/**
 * User management (admins only).
 * POST {action: create, username, password, email?, role, max_rating?, show_unrated}
 * POST {action: update, id, role?, status?, email?, max_rating?, show_unrated?, password?}
 * POST {action: delete, id}
 */

$me = wk_require_admin(true);

wk_api(function () use ($me) {
    $body = wk_require_post();
    $id = (int) ($body['id'] ?? 0);
    switch ($body['action'] ?? '') {
        case 'create':
            $id = wk_user_create(
                (string) ($body['username'] ?? ''), (string) ($body['password'] ?? ''), (string) ($body['role'] ?? 'user'),
                (string) ($body['email'] ?? '') ?: null, 'active', (string) ($body['max_rating'] ?? '') ?: null, !empty($body['show_unrated']),
            );
            return ['ok' => true, 'id' => $id];
        case 'update':
            $fields = array_intersect_key($body, array_flip(['role', 'status', 'email', 'max_rating', 'show_unrated']));
            if ($id === (int) $me['id'] && (($fields['role'] ?? 'admin') !== 'admin' || ($fields['status'] ?? 'active') !== 'active')) {
                throw new InvalidArgumentException('You can\'t demote or disable yourself. Ask another admin.');
            }
            wk_user_update($id, $fields);
            if (($body['password'] ?? '') !== '') {
                wk_user_set_password($id, (string) $body['password'], $id === (int) $me['id'] ? (string) ($_COOKIE[WK_SESSION_COOKIE] ?? '') : null);
            }
            wk_log("auth: {$me['username']} updated user #$id");
            return ['ok' => true];
        case 'delete':
            if ($id === (int) $me['id']) {
                throw new InvalidArgumentException('You can\'t delete your own account here');
            }
            wk_user_delete($id);
            return ['ok' => true];
    }
    throw new InvalidArgumentException('Unknown action');
});
