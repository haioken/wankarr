<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
wk_require_admin(true);

/** POST {action: 'move', id, series_id | new_title} — regroup a book. */

wk_api(function () {
    $body = wk_require_post();
    if (($body['action'] ?? '') !== 'move') {
        throw new InvalidArgumentException('Unknown action');
    }
    $target = wk_book_move(
        wk_int($body['id'] ?? null) ?? 0,
        wk_int($body['series_id'] ?? null),
        isset($body['new_title']) ? (string) $body['new_title'] : null,
    );
    return ['ok' => true, 'series' => $target];
});
