<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

/** Search-box suggestions: facets (genre, author, status, …) and series. */

wk_api(function () {
    $q = trim((string) ($_GET['q'] ?? ''));
    return ['query' => $q, 'items' => mb_strlen($q) >= 1 ? wk_search_suggest($q) : []];
});
