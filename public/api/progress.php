<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

/**
 * Per-user reading state.
 * POST {action: progress, book, page, total}
 * POST {action: prefs, prefs: {direction, layout}, book?, cover_alone?}
 * POST {action: import, items: [{book, page, total, at}], prefs}   (from older browser storage)
 */

$me = wk_require_user(true);

wk_api(function () use ($me) {
    $body = wk_require_post();
    $uid = (int) $me['id'];
    $book = fn($id) => ($b = wk_book((int) $id)) && wk_series_accessible((int) $b['series_id']) ? $b : null;

    switch ($body['action'] ?? '') {
        case 'progress':
            $b = $book($body['book'] ?? 0) ?? throw new InvalidArgumentException('Volume not found');
            wk_progress_save($uid, (int) $b['id'], (int) ($body['page'] ?? 0), (int) ($body['total'] ?? 0));
            return ['ok' => true];

        case 'prefs':
            $prefs = wk_user_save_prefs($uid, (array) ($body['prefs'] ?? []));
            if (isset($body['book'], $body['cover_alone']) && ($b = $book($body['book']))) {
                $p = wk_progress_for_books($uid, [(int) $b['id']])[(int) $b['id']] ?? null;
                wk_progress_save($uid, (int) $b['id'], (int) ($p['page'] ?? 0), (int) ($p['total'] ?? 0), (bool) $body['cover_alone'], $p ? (int) $p['updated_at'] : null);
            }
            return ['ok' => true, 'prefs' => $prefs];

        case 'import':
            $n = 0;
            foreach (array_slice((array) ($body['items'] ?? []), 0, 5000) as $item) {
                $b = $book($item['book'] ?? 0);
                $have = $b ? (wk_progress_for_books($uid, [(int) $b['id']])[(int) $b['id']] ?? null) : null;
                $at = isset($item['at']) ? min(time(), (int) $item['at']) : time();
                if ($b && (!$have || (int) $have['updated_at'] < $at)) {
                    wk_progress_save($uid, (int) $b['id'], (int) ($item['page'] ?? 0), (int) ($item['total'] ?? 0), null, $at);
                    $n++;
                }
            }
            if (is_array($body['prefs'] ?? null) && !$me['prefs']) {
                wk_user_save_prefs($uid, $body['prefs']);
            }
            return ['ok' => true, 'imported' => $n];
    }
    throw new InvalidArgumentException('Unknown action');
});
