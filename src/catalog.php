<?php
declare(strict_types=1);

/**
 * Catalog queries and edits: series, books, search, grouping.
 */

const WK_CONTENT_RATINGS = [
    'safe' => 'Safe',
    'suggestive' => 'Suggestive',
    'erotica' => 'Erotica',
    'pornographic' => 'Adult',
];

const WK_SERIES_SORTS = [
    'title' => ['Title', 's.sort_title ASC'],
    'year' => ['Year', 'COALESCE(m.year, s.year) IS NULL, COALESCE(m.year, s.year) DESC, s.sort_title'],
    'score' => ['Rating', 'm.score IS NULL, m.score DESC, s.sort_title'],
    'added' => ['Recently added', 'last_added DESC, s.sort_title'],
];

const WK_SERIES_SELECT = "
    SELECT s.id, s.library_id, s.title, s.sort_title, s.year AS local_year, s.folder, s.match_status, s.match_score,
           s.metadata_id, s.added_at, s.title_locked, s.hidden,
           m.title AS meta_title, m.year AS meta_year, m.score, m.content_rating, m.status AS pub_status, m.type AS meta_type,
           COALESCE(m.year, s.year) AS year,
           (SELECT COUNT(*) FROM books b WHERE b.series_id = s.id) AS book_count,
           (SELECT MAX(b.added_at) FROM books b WHERE b.series_id = s.id) AS last_added
    FROM series s LEFT JOIN metadata m ON m.id = s.metadata_id";

/** ESCAPE clause making backslash the LIKE escape character, quoted per driver. */
function wk_like_escape(): string
{
    return wk_db_driver() === 'sqlite' ? "ESCAPE '\\'" : "ESCAPE '\\\\'";
}

function wk_like(string $s): string
{
    return '%' . addcslashes($s, '\\%_') . '%';
}

/**
 * Series for listings. $opts: sort, match ('matched'|'unmatched'|'pending'),
 * rating, status, genre (name), person (id), library (id), q (text), limit.
 */
function wk_series_list(array $opts = []): array
{
    [$where, $params] = wk_series_filters($opts);
    $order = WK_SERIES_SORTS[$opts['sort'] ?? 'title'][1] ?? WK_SERIES_SORTS['title'][1];
    $limit = (int) ($opts['limit'] ?? 5000);
    $sql = WK_SERIES_SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY $order LIMIT $limit";
    return wk_all($sql, $params);
}

function wk_series_filters(array $o): array
{
    // Only series from currently configured libraries are listed.
    $active = array_keys(wk_libraries());
    $where = ['s.library_id IN (' . wk_in($active) . ')'];
    $params = $active ?: [0];
    $list = fn($v) => array_values(array_filter(array_map(fn($x) => is_string($x) ? trim($x) : $x, (array) $v), fn($x) => $x !== null && $x !== ''));

    $where[] = !empty($o['hidden']) ? 's.hidden = 1' : 's.hidden = 0';

    // Free text: every word of every text term must appear somewhere.
    foreach ($list($o['q'] ?? []) as $text) {
        foreach (array_slice(explode(' ', wk_norm((string) $text)), 0, 8) as $tok) {
            if ($tok !== '') {
                $where[] = 's.search_text LIKE ? ' . wk_like_escape();
                $params[] = wk_like($tok);
            }
        }
    }
    if (!empty($o['match'])) {
        if ($o['match'] === 'matched') {
            $where[] = "s.match_status IN ('matched', 'manual')";
        } else {
            $where[] = 's.match_status = ?';
            $params[] = $o['match'];
        }
    }
    // Single-valued fields: any of the chosen values.
    foreach (['rating' => 'm.content_rating', 'status' => 'm.status', 'type' => 'm.type'] as $key => $col) {
        if ($values = $list($o[$key] ?? [])) {
            $where[] = "$col IN (" . wk_in($values) . ')';
            array_push($params, ...$values);
        }
    }
    if ($years = array_map('intval', $list($o['year'] ?? []))) {
        $where[] = 'COALESCE(m.year, s.year) IN (' . wk_in($years) . ')';
        array_push($params, ...$years);
    }
    if (!empty($o['library'])) {
        $where[] = 's.library_id = ?';
        $params[] = (int) $o['library'];
    }
    // Multi-valued fields: every chosen value must apply.
    foreach ($list($o['genre'] ?? []) as $genre) {
        $where[] = 'EXISTS (SELECT 1 FROM metadata_genres mg JOIN genres g ON g.id = mg.genre_id WHERE mg.metadata_id = s.metadata_id AND g.name = ?)';
        $params[] = $genre;
    }
    foreach ($list($o['person'] ?? []) as $person) {
        $where[] = 'EXISTS (SELECT 1 FROM metadata_people mp WHERE mp.metadata_id = s.metadata_id AND mp.person_id = ?)';
        $params[] = (int) $person;
    }
    if (!empty($o['ids'])) {
        $ids = array_map('intval', (array) $o['ids']);
        $where[] = 's.id IN (' . wk_in($ids) . ')';
        array_push($params, ...$ids);
    }
    return [$where, $params];
}

/** Book ids per series, for client-side reading-progress bars. */
function wk_series_book_ids(array $seriesIds): array
{
    $map = [];
    foreach (array_chunk($seriesIds, 500) as $ids) {
        foreach (wk_all('SELECT id, series_id FROM books WHERE series_id IN (' . wk_in($ids) . ')', $ids) as $r) {
            $map[(int) $r['series_id']][] = (int) $r['id'];
        }
    }
    return $map;
}

/** One series with its metadata, people and genres. */
function wk_series(int $id): ?array
{
    $s = wk_one(WK_SERIES_SELECT . ' WHERE s.id = ?', [$id]);
    if ($s === null) {
        return null;
    }
    $s['meta'] = $s['metadata_id'] ? wk_one('SELECT * FROM metadata WHERE id = ?', [$s['metadata_id']]) : null;
    $s['people'] = $s['genres'] = [];
    if ($s['meta']) {
        $s['meta']['titles'] = json_decode((string) $s['meta']['titles'], true) ?: [];
        $s['meta']['links'] = json_decode((string) $s['meta']['links'], true) ?: [];
        $s['meta']['serializations'] = json_decode((string) $s['meta']['serializations'], true) ?: [];
        $s['people'] = wk_all(
            'SELECT p.id, p.name, p.url, mp.role FROM metadata_people mp JOIN people p ON p.id = mp.person_id WHERE mp.metadata_id = ? ORDER BY mp.position',
            [$s['metadata_id']],
        );
        $s['genres'] = wk_all(
            "SELECT g.id, g.name, g.kind FROM metadata_genres mg JOIN genres g ON g.id = mg.genre_id WHERE mg.metadata_id = ?
             ORDER BY CASE g.kind WHEN 'demographic' THEN 0 WHEN 'genre' THEN 1 WHEN 'explicit' THEN 2 ELSE 3 END, g.name",
            [$s['metadata_id']],
        );
    }
    $s['library'] = wk_library((int) $s['library_id']);
    return $s;
}

/** Books in reading order: volume, then chapter, then natural name order. */
function wk_series_books(int $seriesId): array
{
    $books = wk_all('SELECT * FROM books WHERE series_id = ?', [$seriesId]);
    usort($books, function ($a, $b) {
        foreach (['volume', 'chapter'] as $k) {
            $x = $a[$k];
            $y = $b[$k];
            if ($x === null && $y === null) {
                continue;
            }
            if ($x === null || $y === null) {
                return $x === null ? 1 : -1; // numbered books first
            }
            if ((float) $x !== (float) $y) {
                return (float) $x <=> (float) $y;
            }
        }
        return strnatcasecmp($a['rel_path'], $b['rel_path']);
    });
    return $books;
}

function wk_book(int $id): ?array
{
    return wk_one('SELECT b.*, s.title AS series_title FROM books b JOIN series s ON s.id = b.series_id WHERE b.id = ?', [$id]);
}

/** [previous, next] books in the same series. */
function wk_book_neighbours(array $book): array
{
    $books = wk_series_books((int) $book['series_id']);
    foreach ($books as $i => $b) {
        if ((int) $b['id'] === (int) $book['id']) {
            return [$books[$i - 1] ?? null, $books[$i + 1] ?? null];
        }
    }
    return [null, null];
}

/** Rebuild the denormalised search text for a series. */
function wk_series_reindex(int $id): void
{
    $s = wk_one('SELECT s.title, s.folder, s.year, s.metadata_id FROM series s WHERE s.id = ?', [$id]);
    if ($s === null) {
        return;
    }
    $parts = [$s['title'], $s['folder'], $s['year']];
    if ($s['metadata_id']) {
        $m = wk_one('SELECT title, title_english, title_japanese, titles, year FROM metadata WHERE id = ?', [$s['metadata_id']]);
        array_push($parts, $m['title'], $m['title_english'], $m['title_japanese'], $m['year'], ...(json_decode((string) $m['titles'], true) ?: []));
        foreach (wk_col('SELECT p.name FROM metadata_people mp JOIN people p ON p.id = mp.person_id WHERE mp.metadata_id = ?', [$s['metadata_id']]) as $name) {
            $parts[] = wk_person_name($name);
            $parts[] = $name;
        }
        array_push($parts, ...wk_col('SELECT g.name FROM metadata_genres mg JOIN genres g ON g.id = mg.genre_id WHERE mg.metadata_id = ?', [$s['metadata_id']]));
    }
    $text = implode(' | ', array_unique(array_filter(array_map(fn($p) => wk_norm((string) $p), $parts))));
    wk_update('series', ['search_text' => $text], ['id' => $id]);
}

/** MAL stores "Family, Given"; show "Given Family". */
function wk_person_name(string $name): string
{
    $parts = array_map('trim', explode(',', $name, 2));
    return count($parts) === 2 && $parts[1] !== '' ? $parts[1] . ' ' . $parts[0] : $name;
}

// ---------------------------------------------------------------------------
// Search

/**
 * Library search, ranked. $texts are free-text terms (all must match);
 * $filters are facet filters (see wk_series_filters). Falls back to fuzzy
 * title matching when the text finds nothing (typos, partial romanisations).
 * Returns ['series' => rows (with 'rank'), 'books' => rows, 'fuzzy' => bool].
 */
function wk_search(array|string $texts, array $filters = [], int $limit = 100): array
{
    $texts = array_values(array_filter(array_map('trim', (array) $texts), 'strlen'));
    $q = implode(' ', $texts);
    $rows = wk_series_list($filters + ['q' => $texts, 'limit' => 1000]);
    $fuzzy = false;

    if ($q !== '' && !$rows) {
        $fuzzy = true;
        $candidates = wk_series_list($filters + ['limit' => 5000]);
        $titles = wk_series_alt_titles(array_column($candidates, 'metadata_id'));
        foreach ($candidates as $c) {
            $c['rank'] = wk_search_rank($q, $c, $titles[(int) $c['metadata_id']] ?? []);
            if ($c['rank'] >= 0.6) {
                $rows[] = $c;
            }
        }
    } elseif ($q !== '') {
        $titles = wk_series_alt_titles(array_column($rows, 'metadata_id'));
        foreach ($rows as &$r) {
            $r['rank'] = wk_search_rank($q, $r, $titles[(int) $r['metadata_id']] ?? []);
        }
        unset($r);
    }
    if ($q !== '') {
        usort($rows, fn($a, $b) => [$b['rank'], $a['sort_title']] <=> [$a['rank'], $b['sort_title']]);
    }

    $books = [];
    if ($q !== '' && !array_filter($filters)) {
        $esc = wk_like_escape();
        $active = array_keys(wk_libraries()) ?: [0];
        $books = wk_all(
            "SELECT b.id, b.title, b.series_id, b.volume, b.chapter, b.page_count, s.title AS series_title
             FROM books b JOIN series s ON s.id = b.series_id
             WHERE s.hidden = 0 AND s.library_id IN (" . wk_in($active) . ") AND (b.rel_path LIKE ? $esc OR b.title LIKE ? $esc)
             ORDER BY s.sort_title LIMIT 50",
            [...$active, wk_like($q), wk_like($q)],
        );
    }
    return ['series' => array_slice($rows, 0, $limit), 'books' => $books, 'fuzzy' => $fuzzy, 'total' => count($rows)];
}

/**
 * Facet suggestions for the search box: genres, people, status, rating,
 * type, year and series whose names fuzzily match what's typed.
 */
function wk_search_suggest(string $q, int $limit = 14): array
{
    $nq = wk_norm($q);
    if ($nq === '') {
        return [];
    }
    $active = array_keys(wk_libraries()) ?: [0];
    $in = wk_in($active);
    $visible = "s.hidden = 0 AND s.library_id IN ($in)";
    $score = function (string $label) use ($nq): float {
        $nl = wk_norm($label);
        if ($nl === '') {
            return 0;
        }
        if (str_starts_with($nl, $nq)) {
            return 1.0;
        }
        if (preg_match('/(^| )' . preg_quote($nq, '/') . '/u', $nl)) {
            return 0.95;
        }
        if (str_contains($nl, $nq)) {
            return 0.85;
        }
        return mb_strlen($nq) >= 3 ? wk_search_similarity($nq, $nl) : 0;
    };
    $out = [];
    $add = function (string $kind, string $group, string|int $value, string $label, int $count, float $s, ?string $hint = null) use (&$out) {
        if ($s >= 0.7) { // typo-tolerant, but not "miura" → "Murata"
            $out[] = compact('kind', 'group', 'value', 'label', 'count', 'hint') + ['score' => $s];
        }
    };

    $groups = ['genre' => 'Genre', 'explicit' => 'Genre', 'theme' => 'Theme', 'demographic' => 'Demographic'];
    foreach (wk_all("SELECT g.name, g.kind, COUNT(DISTINCT s.id) AS n FROM genres g JOIN metadata_genres mg ON mg.genre_id = g.id
                     JOIN series s ON s.metadata_id = mg.metadata_id WHERE $visible GROUP BY g.name, g.kind", $active) as $g) {
        $add('genre', $groups[$g['kind']] ?? 'Genre', $g['name'], $g['name'], (int) $g['n'], $score($g['name']));
    }
    foreach (wk_all("SELECT p.id, p.name, COUNT(DISTINCT s.id) AS n FROM people p JOIN metadata_people mp ON mp.person_id = p.id
                     JOIN series s ON s.metadata_id = mp.metadata_id WHERE $visible GROUP BY p.id, p.name", $active) as $p) {
        $display = wk_person_name($p['name']);
        $add('person', 'Author', (int) $p['id'], $display, (int) $p['n'], max($score($display), $score($p['name'])));
    }
    foreach (['status' => 'Status', 'type' => 'Type'] as $col => $group) {
        foreach (wk_all("SELECT m.$col AS v, COUNT(*) AS n FROM series s JOIN metadata m ON m.id = s.metadata_id
                         WHERE $visible AND m.$col IS NOT NULL GROUP BY m.$col", $active) as $r) {
            $add($col, $group, $r['v'], $r['v'], (int) $r['n'], $score($r['v']));
        }
    }
    $ratings = array_column(wk_all("SELECT m.content_rating AS v, COUNT(*) AS n FROM series s JOIN metadata m ON m.id = s.metadata_id
                                    WHERE $visible AND m.content_rating IS NOT NULL GROUP BY m.content_rating", $active), 'n', 'v');
    foreach (WK_CONTENT_RATINGS as $key => $label) {
        if (isset($ratings[$key])) {
            $add('rating', 'Rating', $key, $label, (int) $ratings[$key], max($score($label), $score($key)));
        }
    }
    if (preg_match('/^(19|20)\d{2}$/', $nq)) {
        $n = (int) wk_val("SELECT COUNT(*) FROM series s LEFT JOIN metadata m ON m.id = s.metadata_id WHERE $visible AND COALESCE(m.year, s.year) = ?", [...$active, (int) $nq]);
        $add('year', 'Year', (int) $nq, $nq, $n, $n ? 1.0 : 0);
    }
    foreach (wk_search([$q], [], 6)['series'] as $s) {
        $add('series', 'Series', (int) $s['id'], $s['title'], (int) $s['book_count'], min(1.0, (float) ($s['rank'] ?? 0.7)), $s['year'] ? (string) $s['year'] : null);
    }

    $kindOrder = ['genre' => 0, 'person' => 1, 'series' => 2, 'status' => 3, 'type' => 4, 'rating' => 5, 'year' => 6];
    usort($out, fn($a, $b) => [$b['score'], $kindOrder[$a['kind']], $b['count']] <=> [$a['score'], $kindOrder[$b['kind']], $a['count']]);
    return array_map(fn($o) => array_diff_key($o, ['score' => 1]), array_slice($out, 0, $limit));
}

function wk_series_alt_titles(array $metadataIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $metadataIds))));
    $out = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        foreach (wk_all('SELECT id, title, title_english, titles FROM metadata WHERE id IN (' . wk_in($chunk) . ')', $chunk) as $m) {
            $out[(int) $m['id']] = array_filter([$m['title'], $m['title_english'], ...(json_decode((string) $m['titles'], true) ?: [])]);
        }
    }
    return $out;
}

/** Typo-tolerant similarity of a query against any run of words in a title. */
function wk_search_similarity(string $nq, string $nt): float
{
    $best = wk_similarity($nq, $nt);
    $words = explode(' ', $nt);
    $k = count(explode(' ', $nq));
    for ($size = max(1, $k - 1); $size <= $k + 1; $size++) {
        for ($i = 0; $i + $size <= count($words); $i++) {
            $window = implode(' ', array_slice($words, $i, $size));
            similar_text($nq, $window, $pct);
            // similar_text forgives transposed letters ("fireen" ~ "frieren").
            $best = max($best, wk_similarity($nq, $window) * 0.95, $pct / 100 * 0.9);
        }
    }
    return $best;
}

function wk_search_rank(string $q, array $series, array $altTitles): float
{
    $nq = wk_norm($q);
    $best = 0.0;
    foreach ([$series['title'], ...$altTitles] as $i => $t) {
        $nt = wk_norm((string) $t);
        $score = wk_search_similarity($nq, $nt);
        if ($nq !== '' && str_starts_with($nt, $nq)) {
            $score = max($score, 0.9);
        } elseif ($nq !== '' && str_contains($nt, $nq)) {
            $score = max($score, 0.8);
        }
        $best = max($best, $i === 0 ? $score : $score * 0.97); // prefer the displayed title
    }
    // Matches only via author/genre/other text still rank, just lower.
    return max($best, 0.4);
}

// ---------------------------------------------------------------------------
// Editing / grouping

function wk_series_rename(int $id, string $title, ?string $sortTitle = null): void
{
    $title = mb_substr(trim($title), 0, 255);
    if ($title === '') {
        throw new InvalidArgumentException('Title is required');
    }
    wk_update('series', [
        'title' => $title,
        'sort_title' => mb_substr($sortTitle !== null && trim($sortTitle) !== '' ? wk_norm($sortTitle) : wk_sort_title($title), 0, 255),
        'title_locked' => 1,
        'updated_at' => time(),
    ], ['id' => $id]);
    wk_series_reindex($id);
}

/** Merge $fromId into $intoId: books move, grouping keys become aliases. */
function wk_series_merge(int $fromId, int $intoId): void
{
    if ($fromId === $intoId) {
        throw new InvalidArgumentException('Cannot merge a series into itself');
    }
    $from = wk_one('SELECT id, library_id FROM series WHERE id = ?', [$fromId]);
    $into = wk_one('SELECT id, library_id FROM series WHERE id = ?', [$intoId]);
    if (!$from || !$into) {
        throw new InvalidArgumentException('Series not found');
    }
    if ($from['library_id'] !== $into['library_id']) {
        throw new InvalidArgumentException('Series are in different libraries');
    }
    wk_tx(function () use ($fromId, $intoId) {
        wk_q('UPDATE books SET series_id = ? WHERE series_id = ?', [$intoId, $fromId]);
        wk_q('UPDATE series_keys SET series_id = ? WHERE series_id = ?', [$intoId, $fromId]);
        wk_delete('series', ['id' => $fromId]);
        wk_q('UPDATE series SET updated_at = ? WHERE id = ?', [time(), $intoId]);
    });
    wk_series_reindex($intoId);
}

/** Move one book to another series, or split it into a new one. */
function wk_book_move(int $bookId, ?int $seriesId, ?string $newTitle = null): int
{
    $book = wk_one('SELECT id, series_id, library_id FROM books WHERE id = ?', [$bookId]);
    if (!$book) {
        throw new InvalidArgumentException('Volume not found');
    }
    return wk_tx(function () use ($book, $seriesId, $newTitle) {
        if ($seriesId === null) {
            $title = mb_substr(trim((string) $newTitle), 0, 255);
            if ($title === '') {
                throw new InvalidArgumentException('A title is required for the new series');
            }
            $now = time();
            $seriesId = wk_insert('series', [
                'library_id' => $book['library_id'], 'title' => $title, 'sort_title' => mb_substr(wk_sort_title($title), 0, 255),
                'title_locked' => 1, 'match_status' => 'pending', 'added_at' => $now, 'updated_at' => $now,
            ]);
            $key = wk_series_key((int) $book['library_id'], $title);
            wk_upsert('series_keys', ['key_hash' => sha1($key), 'series_id' => $seriesId, 'match_key' => mb_substr($key, 0, 191)], ['key_hash']);
        } else {
            $target = wk_one('SELECT library_id FROM series WHERE id = ?', [$seriesId]);
            if (!$target || $target['library_id'] !== $book['library_id']) {
                throw new InvalidArgumentException('Target series not found in this library');
            }
        }
        wk_update('books', ['series_id' => $seriesId], ['id' => $book['id']]);
        $left = (int) wk_val('SELECT COUNT(*) FROM books WHERE series_id = ?', [$book['series_id']]);
        if ($left === 0) {
            // Keep the emptied series' keys pointing at the destination.
            wk_q('UPDATE series_keys SET series_id = ? WHERE series_id = ?', [$seriesId, $book['series_id']]);
            wk_delete('series', ['id' => $book['series_id']]);
        } else {
            wk_series_reindex((int) $book['series_id']);
        }
        wk_series_reindex($seriesId);
        return $seriesId;
    });
}

/** Genres in use, with series counts, for filter menus. */
function wk_genres_in_use(): array
{
    return wk_all(
        'SELECT g.name, g.kind, COUNT(DISTINCT s.id) AS n FROM genres g
         JOIN metadata_genres mg ON mg.genre_id = g.id JOIN series s ON s.metadata_id = mg.metadata_id
         WHERE s.hidden = 0 GROUP BY g.name, g.kind ORDER BY g.name',
    );
}

function wk_library_stats(): array
{
    $ids = array_keys(wk_libraries()) ?: [0];
    $in = 'library_id IN (' . wk_in($ids) . ')';
    $s = fn(string $sql) => (int) wk_val($sql, $ids);
    return [
        'series' => $s("SELECT COUNT(*) FROM series WHERE $in AND hidden = 0"),
        'hidden' => $s("SELECT COUNT(*) FROM series WHERE $in AND hidden = 1"),
        'books' => $s("SELECT COUNT(*) FROM books WHERE $in"),
        'pages' => $s("SELECT COALESCE(SUM(page_count), 0) FROM books WHERE $in"),
        'bytes' => $s("SELECT COALESCE(SUM(file_size), 0) FROM books WHERE $in"),
        'matched' => $s("SELECT COUNT(*) FROM series WHERE $in AND match_status IN ('matched', 'manual')"),
        'unmatched' => $s("SELECT COUNT(*) FROM series WHERE $in AND match_status = 'unmatched'"),
        'pending' => $s("SELECT COUNT(*) FROM series WHERE $in AND match_status = 'pending'"),
    ];
}
