<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

/** Candidates from the metadata provider for a series, scored against it. */

wk_api(function () {
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($sid = wk_int($_GET['series'] ?? null)) {
        $series = wk_one(WK_SERIES_SELECT . ' WHERE s.id = ?', [$sid]) ?? throw new InvalidArgumentException('Series not found');
        $q = $q ?: $series['title'];
        $current = $series['metadata_id'] ? wk_val('SELECT provider_id FROM metadata WHERE id = ?', [$series['metadata_id']]) : null;
    } else {
        // Free search (import): score results against the query itself.
        if ($q === '') {
            throw new InvalidArgumentException('Enter something to search for');
        }
        $series = ['title' => $q];
        $current = null;
    }
    return [
        'query' => $q,
        'threshold' => (float) wk_config()['metadata']['auto_match'],
        'results' => array_map(fn($c) => $c + [
            'current' => $c['id'] === $current,
            'preferred_title' => wk_preferred_title(['title' => $c['title'], 'title_english' => $c['title_english']]),
        ], wk_match_candidates($series, $q)),
    ];
});
