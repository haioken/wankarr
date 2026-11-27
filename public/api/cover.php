<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
wk_require_user(true);

/**
 * Covers. ?series=ID → metadata poster, else its first book's first page.
 *         ?book=ID   → the book's first page.
 */

$book = null;
if ($sid = wk_int($_GET['series'] ?? null)) {
    $series = wk_series_accessible($sid) ? wk_one('SELECT id, metadata_id FROM series WHERE id = ?', [$sid]) : null;
    if ($series === null) {
        wk_fail(404, 'Not found');
    }
    if ($series['metadata_id'] && is_file($poster = wk_metadata_cover_path((int) $series['metadata_id']))) {
        wk_cache_headers(sha1($poster . filemtime($poster)), 86400);
        wk_send_file($poster);
    }
    $book = wk_series_books($sid)[0] ?? null;
} elseif ($bid = wk_int($_GET['book'] ?? null)) {
    $book = wk_one('SELECT * FROM books WHERE id = ?', [$bid]);
    if ($book && !wk_series_accessible((int) $book['series_id'])) {
        $book = null;
    }
}

$node = $book ? wk_book_node($book) : null;
if ($node === null) {
    wk_fail(404, 'No cover');
}

$sig = wk_book_signature($node);
wk_cache_headers(sha1('cover#' . $sig), 86400);

// Cover extraction from RAR/7z shells out, so keep the result on disk.
$cache = wk_data_path('covers/books/' . sha1($node['abs']) . '.ser');
if (is_file($cache)) {
    $hit = @unserialize((string) file_get_contents($cache), ['allowed_classes' => false]);
    if (is_array($hit) && $hit['sig'] === $sig) {
        wk_send_image($hit['name'], $hit['bytes']);
    }
}

try {
    $page = wk_book_first_page($node);
} catch (Throwable) {
    $page = null;
}
if ($page === null) {
    wk_fail(404, 'No cover');
}
if (isset($page['path'])) {
    wk_send_file($page['path']);
}
if (!in_array($node['format'], ['dir', 'zip'], true)) {
    file_put_contents($cache, serialize(['sig' => $sig, 'name' => $page['name'], 'bytes' => $page['bytes']]), LOCK_EX);
}
wk_send_image($page['name'], $page['bytes']);
