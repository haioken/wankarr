<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

$book = wk_book(wk_int($_GET['id'] ?? null) ?? 0);
$node = $book ? wk_book_node($book) : null;
if ($book === null) {
    wk_fail(404, 'Volume not found');
}
if ($node === null) {
    wk_fail(410, 'The file for this volume is missing. Run a library scan to update.');
}

try {
    $meta = wk_book_meta($node);
} catch (Throwable $e) {
    wk_fail(500, $e->getMessage());
}
if (!$meta['pages']) {
    wk_fail(422, 'This volume contains no readable images');
}

[$prev, $next] = wk_book_neighbours($book);
$link = fn(?array $b) => $b ? ['id' => (int) $b['id'], 'title' => $b['title']] : null;

wk_json([
    'id' => (int) $book['id'],
    'title' => $book['title'],
    'series' => ['id' => (int) $book['series_id'], 'title' => $book['series_title']],
    'format' => $book['format'],
    'version' => substr(sha1(wk_book_signature($node)), 0, 12),
    // [width, height] per page in reading order; 0 when undetectable.
    'pages' => array_map(fn($p) => [$p[1], $p[2]], $meta['pages']),
    'prev' => $link($prev),
    'next' => $link($next),
    'defaultDirection' => wk_config()['default_direction'] === 'ltr' ? 'ltr' : 'rtl',
]);
