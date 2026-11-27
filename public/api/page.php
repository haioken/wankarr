<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
$me = wk_require_user(true);

$book = wk_book(wk_int($_GET['id'] ?? null) ?? 0);
if ($book !== null && !wk_series_accessible((int) $book['series_id'])) {
    $book = null; // outside this user's content limit
}
$index = filter_var($_GET['p'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$node = $book ? wk_book_node($book) : null;
if ($node === null || $index === false) {
    wk_fail(404, 'Page not found');
}

wk_cache_headers(sha1(wk_book_signature($node) . '#' . $index));

try {
    $page = wk_book_page($node, $index);
} catch (Throwable $e) {
    wk_fail(500, $e->getMessage());
}
if ($page === null) {
    wk_fail(404, 'Page not found');
}
isset($page['path']) ? wk_send_file($page['path']) : wk_send_image($page['name'], $page['bytes']);
