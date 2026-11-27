<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

/**
 * GET ?q=      series picker (merge / move targets)
 * POST {action, id, ...}
 *   match     {provider, provider_id}  attach chosen metadata
 *   automatch                         re-run automatic matching
 *   refresh                           re-fetch attached metadata
 *   unmatch                           detach metadata, stop auto-matching
 *   rename    {title, sort_title?}
 *   merge     {into}                  move all volumes into another series
 * POST {action, ids: [..]}             bulk actions on a selection
 *   hide | unhide                      (any user; affects only their own view)
 *   rescan | delete-preview | delete   (admin; delete removes files from disk)
 */

$me = wk_require_user(true);

wk_api(function () use ($me) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        wk_require_admin(true);
        $rows = wk_series_list(['q' => (string) ($_GET['q'] ?? ''), 'limit' => 25, 'library' => wk_int($_GET['library'] ?? null)]);
        return array_map(fn($r) => [
            'id' => (int) $r['id'], 'title' => $r['title'], 'year' => $r['year'], 'volumes' => (int) $r['book_count'],
        ], $rows);
    }

    $body = wk_require_post();

    // Bulk actions over a selection.
    $ids = array_values(array_filter(array_map('intval', (array) ($body['ids'] ?? []))));
    $ids = array_values(array_filter($ids, 'wk_series_accessible'));
    switch ($body['action'] ?? '') {
        case 'hide':
            return ['ok' => true, 'hidden' => wk_hide_series((int) $me['id'], $ids, true)];
        case 'unhide':
            return ['ok' => true, 'unhidden' => wk_hide_series((int) $me['id'], $ids, false)];
    }

    wk_require_admin(true);
    switch ($body['action'] ?? '') {
        case 'delete-preview':
            return array_map(fn($e) => array_diff_key($e, ['root' => 1]), wk_delete_plan($ids));
        case 'delete':
            return wk_series_delete($ids, true);
        case 'rescan':
            return wk_rescan_series($ids);
    }

    $id = wk_int($body['id'] ?? null) ?? 0;
    $series = wk_one('SELECT * FROM series WHERE id = ?', [$id]) ?? throw new InvalidArgumentException('Series not found');

    switch ($body['action'] ?? '') {
        case 'match':
            wk_apply_match($id, (string) ($body['provider'] ?? 'tenrai'), (string) ($body['provider_id'] ?? ''), isset($body['score']) ? (float) $body['score'] : null, 'manual');
            break;
        case 'automatch':
            return ['status' => wk_auto_match($id)];
        case 'refresh':
            $meta = $series['metadata_id'] ? wk_one('SELECT provider, provider_id FROM metadata WHERE id = ?', [$series['metadata_id']]) : null;
            if (!$meta) {
                return ['status' => wk_auto_match($id)];
            }
            wk_apply_match($id, $meta['provider'], $meta['provider_id'], $series['match_score'] !== null ? (float) $series['match_score'] : null, $series['match_status']);
            break;
        case 'unmatch':
            wk_unmatch($id);
            break;
        case 'rename':
            wk_series_rename($id, (string) ($body['title'] ?? ''), isset($body['sort_title']) ? (string) $body['sort_title'] : null);
            break;
        case 'merge':
            $into = wk_int($body['into'] ?? null) ?? 0;
            wk_series_merge($id, $into);
            return ['ok' => true, 'redirect' => "series.php?id=$into"];
        default:
            throw new InvalidArgumentException('Unknown action');
    }
    return ['ok' => true];
});
